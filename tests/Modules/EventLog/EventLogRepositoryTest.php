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
use App\Modules\EventLog\EventLogEvent;
use App\Modules\EventLog\EventLogRepository;
use App\Modules\EventLog\EventType;
use DateTimeImmutable;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

class EventLogRepositoryTest extends TestCase
{
    private function event(string $player): EventLogEvent
    {
        return new EventLogEvent(
            $player,
            new DateTimeImmutable('2026-10-03T10:00:00Z'),
            EventType::Warning,
            'player',
            'low_disk',
            ['free' => '100']
        );
    }

    #[Group('units')]
    public function testInsertBatchWritesAllEventsWithOneInsert(): void
    {
        $client = $this->createMock(ClickHouseClientInterface::class);
        $client->expects($this->once())
            ->method('insert')
            ->with(
                'event_log',
                [
                    ['p1', '2026-10-03 10:00:00', 'warning', 'player', 'low_disk', ['free' => '100']],
                    ['p2', '2026-10-03 10:00:00', 'warning', 'player', 'low_disk', ['free' => '100']],
                ],
                ['player_id', 'event_time', 'event_type', 'event_source', 'event_name', 'metadata'],
                static::matchesRegularExpression('/^[0-9a-f]{64}$/')
            );

        new EventLogRepository($client)->insertBatch([$this->event('p1'), $this->event('p2')]);
    }
}
