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

namespace App\Modules\EventLog;

use App\Framework\Database\ClickHouseClientInterface;
use App\Framework\Exceptions\DatabaseException;
use App\Framework\Query\PageQuery;
use App\Framework\Query\QueryRepositoryInterface;

readonly class EventLogQueryRepository implements QueryRepositoryInterface
{
    private const string FILTER = "player_id = {player_id:String}
        AND event_time >= toDateTime({from:UInt32}, 'UTC')
        AND event_time < toDateTime({to:UInt32}, 'UTC')";

    public function __construct(private ClickHouseClientInterface $client) {}

    /**
     * @throws DatabaseException
     */
    public function count(PageQuery $query): int
    {
        $rows = $this->client->select('SELECT count() AS total FROM event_log WHERE ' . self::FILTER, $this->filterParameters($query));

        return $this->toInt($rows[0]['total'] ?? 0);
    }

    /**
     * @return list<array<string,int|string|object>>
     * @throws DatabaseException
     */
    public function find(PageQuery $query): array
    {
        $direction = $query->descending ? 'DESC' : 'ASC';
        $rows      = $this->client->select(
            'SELECT toUnixTimestamp(event_time) AS event_ts, toString(event_type) AS event_type, event_source, event_name, metadata
             FROM event_log WHERE ' . self::FILTER . '
             ORDER BY event_time ' . $direction . ', event_type ' . $direction . ', event_source ' . $direction . ', event_name ' . $direction . '
             LIMIT {limit:UInt32} OFFSET {offset:UInt32}',
            [...$this->filterParameters($query), 'limit' => $query->limit, 'offset' => $query->offset]
        );

        $items = [];
        foreach ($rows as $row)
        {
            $metadata = $row['metadata'] ?? [];
            $items[]  = [
                'event_time'   => gmdate('Y-m-d\TH:i:s\Z', $this->toInt($row['event_ts'] ?? 0)),
                'event_type'   => $this->toString($row['event_type'] ?? ''),
                'event_source' => $this->toString($row['event_source'] ?? ''),
                'event_name'   => $this->toString($row['event_name'] ?? ''),
                'metadata'     => (object) (is_array($metadata) ? $metadata : []),
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

    private function toString(mixed $value): string
    {
        return is_string($value) ? $value : '';
    }
}
