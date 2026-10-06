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

namespace Tests\Framework\Query;

use App\Framework\Query\PageQuery;
use App\Framework\Query\PlayerCondition;
use DateTimeImmutable;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

class PlayerConditionTest extends TestCase
{
    /**
     * @param list<string> $playerIds
     */
    private function query(string $playerId, array $playerIds = []): PageQuery
    {
        return new PageQuery($playerId, new DateTimeImmutable('2026-10-01T00:00:00Z'), new DateTimeImmutable('2026-10-02T00:00:00Z'), 10, 0, true, [], $playerIds);
    }

    #[Group('units')]
    public function testOnePlayerIsAnEquality(): void
    {
        $query = $this->query("p'1");

        static::assertSame('player_id = {player_id:String}', PlayerCondition::sql($query));
        static::assertSame(['player_id' => "p'1"], PlayerCondition::parameters($query));
    }

    #[Group('units')]
    public function testAGroupIsAnInListWithOnePlaceholderPerPlayerAndNoIdInTheSql(): void
    {
        $query = $this->query('', ["a'1", 'b', 'c']);

        static::assertSame('player_id IN ({player_0:String}, {player_1:String}, {player_2:String})', PlayerCondition::sql($query));
        static::assertSame(['player_0' => "a'1", 'player_1' => 'b', 'player_2' => 'c'], PlayerCondition::parameters($query));
        static::assertStringNotContainsString("a'1", PlayerCondition::sql($query));
    }

    #[Group('units')]
    public function testAGroupOfOneIsStillAnInList(): void
    {
        static::assertSame('player_id IN ({player_0:String})', PlayerCondition::sql($this->query('', ['only'])));
    }
}
