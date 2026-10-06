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
use App\Modules\PlayLog\PlayLogStatsPeriodRepository;
use App\Modules\PlayLog\PlayLogStatsRepository;
use DateTimeImmutable;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

class PlayLogStatsRepositoryTest extends TestCase
{
    /**
     * @param array<string,string> $filters
     */
    private function query(array $filters = [], bool $descending = false): PageQuery
    {
        return new PageQuery('p\'1', new DateTimeImmutable('2026-10-01T00:00:00Z'), new DateTimeImmutable('2026-10-02T00:00:00Z'), 50, 100, $descending, $filters);
    }

    #[Group('units')]
    public function testCountCountsTheContentsWithTypedParameters(): void
    {
        $client = $this->createMock(ClickHouseClientInterface::class);
        $client->expects($this->once())->method('select')->with(
            static::logicalAnd(
                static::stringContains('SELECT count() AS total FROM (SELECT content_id FROM play_hourly'),
                static::stringContains('GROUP BY content_id)'),
                static::stringContains('{player_id:String}'),
                static::logicalNot(static::stringContains("p'1")),
                static::logicalNot(static::stringContains('content_id = '))
            ),
            ['player_id' => "p'1", 'from' => 1790812800, 'to' => 1790899200]
        )->willReturn([['total' => '3']]);

        static::assertSame(3, new PlayLogStatsRepository($client)->count($this->query()));
    }

    #[Group('units')]
    public function testContentFilterIsAParameterInCountAndFind(): void
    {
        $client = $this->createMock(ClickHouseClientInterface::class);
        $client->expects($this->exactly(2))->method('select')->with(
            static::logicalAnd(
                static::stringContains('AND content_id = {content_id:String}'),
                static::logicalNot(static::stringContains("spot'1"))
            ),
            static::callback(static fn(array $parameters): bool => $parameters['content_id'] === "spot'1")
        )->willReturn([['total' => 0]]);

        $repository = new PlayLogStatsRepository($client);
        $query      = $this->query(['sort' => 'content_id', 'content_id' => "spot'1"]);
        $repository->count($query);
        $repository->find($query);
    }

    #[Group('units')]
    public function testFindSumsGroupsAndMapsTheRows(): void
    {
        $client = $this->createMock(ClickHouseClientInterface::class);
        $client->expects($this->once())->method('select')->with(
            static::logicalAnd(
                static::stringContains('sum(plays) AS total_plays, sum(duration_s) AS total_duration_s'),
                static::stringContains('FROM play_hourly'),
                static::stringContains('GROUP BY content_id'),
                static::stringContains('ORDER BY content_id ASC'),
                static::stringContains('LIMIT {limit:UInt32} OFFSET {offset:UInt32}')
            ),
            ['player_id' => "p'1", 'from' => 1790812800, 'to' => 1790899200, 'limit' => 50, 'offset' => 100]
        )->willReturn([['content_id' => 'spot-1', 'total_plays' => '12', 'total_duration_s' => '360']]);

        static::assertSame(
            [['content_id' => 'spot-1', 'plays' => 12, 'duration_s' => 360]],
            new PlayLogStatsRepository($client)->find($this->query())
        );
    }

    /**
     * @return array<string,array{0: array<string,string>, 1: bool, 2: string}>
     */
    public static function orderings(): array
    {
        return [
            'default content_id ascending'  => [[], false, 'ORDER BY content_id ASC'],
            'content_id descending'         => [['sort' => 'content_id'], true, 'ORDER BY content_id DESC'],
            'plays, ties by content_id'     => [['sort' => 'plays'], true, 'ORDER BY total_plays DESC, content_id ASC'],
            'duration ascending'            => [['sort' => 'duration_s'], false, 'ORDER BY total_duration_s ASC, content_id ASC'],
            'unknown sort falls back'       => [['sort' => 'nonsense'], true, 'ORDER BY content_id DESC'],
        ];
    }

    /**
     * @param array<string,string> $filters
     */
    #[Group('units')]
    #[DataProvider('orderings')]
    public function testTheSortIsOneOfTheFixedExpressions(array $filters, bool $descending, string $expected): void
    {
        $client = $this->createMock(ClickHouseClientInterface::class);
        $client->expects($this->once())->method('select')->with(static::stringContains($expected), static::anything())->willReturn([]);

        new PlayLogStatsRepository($client)->find($this->query($filters, $descending));
    }

