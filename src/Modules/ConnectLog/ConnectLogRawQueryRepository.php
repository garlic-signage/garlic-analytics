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
 * The raw connects of a player from connect_log (one per index request). The range is short, see
 * max_raw_range_hours in config_connectlog.ini.
 */
readonly class ConnectLogRawQueryRepository implements QueryRepositoryInterface
{
    private const string FILTER = "player_id = {player_id:String}
        AND connected_at >= toDateTime({from:UInt32}, 'UTC')
        AND connected_at < toDateTime({to:UInt32}, 'UTC')";

    public function __construct(private ClickHouseClientInterface $client) {}

    /**
     * @throws DatabaseException
     */
    public function count(PageQuery $query): int
    {
        $rows = $this->client->select('SELECT count() AS total FROM connect_log WHERE ' . self::FILTER, $this->filterParameters($query));

        $total = $rows[0]['total'] ?? 0;

        return is_int($total) || is_string($total) ? (int) $total : 0;
    }

    /**
     * @return list<array<string,int|string>>
     * @throws DatabaseException
     */
    public function find(PageQuery $query): array
    {
        $direction = $query->descending ? 'DESC' : 'ASC';
        $rows      = $this->client->select(
            'SELECT toUnixTimestamp(connected_at) AS connected_ts, refresh
             FROM connect_log WHERE ' . self::FILTER . '
             ORDER BY connected_at ' . $direction . ', refresh ' . $direction . '
             LIMIT {limit:UInt32} OFFSET {offset:UInt32}',
            [...$this->filterParameters($query), 'limit' => $query->limit, 'offset' => $query->offset]
        );

        $items = [];
        foreach ($rows as $row)
        {
            $connected = $row['connected_ts'] ?? 0;
            $refresh   = $row['refresh'] ?? 0;
            $items[]   = [
                'connected_at' => gmdate('Y-m-d\TH:i:s\Z', is_int($connected) || is_string($connected) ? (int) $connected : 0),
                'refresh'      => is_int($refresh) || is_string($refresh) ? (int) $refresh : 0,
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
}
