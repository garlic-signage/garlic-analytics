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

use DirectoryIterator;

/**
 * Lists the files in an upload directory, oldest first.
 *
 * Files that changed during the last $minAgeSeconds are left out. They may still be uploading,
 * the next run picks them up.
 */
readonly class DirectoryScanner
{
    /**
     * @return list<string> full paths
     */
    public function scan(string $dir, int $minAgeSeconds, ?int $now = null): array
    {
        if (!is_dir($dir))
            return [];

        $now   ??= time();
        $files = [];
        foreach (new DirectoryIterator($dir) as $entry)
        {
            if (!$entry->isFile() || str_starts_with($entry->getFilename(), '.'))
                continue;
            if ($now - $entry->getMTime() < $minAgeSeconds)
                continue;

            $files[] = ['path' => $entry->getPathname(), 'mtime' => $entry->getMTime()];
        }

        usort($files, static fn(array $a, array $b): int => [$a['mtime'], $a['path']] <=> [$b['mtime'], $b['path']]);

        return array_column($files, 'path');
    }
}
