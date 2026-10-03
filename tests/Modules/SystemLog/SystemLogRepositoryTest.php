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
use App\Modules\SystemLog\SystemLogEvent;
use App\Modules\SystemLog\SystemLogRepository;
use DateTimeImmutable;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

class SystemLogRepositoryTest extends TestCase
{
    #[Group('units')]
    public function testInsertBatchWritesRowsWithNullsForMissingValues(): void
    {
        $client = $this->createMock(ClickHouseClientInterface::class);
        $client->expects($this->once())
            ->method('insert')
            ->with(
                'system_log',
                [
                    ['p1', '2026-10-03 11:00:00', '2026-10-03 01:00:00', 'MEZ', 100, 40, 31, 2000, 1000, '1920x1080p-60'],
                    ['p2', '2026-10-03 11:00:00', '2026-10-03 01:00:00', 'MEZ', 100, 40, null, null, null, ''],
                ],
                ['player_id', 'reported_at', 'system_start', 'time_zone', 'disk_total', 'disk_free', 'cpu_usage', 'memory_total', 'memory_used', 'hdmi_output'],
                static::matchesRegularExpression('/^[0-9a-f]{64}$/')
            );

        $reported = new DateTimeImmutable('2026-10-03T11:00:00Z');
        $start    = new DateTimeImmutable('2026-10-03T01:00:00Z');
        new SystemLogRepository($client)->insertBatch([
            new SystemLogEvent('p1', $reported, $start, 'MEZ', 100, 40, 31, 2000, 1000, '1920x1080p-60'),
            new SystemLogEvent('p2', $reported, $start, 'MEZ', 100, 40, null, null, null, ''),
        ]);
    }
}
