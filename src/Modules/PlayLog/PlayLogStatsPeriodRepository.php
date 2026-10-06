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

namespace App\Modules\PlayLog;

use App\Framework\Exceptions\DatabaseException;
use App\Framework\Query\PageQuery;
use App\Framework\Query\PeriodQuery;

/**
 * The plays of a player per hour, day or month, optionally of one content, summed up from the hourly
 * aggregate play_hourly. Days and months are those of the time zone of the query. A play belongs to the hour
 * it started in, a play over a full hour is not split.
 *
 * The aggregate is a SummingMergeTree, so every query uses sum() and GROUP BY.
 */
readonly class PlayLogStatsPeriodRepository extends PlayHourlyRepository
{
    /**
     * @throws DatabaseException
     */
    public function count(PageQuery $query): int
    {
        $rows = $this->client->select(
            'SELECT count() AS total FROM (SELECT ' . PeriodQuery::expression($query) . ' AS period FROM play_hourly WHERE '
                . $this->where($query) . ' GROUP BY period)',
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
            'SELECT ' . PeriodQuery::expression($query) . ' AS period, sum(plays) AS total_plays, sum(duration_s) AS total_duration_s
             FROM play_hourly WHERE ' . $this->where($query) . '
             GROUP BY period
             ORDER BY period ' . $direction . '
             LIMIT {limit:UInt32} OFFSET {offset:UInt32}',
            [...$this->parameters($query), 'limit' => $query->limit, 'offset' => $query->offset]
        );

        $items = [];
        foreach ($rows as $row)
        {
            $items[] = [
                'period'     => PeriodQuery::label($query, $row['period'] ?? ''),
                'plays'      => $this->toInt($row['total_plays'] ?? 0),
                'duration_s' => $this->toInt($row['total_duration_s'] ?? 0),
            ];
        }

        return $items;
    }

    /**
     * @return array<string,int|string>
     */
    protected function parameters(PageQuery $query): array
    {
        return [...parent::parameters($query), ...PeriodQuery::parameters($query)];
    }
}
