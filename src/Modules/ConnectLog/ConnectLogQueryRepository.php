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
use App\Framework\Query\PeriodQuery;
use App\Framework\Query\PlayerCondition;
use App\Framework\Query\QueryRepositoryInterface;

/**
 * The connects of a player or of a group of players per hour, day or month, summed up from the hourly aggregate
 * connect_hourly.
 * Days and months are those of the time zone of the query. A period belongs to the range by the start of the
 * hours it consists of: an hour counts if it starts at from or later and before to.
 *
 * The aggregate is a SummingMergeTree, so every query uses sum() and GROUP BY.
 */
readonly class ConnectLogQueryRepository implements QueryRepositoryInterface
{
    private const string RANGE = "hour >= toDateTime({from:UInt32}, 'UTC')
        AND hour < toDateTime({to:UInt32}, 'UTC')";

    public function __construct(private ClickHouseClientInterface $client) {}

    /**
     * @throws DatabaseException
     */
    public function count(PageQuery $query): int
    {
        $rows = $this->client->select(
            'SELECT count() AS total FROM (SELECT ' . PeriodQuery::expression($query) . ' AS period FROM connect_hourly WHERE ' . $this->where($query) . ' GROUP BY period)',
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
            'SELECT ' . PeriodQuery::expression($query) . ' AS period, sum(connects) AS total_connects, sum(covered_s) AS total_covered_s
             FROM connect_hourly WHERE ' . $this->where($query) . '
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
                'period'    => PeriodQuery::label($query, $period),
                'connects'  => $this->toInt($row['total_connects'] ?? 0),
                'covered_s' => $this->toInt($row['total_covered_s'] ?? 0),
            ];
        }

        return $items;
    }

    /**
     * The condition of the players (one or a group) and the range. Only placeholders, the values are in parameters().
     */
    private function where(PageQuery $query): string
    {
        return PlayerCondition::sql($query) . ' AND ' . self::RANGE;
    }

    /**
     * @return array<string,int|string>
     */
    private function parameters(PageQuery $query): array
    {
        return [
            ...PlayerCondition::parameters($query),
            'from' => $query->from->getTimestamp(),
            'to'   => $query->to->getTimestamp(),
            ...PeriodQuery::parameters($query),
        ];
    }

    /**
     * ClickHouse sends UInt64 as a string in JSON.
     */
    private function toInt(mixed $value): int
    {
        return is_int($value) || is_string($value) ? (int) $value : 0;
    }
}
