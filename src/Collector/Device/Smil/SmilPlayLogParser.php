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
 * The namespace is ignored (it differs between report types), elements are matched by local name.
 * The whole file is read before anything is returned, so a broken file never yields half of its records.
 */
readonly class SmilPlayLogParser
{
    private const array FIELDS = ['contentId', 'startTime', 'endTime'];

    /**
     * @return list<PlayLogRecord>
     * @throws ParseException
     */
    public function parse(string $filePath): array
    {
        if (!is_file($filePath) || !is_readable($filePath))
            throw new ParseException('File is not readable');

        $previous = libxml_use_internal_errors(true);
        libxml_clear_errors();
        $reader = XMLReader::open($filePath, null, LIBXML_NONET);

        try
        {
            if ($reader === false)
                throw new ParseException('File is not valid XML');

            return $this->read($reader);
        }
        finally
        {
            if ($reader !== false)
                $reader->close();
            libxml_clear_errors();
            libxml_use_internal_errors($previous);
        }
    }

    /**
     * @return list<PlayLogRecord>
     * @throws ParseException
     */
    private function read(XMLReader $reader): array
    {
        $records  = [];
        $playerId = null;
        $hasLog   = false;
        $current  = null;
        $isRoot   = true;

        while ($reader->read())
        {
            if ($reader->nodeType === XMLReader::ELEMENT)
            {
                $name = $reader->localName;
                if ($isRoot && $name !== 'report')
                    throw new ParseException('Root element must be "report", found "' . $name . '"');
                $isRoot = false;

                if ($name === 'player')
                    $playerId ??= $reader->getAttribute('id');
                elseif ($name === 'contentPlayLog')
                    $hasLog = true;
                elseif ($name === 'contentPlayed' && $hasLog)
                    $current = [];
                elseif ($current !== null && in_array($name, self::FIELDS, true))
                    $current[$name] = trim($reader->readString());
            }
            elseif ($reader->nodeType === XMLReader::END_ELEMENT && $current !== null && $reader->localName === 'contentPlayed')
            {
                $records[] = $this->buildRecord($playerId, $current, count($records) + 1);
                $current   = null;
            }
        }

        $errors = libxml_get_errors();
        if ($errors !== [])
            throw new ParseException('Invalid XML: ' . trim($errors[0]->message) . ' (line ' . $errors[0]->line . ')');
        if (!$hasLog)
            throw new ParseException('Element "contentPlayLog" is missing, this is not a play log');

        return $records;
    }

    /**
     * @param array<string,string> $fields
     * @throws ParseException
     */
    private function buildRecord(?string $playerId, array $fields, int $number): PlayLogRecord
    {
        if ($playerId === null || trim($playerId) === '')
            throw new ParseException('Attribute "id" of element "player" is missing');

        foreach (self::FIELDS as $field)
        {
            if (($fields[$field] ?? '') === '')
                throw new ParseException('contentPlayed #' . $number . ' has no ' . $field);
        }

        return new PlayLogRecord(trim($playerId), $fields['contentId'], $fields['startTime'], $fields['endTime']);
    }
}
