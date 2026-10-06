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

namespace App\Framework\Query;

/**
 * The SQL condition on player_id of the endpoints that aggregate an hourly table: one player or a group. Only
 * placeholders, the IDs are parameters (parameters()), so no ID gets into the SQL string.
 */
final readonly class PlayerCondition
{
    /**
     * "player_id = {player_id:String}" for one player, "player_id IN ({player_0:String}, ...)" for a group.
     */
    public static function sql(PageQuery $query): string
    {
        if ($query->playerIds === [])
            return 'player_id = {player_id:String}';

        $placeholders = array_map(static fn(int $index): string => '{player_' . $index . ':String}', array_keys($query->playerIds));

        return 'player_id IN (' . implode(', ', $placeholders) . ')';
    }

    /**
     * @return array<string,string>
     */
    public static function parameters(PageQuery $query): array
    {
        if ($query->playerIds === [])
            return ['player_id' => $query->playerId];

        $parameters = [];
        foreach ($query->playerIds as $index => $playerId)
            $parameters['player_' . $index] = $playerId;

        return $parameters;
    }
}
