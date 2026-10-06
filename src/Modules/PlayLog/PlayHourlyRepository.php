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
use App\Framework\Query\PageQuery;
use App\Framework\Query\QueryRepositoryInterface;

/**
 * Base of the repositories that read the hourly aggregate play_hourly of one player: the condition of the
 * player, the range and the optional content_id, and its parameters. The aggregate is a SummingMergeTree,
 * so the queries of the subclasses use sum() and GROUP BY.
 *
 * An hour counts for the range if it starts at from or later and before to. A play belongs to the hour it
 * started in, a play over a full hour is not split.
 */
abstract readonly class PlayHourlyRepository implements QueryRepositoryInterface
{
    private const string FILTER = "player_id = {player_id:String}
        AND hour >= toDateTime({from:UInt32}, 'UTC')
        AND hour < toDateTime({to:UInt32}, 'UTC')";

    public function __construct(protected ClickHouseClientInterface $client) {}

    /**
     * The condition of the player, the range and the content_id of the client. Only placeholders, the values
     * are in parameters().
     */
    protected function where(PageQuery $query): string
    {
        return self::FILTER . (isset($query->filters['content_id']) ? ' AND content_id = {content_id:String}' : '');
    }

    /**
     * @return array<string,int|string>
     */
    protected function parameters(PageQuery $query): array
    {
        $parameters = [
            'player_id' => $query->playerId,
            'from'      => $query->from->getTimestamp(),
            'to'        => $query->to->getTimestamp(),
        ];

        $contentId = $query->filters['content_id'] ?? null;
        if (is_string($contentId))
            $parameters['content_id'] = $contentId;

        return $parameters;
    }

    /**
     * ClickHouse sends UInt64 as a string in JSON.
     */
    protected function toInt(mixed $value): int
    {
        return is_int($value) || is_string($value) ? (int) $value : 0;
    }
}
