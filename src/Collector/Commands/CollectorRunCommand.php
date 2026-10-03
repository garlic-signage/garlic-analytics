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

namespace App\Collector\Commands;

use App\Collector\CollectorRunner;
use App\Collector\Exceptions\ArchiveException;
use App\Collector\Exceptions\RetryableIngestException;
use App\Collector\FileResult;
use App\Collector\FileStatus;
use App\Collector\LogType;
use App\Collector\RunLock;
use InvalidArgumentException;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Formatter\OutputFormatter;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\ConsoleOutputInterface;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Reads the files devices uploaded, sends them to the ingest API and moves them to processed/ or error/.
 *
 *   bin/console collector:run
 *   bin/console collector:run --dry-run
 *   bin/console collector:run --device=smil --type=playlog
 *   bin/console collector:run --file=playlog-dd871e62-c8b9-4334-a94f-da54c3c6131b.xml
 *
 * Exit code 1 if the run had to stop (API down, wrong key or URL, files can not be moved),
 * also if the settings are incomplete. Files that end up in error/ do not change the exit code.
 * If another run is active, the command ends with 0, so cron does not report it as an error.
 */
#[AsCommand(name: 'collector:run', description: 'Reads uploaded device files and hands them to the ingest API')]
class CollectorRunCommand extends Command
{
    public function __construct(
        private readonly CollectorRunner $runner,
        private readonly RunLock         $lock
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('device', null, InputOption::VALUE_REQUIRED, 'Only this device, e.g. smil')
            ->addOption('type', null, InputOption::VALUE_REQUIRED, 'Only this type: ' . implode(', ', array_column(LogType::cases(), 'value')))
            ->addOption('file', null, InputOption::VALUE_REQUIRED, 'Only the file with this name')
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'Read and count, send and move nothing');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $errorOutput = $output instanceof ConsoleOutputInterface ? $output->getErrorOutput() : $output;
        $dryRun      = $input->getOption('dry-run') === true;

        $type = $this->stringOption($input, 'type');
        if ($type !== null && LogType::tryFrom($type) === null)
        {
            $errorOutput->writeln('<error>Unknown type "' . OutputFormatter::escape($type) . '", use ' . implode(', ', array_column(LogType::cases(), 'value')) . '.</error>');
            return Command::FAILURE;
        }

        try
        {
            if (!$dryRun && !$this->lock->acquire())
            {
                $output->writeln('Another run is active, nothing to do.');
                return Command::SUCCESS;
            }

            $results = $this->runner->run(
                $this->stringOption($input, 'device'),
                $type === null ? null : LogType::from($type),
                $this->stringOption($input, 'file'),
                $dryRun
            );
        }
        catch (InvalidArgumentException|RetryableIngestException|ArchiveException $e)
        {
            $errorOutput->writeln('<error>' . OutputFormatter::escape($e->getMessage()) . '</error>');
            return Command::FAILURE;
        }
        finally
        {
            $this->lock->release();
        }

        foreach ($results as $result)
            $this->printResult($result, $output, $errorOutput);

        $this->printSummary($results, $output, $dryRun);

        $stopped = array_filter($results, static fn(FileResult $r): bool => $r->status === FileStatus::Retry);

        return $stopped === [] ? Command::SUCCESS : Command::FAILURE;
    }

    private function printResult(FileResult $result, OutputInterface $output, OutputInterface $errorOutput): void
    {
        $name    = OutputFormatter::escape($result->device . '/' . $result->fileName);
        $message = OutputFormatter::escape((string) $result->message);
        $detail  = '  player ' . OutputFormatter::escape($result->playerId ?? '-') . '  ' . $result->events . ' events in ' . $result->batches . ' batch(es)';

        match ($result->status)
        {
            FileStatus::Parsed   => $output->writeln('parsed   ' . $name . $detail),
            FileStatus::Sent     => $output->writeln('sent     ' . $name . $detail),
            FileStatus::SkippedUnknown, FileStatus::SkippedUnsupported => $output->writeln('skipped  ' . $name . '  (' . $message . ')'),
            FileStatus::Failed   => $errorOutput->writeln('<error>failed   ' . $name . '  ' . $message . '</error>'),
            FileStatus::Rejected => $errorOutput->writeln('<error>rejected ' . $name . '  moved to error/: ' . $message . '</error>'),
            FileStatus::Retry    => $errorOutput->writeln('<error>retry    ' . $name . '  stays in upload/, run stopped: ' . $message . '</error>'),
        };
    }

    /**
     * @param list<FileResult> $results
     */
    private function printSummary(array $results, OutputInterface $output, bool $dryRun): void
    {
        $count = static fn(FileStatus ...$statuses): int => count(array_filter($results, static fn(FileResult $r): bool => in_array($r->status, $statuses, true)));
        $events  = array_sum(array_map(static fn(FileResult $r): int => $r->events, $results));
        $batches = array_sum(array_map(static fn(FileResult $r): int => $r->batches, $results));
        $skipped = $count(FileStatus::SkippedUnknown, FileStatus::SkippedUnsupported);

        if ($dryRun)
        {
            $output->writeln(sprintf(
                'Files: %d parsed, %d skipped, %d failed. Events: %d in %d batch(es). Dry run, nothing was sent or moved.',
                $count(FileStatus::Parsed), $skipped, $count(FileStatus::Failed), $events, $batches
            ));
            return;
        }

        $output->writeln(sprintf(
            'Files: %d sent, %d rejected, %d skipped, %d kept for retry. Events sent: %d in %d batch(es).',
            $count(FileStatus::Sent), $count(FileStatus::Rejected), $skipped, $count(FileStatus::Retry), $events, $batches
        ));
    }

    private function stringOption(InputInterface $input, string $name): ?string
    {
        $value = $input->getOption($name);

        return is_string($value) && $value !== '' ? $value : null;
    }
}
