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

namespace App\Modules\SystemLog;

use App\Framework\Database\BatchRepository;
use App\Framework\Exceptions\DatabaseException;
use App\Framework\Ingest\IngestRepositoryInterface;
use JsonException;

/**
 * Inserts into the table system_log. ClickHouse fills received_at.
 *
 * @implements IngestRepositoryInterface<SystemLogEvent>
 */
readonly class SystemLogRepository extends BatchRepository implements IngestRepositoryInterface
{
    private const string TABLE       = 'system_log';
    private const string TIME_FORMAT = 'Y-m-d H:i:s';

    /**
     * @param list<SystemLogEvent> $events
     * @throws DatabaseException
     * @throws JsonException
     */
    public function insertBatch(array $events): void
    {
        $rows = [];
        foreach ($events as $event)
        {
            $rows[] = [
                $event->playerId,
                $event->reportedAt->format(self::TIME_FORMAT),
                $event->systemStart->format(self::TIME_FORMAT),
                $event->timeZone,
                $event->diskTotal,
                $event->diskFree,
                $event->cpuUsage,
                $event->memoryTotal,
                $event->memoryUsed,
                $event->hdmiOutput
            ];
        }

        $this->insertRows(
            self::TABLE,
            $rows,
            ['player_id', 'reported_at', 'system_start', 'time_zone', 'disk_total', 'disk_free', 'cpu_usage', 'memory_total', 'memory_used', 'hdmi_output']
        );
    }
}
