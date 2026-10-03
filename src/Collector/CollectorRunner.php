<?php
/*
  GarlicSignage: Open Source Digital Signage Stack

 Copyright (C) 2026 Nikolaos Sagiadinos <garlic@saghiadinos.de>
 This file is part of the GarlicSignage source code

 This program is free software: you can redistribute it and/or modify
 it under the terms of the GNU Affero General Public License, version 3,
 as published by the Free Software Foundation.

 This program is distributed in the hope that it will be useful,
 but WITHOUT ANY WARRANTY; without even the implied warranty of
 MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 GNU Affero General Public License for more details.

 You should have received a copy of the GNU Affero General Public License
 along with this program.  If not, see <http://www.gnu.org/licenses/>.
*/
declare(strict_types=1);

namespace App\Collector;

use App\Collector\Device\DeviceSource;
use App\Collector\Exceptions\ArchiveException;
use App\Collector\Exceptions\ParseException;
use App\Collector\Exceptions\RejectedIngestException;
use App\Collector\Exceptions\RetryableIngestException;
use App\Collector\Ingest\IngestClientInterface;
use InvalidArgumentException;

/**
 * Goes through the upload directories of the devices and hands the play logs and events to the ingest API.
 *
 * Per file: parse, send the blocks one after the other, then move the file.
 * - all blocks accepted: file to processed/
 * - broken file or the API refuses the data (see RejectedIngestException): file to error/ with a .error file
 * - API down or collector not set up right (see RetryableIngestException): file stays, the run stops,
 *   because the next files would fail the same way. The next run starts again with this file.
 *
 * Blocks are cut the same way every time, so blocks that were already accepted before a failure
 * are dropped by the API when the file is sent again.
 *
 * Files with an unknown name stay where they are.
 * With $dryRun nothing is sent or moved, the files are only read.
 */
readonly class CollectorRunner
{
    /**
     * @param array<string,DeviceSource> $devices by device name, e.g. "smil"
     */
    public function __construct(
        private array                $devices,
        private DirectoryScanner     $scanner,
        private BatchSplitter        $splitter,
        private IngestClientInterface $ingest,
        private FileArchive          $archive,
        private int                  $minAgeSeconds
    ) {}

    /**
     * @param string|null  $device   only this device
     * @param LogType|null $type     only files of this type
     * @param string|null  $fileName only this file (by name), also if it is very new
     * @return list<FileResult>
     * @throws InvalidArgumentException
     * @throws RetryableIngestException if the ingest client is not configured (before any file is touched)
     */
    public function run(?string $device = null, ?LogType $type = null, ?string $fileName = null, bool $dryRun = false): array
    {
        if ($device !== null && !isset($this->devices[$device]))
            throw new InvalidArgumentException('Unknown device "' . $device . '", known: ' . implode(', ', array_keys($this->devices)));
        if (!$dryRun)
            $this->ingest->ensureConfigured();

        $results = [];
        foreach ($this->devices as $name => $source)
        {
            if ($device !== null && $device !== $name)
                continue;

            $minAge = $fileName === null ? $this->minAgeSeconds : 0;
            foreach ($this->scanner->scan($source->uploadDir, $minAge) as $path)
            {
                $baseName = basename($path);
                if ($fileName !== null && $baseName !== $fileName)
                    continue;

                $fileType = $source->adapter->classify($baseName);
                if ($type !== null && $fileType !== $type)
                    continue;

                if ($fileType === null)
                    $result = new FileResult($name, $baseName, null, FileStatus::SkippedUnknown, message: 'unknown file name');
                else
                    $result = $dryRun ? $this->inspect($name, $source, $path, $fileType) : $this->process($name, $source, $path, $fileType);
                $results[] = $result;

                if ($result->status === FileStatus::Retry)
                    return $results;
            }
        }

        return $results;
    }

    private function inspect(string $device, DeviceSource $source, string $path, LogType $type): FileResult
    {
        $fileName = basename($path);
        try
        {
            $records = $source->adapter->parse($type, $path);
        }
        catch (ParseException $e)
        {
            return new FileResult($device, $fileName, $type, FileStatus::Failed, message: $e->getMessage());
        }

        return new FileResult(
            $device,
            $fileName,
            $type,
            FileStatus::Parsed,
            $records[0]->playerId ?? null,
            count($records),
            count($this->splitter->split($records))
        );
    }

    private function process(string $device, DeviceSource $source, string $path, LogType $type): FileResult
    {
        $fileName = basename($path);
        try
        {
            $records = $source->adapter->parse($type, $path);
        }
        catch (ParseException $e)
        {
            return $this->reject($device, $source, $path, $type, $e->getMessage());
        }

        $playerId = $records[0]->playerId ?? null;
        $blocks   = $this->splitter->split($records);
        $total    = count($blocks);
        foreach ($blocks as $index => $block)
        {
            $where = $total > 1 ? 'block ' . ($index + 1) . '/' . $total . ': ' : '';
            try
            {
                $this->ingest->send($type, $block);
            }
            catch (RejectedIngestException $e)
            {
                return $this->reject($device, $source, $path, $type, $where . $e->getMessage(), $playerId);
            }
            catch (RetryableIngestException $e)
            {
                return new FileResult($device, $fileName, $type, FileStatus::Retry, $playerId, message: $where . $e->getMessage());
            }
        }

        try
        {
            $this->archive->markProcessed($path, $source->processedDir());
        }
        catch (ArchiveException $e)
        {
            return new FileResult($device, $fileName, $type, FileStatus::Retry, $playerId, message: 'sent, but ' . $e->getMessage());
        }

        return new FileResult($device, $fileName, $type, FileStatus::Sent, $playerId, count($records), $total);
    }

    private function reject(string $device, DeviceSource $source, string $path, LogType $type, string $message, ?string $playerId = null): FileResult
    {
        $fileName = basename($path);
        try
        {
            $this->archive->markRejected($path, $source->errorDir(), $message);
        }
        catch (ArchiveException $e)
        {
            return new FileResult($device, $fileName, $type, FileStatus::Retry, $playerId, message: $message . ' (and ' . $e->getMessage() . ')');
        }

        return new FileResult($device, $fileName, $type, FileStatus::Rejected, $playerId, message: $message);
    }
}
