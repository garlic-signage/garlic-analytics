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

use App\Framework\Database\ClickHouseClientInterface;
use App\Framework\Exceptions\DatabaseException;
use App\Framework\Query\PageQuery;
use App\Framework\Query\QueryRepositoryInterface;

/**
 * Reads the raw table play_log. The filter uses the sorting key (player_id, start_time), the order of the
 * result follows it. start_time decides if a play belongs to the time range.
 */
readonly class PlayLogQueryRepository implements QueryRepositoryInterface
{
    private const string FILTER = "player_id = {player_id:String}
        AND start_time >= toDateTime({from:UInt32}, 'UTC')
        AND start_time < toDateTime({to:UInt32}, 'UTC')";

    public function __construct(private ClickHouseClientInterface $client) {}

    /**
     * @throws DatabaseException
     */
    public function count(PageQuery $query): int
    {
        $rows = $this->client->select('SELECT count() AS total FROM play_log WHERE ' . self::FILTER, $this->filterParameters($query));

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
            'SELECT content_id, toUnixTimestamp(start_time) AS start_ts, toUnixTimestamp(end_time) AS end_ts, duration_s
             FROM play_log WHERE ' . self::FILTER . '
             ORDER BY start_time ' . $direction . ', content_id ' . $direction . ', end_time ' . $direction . '
             LIMIT {limit:UInt32} OFFSET {offset:UInt32}',
            [...$this->filterParameters($query), 'limit' => $query->limit, 'offset' => $query->offset]
        );

        $items = [];
        foreach ($rows as $row)
        {
            $contentId = $row['content_id'] ?? '';
            $items[]   = [
                'content_id' => is_string($contentId) ? $contentId : '',
                'start_time' => gmdate('Y-m-d\TH:i:s\Z', $this->toInt($row['start_ts'] ?? 0)),
                'end_time'   => gmdate('Y-m-d\TH:i:s\Z', $this->toInt($row['end_ts'] ?? 0)),
                'duration_s' => $this->toInt($row['duration_s'] ?? 0),
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

    private function toInt(mixed $value): int
    {
        return is_int($value) || is_string($value) ? (int) $value : 0;
    }
}
