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

use App\Framework\Database\ClickHouseClientInterface;
use App\Framework\Exceptions\DatabaseException;
use App\Framework\Query\PageQuery;
use App\Framework\Query\QueryRepositoryInterface;

readonly class SystemLogQueryRepository implements QueryRepositoryInterface
{
    private const string FILTER = "player_id = {player_id:String}
        AND reported_at >= toDateTime({from:UInt32}, 'UTC')
        AND reported_at < toDateTime({to:UInt32}, 'UTC')";

    public function __construct(private ClickHouseClientInterface $client) {}

    /**
     * @throws DatabaseException
     */
    public function count(PageQuery $query): int
    {
        $rows = $this->client->select('SELECT count() AS total FROM system_log WHERE ' . self::FILTER, $this->filterParameters($query));

        return $this->toInt($rows[0]['total'] ?? 0);
    }

    /**
     * @return list<array<string,int|string|null>>
     * @throws DatabaseException
     */
    public function find(PageQuery $query): array
    {
        $direction = $query->descending ? 'DESC' : 'ASC';
        $rows      = $this->client->select(
            'SELECT toUnixTimestamp(reported_at) AS reported_ts, toUnixTimestamp(system_start) AS start_ts, time_zone,
                    disk_total, disk_free, cpu_usage, memory_total, memory_used, hdmi_output
             FROM system_log WHERE ' . self::FILTER . '
             ORDER BY reported_at ' . $direction . ', system_start ' . $direction . ', disk_total ' . $direction . ', disk_free ' . $direction . '
             LIMIT {limit:UInt32} OFFSET {offset:UInt32}',
            [...$this->filterParameters($query), 'limit' => $query->limit, 'offset' => $query->offset]
        );

        $items = [];
        foreach ($rows as $row)
        {
            $timeZone = $row['time_zone'] ?? '';
            $hdmi     = $row['hdmi_output'] ?? '';
            $items[]  = [
                'reported_at'  => gmdate('Y-m-d\TH:i:s\Z', $this->toInt($row['reported_ts'] ?? 0)),
                'system_start' => gmdate('Y-m-d\TH:i:s\Z', $this->toInt($row['start_ts'] ?? 0)),
                'time_zone'    => is_string($timeZone) ? $timeZone : '',
                'disk_total'   => $this->toInt($row['disk_total'] ?? 0),
                'disk_free'    => $this->toInt($row['disk_free'] ?? 0),
                'cpu_usage'    => $this->toNullableInt($row['cpu_usage'] ?? null),
                'memory_total' => $this->toNullableInt($row['memory_total'] ?? null),
                'memory_used'  => $this->toNullableInt($row['memory_used'] ?? null),
                'hdmi_output'  => is_string($hdmi) ? $hdmi : '',
            ];
        }

        return $items;
    }

    /**
     * @return array<string,int|string>
     */
    private function filterParameters(PageQuery $query): array
    {
        return [
            'player_id' => $query->playerId,
            'from'      => $query->from->getTimestamp(),
            'to'        => $query->to->getTimestamp(),
        ];
    }

    /**
     * ClickHouse sends UInt64 as a string in JSON.
     */
    private function toInt(mixed $value): int
    {
        return is_int($value) || is_string($value) ? (int) $value : 0;
    }

    private function toNullableInt(mixed $value): ?int
    {
        return $value === null ? null : $this->toInt($value);
    }
}
