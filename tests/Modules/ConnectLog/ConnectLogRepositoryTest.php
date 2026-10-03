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
use App\Modules\ConnectLog\ConnectLogEvent;
use App\Modules\ConnectLog\ConnectLogRepository;
use DateTimeImmutable;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

class ConnectLogRepositoryTest extends TestCase
{
    private function event(string $player, string $time = '2026-10-03T10:00:00Z'): ConnectLogEvent
    {
        return new ConnectLogEvent($player, new DateTimeImmutable($time), 300);
    }

    #[Group('units')]
    public function testInsertBatchWritesAllConnectsWithOneInsert(): void
    {
        $client = $this->createMock(ClickHouseClientInterface::class);
        $client->expects($this->once())
            ->method('insert')
            ->with(
                'connect_log',
                [['p1', '2026-10-03 10:00:00', 300], ['p2', '2026-10-03 10:00:00', 300]],
                ['player_id', 'connected_at', 'refresh'],
                static::matchesRegularExpression('/^[0-9a-f]{64}$/')
            );

        new ConnectLogRepository($client)->insertBatch([$this->event('p1'), $this->event('p2')]);
    }

    #[Group('units')]
    public function testTheSameConnectSentAgainGetsTheSameToken(): void
    {
        $tokens = [];
        $client = static::createStub(ClickHouseClientInterface::class);
        $client->method('insert')->willReturnCallback(
            static function (string $table, array $rows, array $columns, ?string $token) use (&$tokens): void
            {
                $tokens[] = $token;
            }
        );
        $repository = new ConnectLogRepository($client);

        $repository->insertBatch([$this->event('p1')]);
        $repository->insertBatch([$this->event('p1')]);
        $repository->insertBatch([$this->event('p1', '2026-10-03T10:05:00Z')]);

        static::assertSame($tokens[0], $tokens[1]);
        static::assertNotSame($tokens[0], $tokens[2]);
    }
}
