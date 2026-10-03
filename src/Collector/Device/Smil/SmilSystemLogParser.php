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
use App\Collector\SystemLogRecord;
use XMLReader;

/**
 * Reads a system-*.xml of a SMIL player:
 *
 *   <report xmlns="..."><date/><player id="..."><systemInfo>
 *     <systemStartTime/><systemTimeZone/><totalCapacity/><totalFreeSpace/>
 *     <cpuUsage/><memoryTotal/><memoryUsed/><hdmiOutput/> ... </systemInfo></player></report>
 *
 * Older players write timeZone instead of systemTimeZone and do not report cpu, memory and hdmi.
 * Only these values are taken. network (MAC and IP addresses), configuration (it contains passwords)
 * and hardwareInfo never leave the collector.
 *
 * Normalized here: the offset of the times "+0200" becomes "+02:00", the cpu usage "31%" becomes 31.
 *
 * @extends SmilReportParser<SystemLogRecord>
 */
readonly class SmilSystemLogParser extends SmilReportParser
{
    private const array FIELDS   = ['systemStartTime', 'totalCapacity', 'totalFreeSpace', 'cpuUsage', 'memoryTotal', 'memoryUsed', 'hdmiOutput'];
    private const array REQUIRED = ['date', 'systemStartTime', 'timeZone', 'totalCapacity', 'totalFreeSpace'];

    protected function logElement(): string
    {
        return 'player';
    }

    protected function itemElement(): string
    {
        return 'systemInfo';
    }

    protected function description(): string
    {
        return 'a system report';
    }

    protected function collectReport(XMLReader $reader, string $name, array &$report): void
    {
        if ($name === 'date')
            $report['date'] = trim($reader->readString());
    }

    protected function collect(XMLReader $reader, string $name, array &$fields): void
    {
        if (in_array($name, self::FIELDS, true))
            $fields[$name] = trim($reader->readString());
        elseif ($name === 'timeZone' || $name === 'systemTimeZone')
            $fields['timeZone'] = trim($reader->readString());
    }

    /**
     * @throws ParseException
     */
    protected function buildRecord(string $playerId, array $fields, int $number): SystemLogRecord
    {
        $this->requireFields($fields, self::REQUIRED, $number);

        $hdmi = $fields['hdmiOutput'] ?? '';

        return new SystemLogRecord(
            $playerId,
            $this->normalizeTime($fields['date']),
            $this->normalizeTime($fields['systemStartTime']),
            $fields['timeZone'],
            $this->number($fields, 'totalCapacity', $number) ?? 0,
            $this->number($fields, 'totalFreeSpace', $number) ?? 0,
            $this->number($fields, 'cpuUsage', $number, true),
            $this->number($fields, 'memoryTotal', $number),
            $this->number($fields, 'memoryUsed', $number),
            $hdmi === '' ? null : $hdmi
        );
    }

    /**
     * "2026-10-03T18:27:36+0200" -> "2026-10-03T18:27:36+02:00"
     */
    private function normalizeTime(string $time): string
    {
        return preg_replace('/([+-]\d{2})(\d{2})$/', '$1:$2', $time) ?? $time;
    }

    /**
     * An empty or missing value gives null. $percent allows a trailing "%".
     *
     * @param array<string,string> $fields
     * @throws ParseException
     */
    private function number(array $fields, string $name, int $number, bool $percent = false): ?int
    {
        $value = $fields[$name] ?? '';
        if ($value === '')
            return null;

        if (preg_match($percent ? '/^(\d+)\s*%?$/' : '/^(\d+)$/', $value, $match) !== 1)
            throw new ParseException($this->itemElement() . ' #' . $number . ': ' . $name . ' is not a number');

        return (int) $match[1];
    }
}
