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

/**
 * Keeps two runs from working on the same files (flock on a lock file). The lock is released
 * with release() or when the process ends.
 */
class RunLock
{
    private mixed $handle = null;

    public function __construct(private readonly string $path) {}

    /**
     * @return bool false if another run holds the lock
     * @throws ArchiveException
     */
    public function acquire(): bool
    {
        $dir = dirname($this->path);
        if (!is_dir($dir) && !@mkdir($dir, 0775, true) && !is_dir($dir))
            throw new ArchiveException('Can not create directory ' . $dir);

        $handle = @fopen($this->path, 'c');
        if ($handle === false)
            throw new ArchiveException('Can not open lock file ' . $this->path);

        if (!flock($handle, LOCK_EX | LOCK_NB))
        {
            fclose($handle);
            return false;
        }

        $this->handle = $handle;

        return true;
    }

    public function release(): void
    {
        if (is_resource($this->handle))
        {
            flock($this->handle, LOCK_UN);
            fclose($this->handle);
        }
        $this->handle = null;
    }
}
