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

use App\Collector\Exceptions\ArchiveException;
use DateTimeImmutable;

/**
 * Moves finished files out of upload/ with rename() (atomic on the same volume).
 *
 * A file that is rejected gets a "<name>.error" next to it with the time and the reason.
 * If the name is already taken in the target directory, a time stamp is put before the extension,
 * nothing is overwritten.
 */
readonly class FileArchive
{
    /**
     * @throws ArchiveException
     */
    public function markProcessed(string $path, string $processedDir): void
    {
        $this->move($path, $processedDir);
    }

    /**
     * @throws ArchiveException
     */
    public function markRejected(string $path, string $errorDir, string $message, ?DateTimeImmutable $now = null): void
    {
        $now    ??= new DateTimeImmutable();
        $target = $this->move($path, $errorDir);

        if (@file_put_contents($target . '.error', $now->format(DATE_ATOM) . "\n" . $message . "\n") === false)
            throw new ArchiveException('Can not write ' . $target . '.error');
    }

    /**
     * @return string the new path
     * @throws ArchiveException
     */
    private function move(string $path, string $targetDir): string
    {
        if (!is_dir($targetDir) && !@mkdir($targetDir, 0775, true) && !is_dir($targetDir))
            throw new ArchiveException('Can not create directory ' . $targetDir);

        $target = $this->freeName($targetDir, basename($path));
        if (!@rename($path, $target))
            throw new ArchiveException('Can not move ' . $path . ' to ' . $target);

        return $target;
    }

    private function freeName(string $dir, string $name): string
    {
        $target = $dir . '/' . $name;
        if (!file_exists($target) && !file_exists($target . '.error'))
            return $target;

        $info = pathinfo($name);
        $base = $info['filename'] . '-' . date('YmdHis');
        $ext  = isset($info['extension']) ? '.' . $info['extension'] : '';
        for ($i = 0; ; $i++)
        {
            $target = $dir . '/' . $base . ($i === 0 ? '' : '-' . $i) . $ext;
            if (!file_exists($target) && !file_exists($target . '.error'))
                return $target;
        }
    }
}