    #[Group('units')]
    public function testPeriodStatisticsSumPerDayInTheTimeZone(): void
    {
        $client = $this->createMock(ClickHouseClientInterface::class);
        $client->expects($this->once())->method('select')->with(
            static::logicalAnd(
                static::stringContains("formatDateTime(hour, '%Y-%m-%d', {time_zone:String}) AS period"),
                static::stringContains('sum(plays) AS total_plays, sum(duration_s) AS total_duration_s'),
                static::stringContains('FROM play_hourly'),
                static::stringContains('AND content_id = {content_id:String}'),
                static::stringContains('GROUP BY period'),
                static::stringContains('ORDER BY period DESC')
            ),
            ['player_id' => "p'1", 'from' => 1790812800, 'to' => 1790899200, 'content_id' => 'spot-1', 'time_zone' => 'Europe/Berlin', 'limit' => 50, 'offset' => 100]
        )->willReturn([['period' => '2026-10-01', 'total_plays' => '4', 'total_duration_s' => '80']]);

        $query = $this->query(['resolution' => 'day', 'time_zone' => 'Europe/Berlin', 'content_id' => 'spot-1'], true);

        static::assertSame([['period' => '2026-10-01', 'plays' => 4, 'duration_s' => 80]], new PlayLogStatsPeriodRepository($client)->find($query));
    }

    #[Group('units')]
    public function testPeriodStatisticsOfHoursNeedNoTimeZoneAndAreFormattedInUtc(): void
    {
        $client = $this->createMock(ClickHouseClientInterface::class);
        $client->expects($this->once())->method('select')->with(
            static::logicalAnd(static::stringContains('toUnixTimestamp(hour) AS period'), static::logicalNot(static::stringContains('time_zone'))),
            ['player_id' => "p'1", 'from' => 1790812800, 'to' => 1790899200, 'limit' => 50, 'offset' => 100]
        )->willReturn([['period' => 1790856000, 'total_plays' => 1, 'total_duration_s' => 10]]);

        static::assertSame(
            [['period' => '2026-10-01T12:00:00Z', 'plays' => 1, 'duration_s' => 10]],
            new PlayLogStatsPeriodRepository($client)->find($this->query())
        );
    }

    #[Group('units')]
    public function testPeriodStatisticsCountThePeriods(): void
    {
        $client = $this->createMock(ClickHouseClientInterface::class);
        $client->expects($this->once())->method('select')->with(
            static::stringContains('SELECT count() AS total FROM (SELECT formatDateTime(hour, \'%Y-%m\', {time_zone:String}) AS period FROM play_hourly'),
            static::anything()
        )->willReturn([['total' => 12]]);

        static::assertSame(12, new PlayLogStatsPeriodRepository($client)->count($this->query(['resolution' => 'month', 'time_zone' => 'UTC'])));
    }

    #[Group('units')]
    public function testAGroupIsAnInListWithPlaceholdersAndTheIdsAsParameters(): void
    {
        $client = $this->createMock(ClickHouseClientInterface::class);
        $client->expects($this->exactly(2))->method('select')->with(
            static::logicalAnd(
                static::stringContains('player_id IN ({player_0:String}, {player_1:String})'),
                static::stringContains('GROUP BY'),
                static::logicalNot(static::stringContains('player_id = ')),
                static::logicalNot(static::stringContains("a'1"))
            ),
            static::callback(static fn(array $parameters): bool => $parameters['player_0'] === "a'1" && $parameters['player_1'] === 'b' && !array_key_exists('player_id', $parameters))
        )->willReturn([['total' => 0]]);

        $query = new PageQuery('', new DateTimeImmutable('2026-10-01T00:00:00Z'), new DateTimeImmutable('2026-10-02T00:00:00Z'), 50, 0, false, ['sort' => 'content_id'], ["a'1", 'b']);

        $repository = new PlayLogStatsRepository($client);
        $repository->count($query);
        $repository->find($query);
    }

    #[Group('units')]
    public function testPeriodStatisticsOfAGroupUseTheSameCondition(): void
    {
        $client = $this->createMock(ClickHouseClientInterface::class);
        $client->expects($this->once())->method('select')->with(
            static::logicalAnd(static::stringContains('player_id IN ({player_0:String}, {player_1:String})'), static::stringContains('toUnixTimestamp(hour) AS period')),
            ['player_0' => 'a', 'player_1' => 'b', 'from' => 1790812800, 'to' => 1790899200, 'limit' => 50, 'offset' => 0]
        )->willReturn([]);

        $query = new PageQuery('', new DateTimeImmutable('2026-10-01T00:00:00Z'), new DateTimeImmutable('2026-10-02T00:00:00Z'), 50, 0, true, [], ['a', 'b']);

        static::assertSame([], new PlayLogStatsPeriodRepository($client)->find($query));
    }
}
