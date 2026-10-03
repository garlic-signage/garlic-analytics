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

use App\Collector\EventLogRecord;
use App\Collector\Exceptions\ParseException;
use XMLReader;

/**
 * Reads an event-*.xml of a SMIL player:
 *
 *   <report xmlns="..."><player id="..."><playerEventLog>
 *     <event><eventType/><eventTime/><eventSource/><eventName/>
 *       <metadata><meta name="..." content="..."/> ...</metadata>
 *     </event> ...
 *
 * The metadata is optional. A meta name which occurs twice keeps its last content.
 * The event type is lower-cased, the API knows only the lower-case names.
 *
 * @extends SmilReportParser<EventLogRecord>
 */
readonly class SmilEventLogParser extends SmilReportParser
{
    private const array FIELDS     = ['eventType', 'eventTime', 'eventSource', 'eventName'];
    private const string META_PREFIX = 'meta:';

    protected function logElement(): string
    {
        return 'playerEventLog';
    }

    protected function itemElement(): string
    {
        return 'event';
    }

    protected function description(): string
    {
        return 'an event log';
    }

    /**
     * @throws ParseException
     */
    protected function collect(XMLReader $reader, string $name, array &$fields): void
    {
        if (in_array($name, self::FIELDS, true))
            $fields[$name] = trim($reader->readString());
        elseif ($name === 'meta')
        {
            $metaName = trim($reader->getAttribute('name') ?? '');
            if ($metaName === '')
                throw new ParseException('A meta element has no name');

            $fields[self::META_PREFIX . $metaName] = $reader->getAttribute('content') ?? '';
        }
    }

    /**
     * @throws ParseException
     */
    protected function buildRecord(string $playerId, array $fields, int $number): EventLogRecord
    {
        $this->requireFields($fields, self::FIELDS, $number);

        $metadata = [];
        foreach ($fields as $key => $value)
        {
            if (str_starts_with($key, self::META_PREFIX))
                $metadata[substr($key, strlen(self::META_PREFIX))] = $value;
        }

        return new EventLogRecord(
            $playerId,
            $fields['eventTime'],
            strtolower($fields['eventType']),
            $fields['eventSource'],
            $fields['eventName'],
            $metadata
        );
    }
}
