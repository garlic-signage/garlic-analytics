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

namespace Tests\Modules\SystemLog;

use App\Framework\Database\ClickHouseClientInterface;
use App\Framework\Query\PageQuery;
use App\Modules\SystemLog\SystemLogQueryRepository;
use DateTimeImmutable;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

class SystemLogQueryRepositoryTest extends TestCase
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
                static::stringContains('FROM system_log'),
                static::stringContains('{player_id:String}'),
                static::logicalNot(static::stringContains("p'1"))
            ),
            ['player_id' => "p'1", 'from' => 1790812800, 'to' => 1790899200]
        )->willReturn([['total' => 7]]);

        static::assertSame(7, new SystemLogQueryRepository($client)->count($this->query()));
    }

    #[Group('units')]
    public function testFindMapsTheRowsAndPassesTheOrderLimitAndOffset(): void
    {
        $client = $this->createMock(ClickHouseClientInterface::class);
        $client->expects($this->once())->method('select')->with(
            static::logicalAnd(
                static::stringContains('ORDER BY reported_at DESC, system_start DESC, disk_total DESC, disk_free DESC'),
                static::stringContains('LIMIT {limit:UInt32} OFFSET {offset:UInt32}')
            ),
            ['player_id' => "p'1", 'from' => 1790812800, 'to' => 1790899200, 'limit' => 50, 'offset' => 100]
        )->willReturn([[
            'reported_ts' => 1790856000, 'start_ts' => 1789888323, 'time_zone' => 'Europe/Berlin',
            'disk_total' => '31457280000', 'disk_free' => '12884901888', 'cpu_usage' => 31,
            'memory_total' => '4294967296', 'memory_used' => '2147483648', 'hdmi_output' => 'HDMI-1',
        ]]);

        $items = new SystemLogQueryRepository($client)->find($this->query());

        static::assertSame([[
            'reported_at' => '2026-10-01T12:00:00Z', 'system_start' => '2026-09-20T07:12:03Z', 'time_zone' => 'Europe/Berlin',
            'disk_total' => 31457280000, 'disk_free' => 12884901888, 'cpu_usage' => 31,
            'memory_total' => 4294967296, 'memory_used' => 2147483648, 'hdmi_output' => 'HDMI-1',
        ]], $items);
    }

    #[Group('units')]
    public function testMissingMeasurementsStayNullAndNoHdmiIsAnEmptyString(): void
    {
        $client = static::createStub(ClickHouseClientInterface::class);
        $client->method('select')->willReturn([[
            'reported_ts' => 1790856000, 'start_ts' => 1789888323, 'time_zone' => 'MEZ',
            'disk_total' => 10, 'disk_free' => 5, 'cpu_usage' => null,
            'memory_total' => null, 'memory_used' => null, 'hdmi_output' => '',
        ]]);

        $item = new SystemLogQueryRepository($client)->find($this->query())[0];

        static::assertNull($item['cpu_usage']);
        static::assertNull($item['memory_total']);
        static::assertNull($item['memory_used']);
        static::assertSame('', $item['hdmi_output']);
    }

    #[Group('units')]
    public function testZeroIsAMeasurementAndNotNull(): void
    {
        $client = static::createStub(ClickHouseClientInterface::class);
        $client->method('select')->willReturn([[
            'reported_ts' => 1790856000, 'start_ts' => 1789888323, 'time_zone' => 'MEZ',
            'disk_total' => 10, 'disk_free' => 5, 'cpu_usage' => 0,
            'memory_total' => '0', 'memory_used' => '0', 'hdmi_output' => '',
        ]]);

        $item = new SystemLogQueryRepository($client)->find($this->query())[0];

        static::assertSame(0, $item['cpu_usage']);
        static::assertSame(0, $item['memory_used']);
    }

    #[Group('units')]
    public function testFindSortsAscendingIfAsked(): void
    {
        $client = $this->createMock(ClickHouseClientInterface::class);
        $client->expects($this->once())->method('select')->with(
            static::stringContains('ORDER BY reported_at ASC, system_start ASC, disk_total ASC, disk_free ASC'),
            static::anything()
        )->willReturn([]);

        static::assertSame([], new SystemLogQueryRepository($client)->find($this->query(false)));
    }
}
