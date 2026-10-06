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

namespace App\Modules\ConnectLog;

use App\Framework\Database\ClickHouseClientInterface;
use App\Framework\Exceptions\DatabaseException;
use App\Framework\Query\PageQuery;
use App\Framework\Query\QueryRepositoryInterface;

/**
 * The connects of a player per hour, day or month, summed up from the hourly aggregate connect_hourly.
 * Days and months are those of the time zone of the query. A period belongs to the range by the start of the
 * hours it consists of: an hour counts if it starts at from or later and before to.
 *
 * The aggregate is a SummingMergeTree, so every query uses sum() and GROUP BY.
 */
readonly class ConnectLogQueryRepository implements QueryRepositoryInterface
{
    private const string FILTER = "player_id = {player_id:String}
        AND hour >= toDateTime({from:UInt32}, 'UTC')
        AND hour < toDateTime({to:UInt32}, 'UTC')";

    public function __construct(private ClickHouseClientInterface $client) {}

    /**
     * @throws DatabaseException
     */
    public function count(PageQuery $query): int
    {
        $rows = $this->client->select(
            'SELECT count() AS total FROM (SELECT ' . $this->period($query) . ' AS period FROM connect_hourly WHERE ' . self::FILTER . ' GROUP BY period)',
            $this->parameters($query)
        );

        return $this->toInt($rows[0]['total'] ?? 0);
    }

    /**
     * @return list<array<string,int|string>>
     * @throws DatabaseException
     */
    public function find(PageQuery $query): array
    {
        $direction = $query->descending ? 'DESC' : 'ASC';
        $rows      = $this->client->select(
            'SELECT ' . $this->period($query) . ' AS period, sum(connects) AS total_connects, sum(covered_s) AS total_covered_s
             FROM connect_hourly WHERE ' . self::FILTER . '
             GROUP BY period
             ORDER BY period ' . $direction . '
             LIMIT {limit:UInt32} OFFSET {offset:UInt32}',
            [...$this->parameters($query), 'limit' => $query->limit, 'offset' => $query->offset]
        );

        $items = [];
        foreach ($rows as $row)
        {
            $period  = $row['period'] ?? '';
            $items[] = [
                'period'    => $this->resolution($query) === 'hour' ? gmdate('Y-m-d\TH:i:s\Z', $this->toInt($period)) : $this->toString($period),
                'connects'  => $this->toInt($row['total_connects'] ?? 0),
                'covered_s' => $this->toInt($row['total_covered_s'] ?? 0),
            ];
        }

        return $items;
    }

    /**
     * The expression of the period: the Unix time for hours (sorts right, formatted in PHP), the local date
     * ("2026-10-03") for days and the local month ("2026-10") for months, both sort right as strings.
     * Only fixed strings, the time zone is a parameter.
     */
    private function period(PageQuery $query): string
    {
        return match ($this->resolution($query))
        {
            'day'   => "formatDateTime(hour, '%Y-%m-%d', {time_zone:String})",
            'month' => "formatDateTime(hour, '%Y-%m', {time_zone:String})",
            default => 'toUnixTimestamp(hour)',
        };
    }

    private function resolution(PageQuery $query): string
    {
        $resolution = $query->filters['resolution'] ?? 'hour';

        return is_string($resolution) ? $resolution : 'hour';
    }

    /**
     * @return array<string,int|string>
     */
    private function parameters(PageQuery $query): array
    {
        $parameters = [
            'player_id' => $query->playerId,
            'from'      => $query->from->getTimestamp(),
            'to'        => $query->to->getTimestamp(),
        ];

        $timeZone = $query->filters['time_zone'] ?? 'UTC';
        if ($this->resolution($query) !== 'hour' && is_string($timeZone))
            $parameters['time_zone'] = $timeZone;

        return $parameters;
    }

    /**
     * ClickHouse sends UInt64 as a string in JSON.
     */
    private function toInt(mixed $value): int
    {
        return is_int($value) || is_string($value) ? (int) $value : 0;
    }

    private function toString(mixed $value): string
    {
        return is_string($value) ? $value : '';
    }
}
