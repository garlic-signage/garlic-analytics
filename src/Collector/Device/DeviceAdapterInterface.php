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

use App\Collector\Exceptions\ParseException;
use App\Collector\LogType;
use App\Collector\RecordInterface;
use InvalidArgumentException;

/**
 * Translates the files of one device family into normalized records.
 *
 * Which adapter reads a file follows from its directory (see DeviceSource), the adapter itself
 * only tells the type of a file and parses it. More types (system reports)
 * are added here when their ingest exists.
 */
interface DeviceAdapterInterface
{
    /**
     * Type of a file by its name, null if the name is not known to this device.
     */
    public function classify(string $fileName): ?LogType;

    /**
     * @return list<RecordInterface>
     * @throws ParseException
     * @throws InvalidArgumentException the type has no parser
     */
    public function parse(LogType $type, string $filePath): array;
}
