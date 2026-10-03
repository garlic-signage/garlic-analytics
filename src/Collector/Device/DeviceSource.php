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

namespace App\Collector\Device;

/**
 * A device family and the directory its files are uploaded to.
 * Finished files are moved to processed/ or error/, next to upload/.
 */
readonly class DeviceSource
{
    public function __construct(
        public DeviceAdapterInterface $adapter,
        public string                 $uploadDir
    ) {}

    public function processedDir(): string
    {
        return dirname($this->uploadDir) . '/processed';
    }

    public function errorDir(): string
    {
        return dirname($this->uploadDir) . '/error';
    }
}
