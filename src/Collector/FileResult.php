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

/**
 * What happened to one uploaded file.
 */
readonly class FileResult
{
    public function __construct(
        public string     $device,
        public string     $fileName,
        public ?LogType   $type,
        public FileStatus $status,
        public ?string    $playerId = null,
        public int        $events = 0,
        public int        $batches = 0,
        public ?string    $message = null
    ) {}
}
