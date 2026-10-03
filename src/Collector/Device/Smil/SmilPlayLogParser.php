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

use App\Collector\Exceptions\ParseException;
use App\Collector\PlayLogRecord;
use XMLReader;

/**
 * Reads a playlog-*.xml of a SMIL player:
 *
 *   <report xmlns="..."><player id="..."><contentPlayLog>
 *     <contentPlayed><contentId/><startTime/><endTime/></contentPlayed> ...
 *
 * @extends SmilReportParser<PlayLogRecord>
 */
readonly class SmilPlayLogParser extends SmilReportParser
{
    private const array FIELDS = ['contentId', 'startTime', 'endTime'];

    protected function logElement(): string
    {
        return 'contentPlayLog';
    }

    protected function itemElement(): string
    {
        return 'contentPlayed';
    }

    protected function description(): string
    {
        return 'a play log';
    }

    protected function collect(XMLReader $reader, string $name, array &$fields): void
    {
        if (in_array($name, self::FIELDS, true))
            $fields[$name] = trim($reader->readString());
    }

    /**
     * @throws ParseException
     */
    protected function buildRecord(string $playerId, array $fields, int $number): PlayLogRecord
    {
        $this->requireFields($fields, self::FIELDS, $number);

        return new PlayLogRecord($playerId, $fields['contentId'], $fields['startTime'], $fields['endTime']);
    }
}
