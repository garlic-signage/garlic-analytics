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

/**
 * How often and how long a player played each of its contents in a time range, summed up from the hourly
 * aggregate play_hourly. A play belongs to the hour it started in, a play over a full hour is not split.
 * An hour counts for the range if it starts at from or later and before to.
 *
 * The aggregate is a SummingMergeTree, so every query uses sum() and GROUP BY. The sort is by the sums or
 * by content_id, the ties are decided by content_id so that the pages follow each other without gaps.
 */
readonly class PlayLogStatsRepository extends PlayHourlyRepository
{
    /** The sort parameter and the column of the result it stands for. */
    private const array SORT_COLUMNS = ['plays' => 'total_plays', 'duration_s' => 'total_duration_s'];

    /**
     * @throws DatabaseException
     */
    public function count(PageQuery $query): int
    {
        $rows = $this->client->select(
            'SELECT count() AS total FROM (SELECT content_id FROM play_hourly WHERE ' . $this->where($query) . ' GROUP BY content_id)',
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
        $rows = $this->client->select(
            'SELECT content_id, sum(plays) AS total_plays, sum(duration_s) AS total_duration_s
             FROM play_hourly WHERE ' . $this->where($query) . '
             GROUP BY content_id
             ORDER BY ' . $this->orderBy($query) . '
             LIMIT {limit:UInt32} OFFSET {offset:UInt32}',
            [...$this->parameters($query), 'limit' => $query->limit, 'offset' => $query->offset]
        );

        $items = [];
        foreach ($rows as $row)
        {
            $contentId = $row['content_id'] ?? '';
            $items[]   = [
                'content_id' => is_string($contentId) ? $contentId : '',
                'plays'      => $this->toInt($row['total_plays'] ?? 0),
                'duration_s' => $this->toInt($row['total_duration_s'] ?? 0),
            ];
        }

        return $items;
    }

    /**
     * Only fixed strings: the sort is one of the keys of SORT_COLUMNS, anything else sorts by content_id.
     */
    private function orderBy(PageQuery $query): string
    {
        $direction = $query->descending ? 'DESC' : 'ASC';
        $sort      = $query->filters['sort'] ?? 'content_id';
        $column    = is_string($sort) ? (self::SORT_COLUMNS[$sort] ?? null) : null;

        return $column === null ? 'content_id ' . $direction : $column . ' ' . $direction . ', content_id ASC';
    }
}
