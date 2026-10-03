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
use App\Modules\PlayLog\PlayLogEvent;
use App\Modules\PlayLog\PlayLogRepository;
use DateMalformedStringException;
use DateTimeImmutable;
use JsonException;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

class PlayLogRepositoryTest extends TestCase
{
    /**
     * @throws DateMalformedStringException
     */
    private function event(string $player, string $end = '2026-10-03T10:00:10Z'): PlayLogEvent
    {
        return new PlayLogEvent($player, 'c1', new DateTimeImmutable('2026-10-03T10:00:00Z'), new DateTimeImmutable($end));
    }

    /**
     * @throws DateMalformedStringException
     * @throws JsonException
     */
    #[Group('units')]
    public function testInsertBatchWritesAllEventsWithOneInsert(): void
    {
        $client = $this->createMock(ClickHouseClientInterface::class);
        $client->expects($this->once())
            ->method('insert')
            ->with(
                'play_log',
                [
                    ['p1', 'c1', '2026-10-03 10:00:00', '2026-10-03 10:00:10'],
                    ['p2', 'c1', '2026-10-03 10:00:00', '2026-10-03 10:00:10'],
                ],
                ['player_id', 'content_id', 'start_time', 'end_time'],
                static::matchesRegularExpression('/^[0-9a-f]{64}$/')
            );

        new PlayLogRepository($client)->insertBatch([$this->event('p1'), $this->event('p2')]);
    }

    /**
     * @throws JsonException
     * @throws DateMalformedStringException
     */
    #[Group('units')]
    public function testSameBatchGetsTheSameTokenAndAnotherBatchDoesNot(): void
    {
        $tokens = [];
        $client = static::createStub(ClickHouseClientInterface::class);
        $client->method('insert')->willReturnCallback(
            static function (string $table, array $rows, array $columns, ?string $token) use (&$tokens): void
            {
                $tokens[] = $token;
            }
        );
        $repository = new PlayLogRepository($client);

        $repository->insertBatch([$this->event('p1'), $this->event('p2')]);
        $repository->insertBatch([$this->event('p1'), $this->event('p2')]);
        $repository->insertBatch([$this->event('p1'), $this->event('p2', '2026-10-03T10:00:11Z')]);
        $repository->insertBatch([$this->event('p2'), $this->event('p1')]);

        static::assertSame($tokens[0], $tokens[1]);
        static::assertNotSame($tokens[0], $tokens[2]);
        static::assertNotSame($tokens[0], $tokens[3]);
    }
}
