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
use App\Collector\RecordInterface;
use XMLReader;

/**
 * Reads a report file of a SMIL player:
 *
 *   <report xmlns="..."><player id="..."><LOG-ELEMENT>
 *     <ITEM-ELEMENT> fields ... </ITEM-ELEMENT> ...
 *
 * The subclasses name the elements and build the records. The namespace is ignored (it differs
 * between report types), elements are matched by local name.
 * The whole file is read before anything is returned, so a broken file never yields half of its records.
 *
 * @template T of RecordInterface
 */
abstract readonly class SmilReportParser
{
    /** Element which holds the items, e.g. "contentPlayLog" */
    abstract protected function logElement(): string;

    /** Element of one item, e.g. "contentPlayed" */
    abstract protected function itemElement(): string;

    /** Name of the report for error messages, e.g. "a play log" */
    abstract protected function description(): string;

    /**
     * Called for every element outside of an item (e.g. the report date). Collects its data into $report,
     * which every item of the file gets as its start of $fields. Nothing by default.
     *
     * @param array<string,string> $report
     */
    protected function collectReport(XMLReader $reader, string $name, array &$report): void {}

    /**
     * Called for every element inside an item. Collects its data into $fields.
     *
     * @param array<string,string> $fields
     */
    abstract protected function collect(XMLReader $reader, string $name, array &$fields): void;

    /**
     * @param array<string,string> $fields
     * @return T
     * @throws ParseException
     */
    abstract protected function buildRecord(string $playerId, array $fields, int $number): RecordInterface;

    /**
     * @return list<T>
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
     * @return list<T>
     * @throws ParseException
     */
    private function read(XMLReader $reader): array
    {
        $records  = [];
        $report   = [];
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

                if ($name === $this->logElement())
                    $hasLog = true;
                elseif ($name === $this->itemElement() && $hasLog)
                    $current = $report;
                elseif ($current !== null)
                    $this->collect($reader, $name, $current);
                else
                    $this->collectReport($reader, $name, $report);
            }
            elseif ($reader->nodeType === XMLReader::END_ELEMENT && $current !== null && $reader->localName === $this->itemElement())
            {
                if ($playerId === null || trim($playerId) === '')
                    throw new ParseException('Attribute "id" of element "player" is missing');

                $records[] = $this->buildRecord(trim($playerId), $current, count($records) + 1);
                $current   = null;
            }
        }

        $errors = libxml_get_errors();
        if ($errors !== [])
            throw new ParseException('Invalid XML: ' . trim($errors[0]->message) . ' (line ' . $errors[0]->line . ')');
        if (!$hasLog)
            throw new ParseException('Element "' . $this->logElement() . '" is missing, this is not ' . $this->description());

        return $records;
    }

    /**
     * @param array<string,string> $fields
     * @param list<string>         $required
     * @throws ParseException
     */
    protected function requireFields(array $fields, array $required, int $number): void
    {
        foreach ($required as $field)
        {
            if (($fields[$field] ?? '') === '')
                throw new ParseException($this->itemElement() . ' #' . $number . ' has no ' . $field);
        }
    }
}
