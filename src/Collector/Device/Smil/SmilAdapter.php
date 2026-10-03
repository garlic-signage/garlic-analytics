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

namespace App\Collector\Device\Smil;

use App\Collector\Device\DeviceAdapterInterface;
use App\Collector\LogType;
use App\Collector\PlayLogRecord;

/**
 * Garlic player and other SMIL players: playlog-*.xml, event-*.xml, and system-*.xml.
 */
readonly class SmilAdapter implements DeviceAdapterInterface
{
    public function __construct(private SmilPlayLogParser $playLogParser) {}

    public function classify(string $fileName): ?LogType
    {
        if (!str_ends_with($fileName, '.xml'))
            return null;

        return match (true)
        {
            str_starts_with($fileName, 'playlog-') => LogType::PlayLog,
            str_starts_with($fileName, 'event-')   => LogType::Event,
            str_starts_with($fileName, 'system-')  => LogType::System,
            default                                => null,
        };
    }

    /**
     * @return list<PlayLogRecord>
     */
    public function parsePlayLog(string $filePath): array
    {
        return $this->playLogParser->parse($filePath);
    }
}
