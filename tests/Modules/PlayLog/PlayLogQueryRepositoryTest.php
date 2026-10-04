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

namespace Tests\Modules\PlayLog;

use App\Framework\Database\ClickHouseClientInterface;
use App\Framework\Query\PageQuery;
use App\Modules\PlayLog\PlayLogQueryRepository;
use DateTimeImmutable;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

class PlayLogQueryRepositoryTest extends TestCase
{
    private function query(bool $descending = true): PageQuery
    {
        return new PageQuery('p\'1', new DateTimeImmutable('2026-10-01T00:00:00Z'), new DateTimeImmutable('2026-10-02T00:00:00Z'), 50, 100, $descending);
    }

    #[Group('units')]
    public function testCountUsesTypedParametersAndNoValueInTheSql(): void
    {
        $client = $this->createMock(ClickHouseClientInterface::class);
        $client->expects($this->once())->method('select')->with(
            static::logicalAnd(
                static::stringContains('count()'),
                static::stringContains('FROM play_log'),
                static::stringContains('{player_id:String}'),
                static::logicalNot(static::stringContains("p'1"))
            ),
            ['player_id' => "p'1", 'from' => 1790812800, 'to' => 1790899200]
        )->willReturn([['total' => 7]]);

        static::assertSame(7, new PlayLogQueryRepository($client)->count($this->query()));
    }

    #[Group('units')]
    public function testFindMapsTheRowsAndPassesTheOrderLimitAndOffset(): void
    {
        $client = $this->createMock(ClickHouseClientInterface::class);
        $client->expects($this->once())->method('select')->with(
            static::logicalAnd(
                static::stringContains('ORDER BY start_time DESC, content_id DESC, end_time DESC'),
                static::stringContains('LIMIT {limit:UInt32} OFFSET {offset:UInt32}')
            ),
            ['player_id' => "p'1", 'from' => 1790812800, 'to' => 1790899200, 'limit' => 50, 'offset' => 100]
        )->willReturn([['content_id' => 'c1', 'start_ts' => 1790856000, 'end_ts' => 1790856010, 'duration_s' => 10]]);

        $items = new PlayLogQueryRepository($client)->find($this->query());

        static::assertSame([['content_id' => 'c1', 'start_time' => '2026-10-01T12:00:00Z', 'end_time' => '2026-10-01T12:00:10Z', 'duration_s' => 10]], $items);
    }

    #[Group('units')]
    public function testFindSortsAscendingIfAsked(): void
    {
        $client = $this->createMock(ClickHouseClientInterface::class);
        $client->expects($this->once())->method('select')->with(
            static::stringContains('ORDER BY start_time ASC, content_id ASC, end_time ASC'),
            static::anything()
        )->willReturn([]);

        static::assertSame([], new PlayLogQueryRepository($client)->find($this->query(false)));
    }
}
