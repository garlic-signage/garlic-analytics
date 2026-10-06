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

namespace Tests\Modules\EventLog;

use App\Framework\Database\ClickHouseClientInterface;
use App\Framework\Query\PageQuery;
use App\Modules\EventLog\EventLogQueryRepository;
use DateTimeImmutable;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

class EventLogQueryRepositoryTest extends TestCase
{
    /**
     * @param array<string,string|list<string>> $filters
     */
    private function query(bool $descending = true, array $filters = []): PageQuery
    {
        return new PageQuery('p\'1', new DateTimeImmutable('2026-10-01T00:00:00Z'), new DateTimeImmutable('2026-10-02T00:00:00Z'), 50, 100, $descending, $filters);
    }

    #[Group('units')]
    public function testCountUsesTypedParametersAndNoValueInTheSql(): void
    {
        $client = $this->createMock(ClickHouseClientInterface::class);
        $client->expects($this->once())->method('select')->with(
            static::logicalAnd(
                static::stringContains('count()'),
                static::stringContains('FROM event_log'),
                static::stringContains('{player_id:String}'),
                static::logicalNot(static::stringContains("p'1"))
            ),
            ['player_id' => "p'1", 'from' => 1790812800, 'to' => 1790899200]
        )->willReturn([['total' => 7]]);

        static::assertSame(7, new EventLogQueryRepository($client)->count($this->query()));
    }

    #[Group('units')]
    public function testFindMapsTheRowsAndPassesTheOrderLimitAndOffset(): void
    {
        $client = $this->createMock(ClickHouseClientInterface::class);
        $client->expects($this->once())->method('select')->with(
            static::logicalAnd(
                static::stringContains('ORDER BY event_time DESC, event_type DESC, event_source DESC, event_name DESC'),
                static::stringContains('LIMIT {limit:UInt32} OFFSET {offset:UInt32}')
            ),
            ['player_id' => "p'1", 'from' => 1790812800, 'to' => 1790899200, 'limit' => 50, 'offset' => 100]
        )->willReturn([[
            'event_ts' => 1790856000, 'event_type' => 'warning', 'event_source' => 'ContentManager',
            'event_name' => 'FETCH_FAILED', 'metadata' => ['errorMessage' => 'Host not found'],
        ]]);

        $items = new EventLogQueryRepository($client)->find($this->query());

        static::assertEquals([[
            'event_time' => '2026-10-01T12:00:00Z', 'event_type' => 'warning', 'event_source' => 'ContentManager',
            'event_name' => 'FETCH_FAILED', 'metadata' => (object) ['errorMessage' => 'Host not found'],
        ]], $items);
    }

    #[Group('units')]
    public function testEmptyMetadataIsAnObject(): void
    {
        $client = static::createStub(ClickHouseClientInterface::class);
        $client->method('select')->willReturn([[
            'event_ts' => 1790856000, 'event_type' => 'error', 'event_source' => 's', 'event_name' => 'n', 'metadata' => [],
        ]]);

        $items = new EventLogQueryRepository($client)->find($this->query());

        static::assertSame('{}', json_encode($items[0]['metadata'], JSON_THROW_ON_ERROR));
    }

    #[Group('units')]
    public function testFindSortsAscendingIfAsked(): void
    {
        $client = $this->createMock(ClickHouseClientInterface::class);
        $client->expects($this->once())->method('select')->with(
            static::stringContains('ORDER BY event_time ASC, event_type ASC, event_source ASC, event_name ASC'),
            static::anything()
        )->willReturn([]);

        static::assertSame([], new EventLogQueryRepository($client)->find($this->query(false)));
    }

    #[Group('units')]
    public function testWithoutFiltersTheSqlHasNoFilterCondition(): void
    {
        $client = $this->createMock(ClickHouseClientInterface::class);
        $client->expects($this->once())->method('select')->with(
            static::logicalNot(static::logicalOr(
                static::stringContains('event_type IN'),
                static::stringContains('event_source ='),
                static::stringContains('event_name =')
            )),
            static::anything()
        )->willReturn([['total' => 0]]);

        new EventLogQueryRepository($client)->count($this->query());
    }

    #[Group('units')]
    public function testCountAppliesAllFiltersAsTypedParameters(): void
    {
        $client = $this->createMock(ClickHouseClientInterface::class);
        $client->expects($this->once())->method('select')->with(
            static::logicalAnd(
                static::stringContains('AND event_type IN ({event_type_0:String}, {event_type_1:String})'),
                static::stringContains('AND event_source = {event_source:String}'),
                static::stringContains('AND event_name = {event_name:String}'),
                static::logicalNot(static::stringContains('Content'))
            ),
            [
                'player_id' => "p'1", 'from' => 1790812800, 'to' => 1790899200,
                'event_type_0' => 'error', 'event_type_1' => 'fatal',
                'event_source' => "Content'Manager", 'event_name' => 'FETCH_FAILED',
            ]
        )->willReturn([['total' => 2]]);

        $filters = ['event_type' => ['error', 'fatal'], 'event_source' => "Content'Manager", 'event_name' => 'FETCH_FAILED'];

        static::assertSame(2, new EventLogQueryRepository($client)->count($this->query(true, $filters)));
    }

    #[Group('units')]
    public function testFindAppliesTheSameFiltersAsCount(): void
    {
        $client = $this->createMock(ClickHouseClientInterface::class);
        $client->expects($this->once())->method('select')->with(
            static::logicalAnd(
                static::stringContains('AND event_type IN ({event_type_0:String})'),
                static::stringContains('LIMIT {limit:UInt32} OFFSET {offset:UInt32}')
            ),
            ['player_id' => "p'1", 'from' => 1790812800, 'to' => 1790899200, 'event_type_0' => 'fatal', 'limit' => 50, 'offset' => 100]
        )->willReturn([]);

        static::assertSame([], new EventLogQueryRepository($client)->find($this->query(true, ['event_type' => ['fatal']])));
    }
}
