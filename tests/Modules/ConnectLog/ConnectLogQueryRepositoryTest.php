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

namespace Tests\Modules\ConnectLog;

use App\Framework\Database\ClickHouseClientInterface;
use App\Framework\Query\PageQuery;
use App\Modules\ConnectLog\ConnectLogQueryRepository;
use DateTimeImmutable;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

class ConnectLogQueryRepositoryTest extends TestCase
{
    private function query(string $resolution = 'hour', string $timeZone = 'UTC', bool $descending = true): PageQuery
    {
        return new PageQuery(
            'p\'1', new DateTimeImmutable('2026-10-01T00:00:00Z'), new DateTimeImmutable('2026-10-02T00:00:00Z'), 50, 100, $descending,
            ['resolution' => $resolution, 'time_zone' => $timeZone]
        );
    }

    #[Group('units')]
    public function testCountCountsThePeriodsAndUsesTypedParameters(): void
    {
        $client = $this->createMock(ClickHouseClientInterface::class);
        $client->expects($this->once())->method('select')->with(
            static::logicalAnd(
                static::stringContains('SELECT count() AS total FROM (SELECT toUnixTimestamp(hour) AS period FROM connect_hourly'),
                static::stringContains('GROUP BY period)'),
                static::stringContains('{player_id:String}'),
                static::logicalNot(static::stringContains("p'1"))
            ),
            ['player_id' => "p'1", 'from' => 1790812800, 'to' => 1790899200]
        )->willReturn([['total' => '24']]);

        static::assertSame(24, new ConnectLogQueryRepository($client)->count($this->query()));
    }

    #[Group('units')]
    public function testHoursAreSummedGroupedAndFormattedInUtc(): void
    {
        $client = $this->createMock(ClickHouseClientInterface::class);
        $client->expects($this->once())->method('select')->with(
            static::logicalAnd(
                static::stringContains('sum(connects) AS total_connects, sum(covered_s) AS total_covered_s'),
                static::stringContains('toUnixTimestamp(hour) AS period'),
                static::stringContains('GROUP BY period'),
                static::stringContains('ORDER BY period DESC'),
                static::stringContains('LIMIT {limit:UInt32} OFFSET {offset:UInt32}'),
                static::logicalNot(static::stringContains('time_zone'))
            ),
            ['player_id' => "p'1", 'from' => 1790812800, 'to' => 1790899200, 'limit' => 50, 'offset' => 100]
        )->willReturn([['period' => 1790856000, 'total_connects' => '12', 'total_covered_s' => '720']]);

        $items = new ConnectLogQueryRepository($client)->find($this->query());

        static::assertSame([['period' => '2026-10-01T12:00:00Z', 'connects' => 12, 'covered_s' => 720]], $items);
    }

    #[Group('units')]
    public function testDaysUseTheTimeZoneAsAParameterAndKeepTheLocalDate(): void
    {
        $client = $this->createMock(ClickHouseClientInterface::class);
        $client->expects($this->once())->method('select')->with(
            static::logicalAnd(
                static::stringContains("formatDateTime(hour, '%Y-%m-%d', {time_zone:String}) AS period"),
                static::logicalNot(static::stringContains('Europe/Berlin'))
            ),
            ['player_id' => "p'1", 'from' => 1790812800, 'to' => 1790899200, 'time_zone' => 'Europe/Berlin', 'limit' => 50, 'offset' => 100]
        )->willReturn([['period' => '2026-10-02', 'total_connects' => 3, 'total_covered_s' => 180]]);

        $items = new ConnectLogQueryRepository($client)->find($this->query('day', 'Europe/Berlin'));

        static::assertSame([['period' => '2026-10-02', 'connects' => 3, 'covered_s' => 180]], $items);
    }

    #[Group('units')]
    public function testMonthsUseTheLocalMonth(): void
    {
        $client = $this->createMock(ClickHouseClientInterface::class);
        $client->expects($this->once())->method('select')->with(
            static::stringContains("formatDateTime(hour, '%Y-%m', {time_zone:String}) AS period"),
            static::anything()
        )->willReturn([['period' => '2026-10', 'total_connects' => 1, 'total_covered_s' => 60]]);

        static::assertSame('2026-10', new ConnectLogQueryRepository($client)->find($this->query('month'))[0]['period']);
    }

    #[Group('units')]
    public function testAscendingOrderAndNoFiltersMeanHoursInUtc(): void
    {
        $client = $this->createMock(ClickHouseClientInterface::class);
        $client->expects($this->once())->method('select')->with(
            static::logicalAnd(static::stringContains('ORDER BY period ASC'), static::stringContains('toUnixTimestamp(hour)')),
            static::anything()
        )->willReturn([]);

        $query = new PageQuery('p1', new DateTimeImmutable('2026-10-01T00:00:00Z'), new DateTimeImmutable('2026-10-02T00:00:00Z'), 50, 0, false);

        static::assertSame([], new ConnectLogQueryRepository($client)->find($query));
    }

    #[Group('units')]
    public function testAGroupIsAnInListWithPlaceholdersAndTheIdsAsParameters(): void
    {
        $client = $this->createMock(ClickHouseClientInterface::class);
        $client->expects($this->exactly(2))->method('select')->with(
            static::logicalAnd(
                static::stringContains('player_id IN ({player_0:String}, {player_1:String})'),
                static::stringContains('GROUP BY period'),
                static::logicalNot(static::stringContains('player_id = ')),
                static::logicalNot(static::stringContains("a'1"))
            ),
            static::callback(static fn(array $parameters): bool => $parameters['player_0'] === "a'1" && $parameters['player_1'] === 'b' && !array_key_exists('player_id', $parameters))
        )->willReturn([['total' => 0]]);

        $query = new PageQuery('', new DateTimeImmutable('2026-10-01T00:00:00Z'), new DateTimeImmutable('2026-10-02T00:00:00Z'), 50, 0, true, ['resolution' => 'hour', 'time_zone' => 'UTC'], ["a'1", 'b']);

        $repository = new ConnectLogQueryRepository($client);
        $repository->count($query);
        $repository->find($query);
    }
}
