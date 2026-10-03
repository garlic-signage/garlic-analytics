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
 * One system report as the device reported it, in the format of POST /v1/systemlog.
 * The times stay as sent (ISO 8601 with offset), the API converts them to UTC.
 */
readonly class SystemLogRecord implements RecordInterface
{
    public function __construct(
        public string  $playerId,
        public string  $reportedAt,
        public string  $systemStart,
        public string  $timeZone,
        public int     $diskTotal,
        public int     $diskFree,
        public ?int    $cpuUsage = null,
        public ?int    $memoryTotal = null,
        public ?int    $memoryUsed = null,
        public ?string $hdmiOutput = null
    ) {}

    /**
     * @return array<string,mixed> values the device did not report are left out
     */
    public function toArray(): array
    {
        $data = [
            'player_id'    => $this->playerId,
            'reported_at'  => $this->reportedAt,
            'system_start' => $this->systemStart,
            'time_zone'    => $this->timeZone,
            'disk_total'   => $this->diskTotal,
            'disk_free'    => $this->diskFree,
            'cpu_usage'    => $this->cpuUsage,
            'memory_total' => $this->memoryTotal,
            'memory_used'  => $this->memoryUsed,
            'hdmi_output'  => $this->hdmiOutput,
        ];

        return array_filter($data, static fn(mixed $value): bool => $value !== null);
    }
}
