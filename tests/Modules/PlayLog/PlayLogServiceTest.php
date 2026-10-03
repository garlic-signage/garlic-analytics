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
use App\Framework\Exceptions\ValidationException;
use App\Framework\Validation\BatchValidator;
use App\Framework\Validation\FieldValidator;
use App\Modules\PlayLog\PlayLogRepository;
use App\Modules\PlayLog\PlayLogService;
use App\Modules\PlayLog\PlayLogValidator;
use DateMalformedStringException;
use JsonException;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

class PlayLogServiceTest extends TestCase
{
    private function service(ClickHouseClientInterface $client): PlayLogService
    {
        return new PlayLogService(
            new PlayLogValidator(new BatchValidator(new FieldValidator(), 10, 36500, 86400)),
            new PlayLogRepository($client)
        );
    }

    /** @return array<string,string> */
    private function event(): array
    {
        return [
            'player_id'  => 'player-1',
            'content_id' => 'content-1',
            'start_time' => '2026-10-03T10:00:00Z',
            'end_time'   => '2026-10-03T10:00:10Z',
        ];
    }

    /**
     * @throws DateMalformedStringException
     * @throws JsonException
     */
    #[Group('units')]
    public function testIngestStoresValidEventsAndReturnsTheirCount(): void
    {
        $client = $this->createMock(ClickHouseClientInterface::class);
        $client->expects($this->once())->method('insert')->with('play_log', static::countOf(2), static::anything(), static::isString());

        static::assertSame(2, $this->service($client)->ingest(['events' => [$this->event(), $this->event()]]));
    }

    /**
     * @throws DateMalformedStringException
     * @throws JsonException
     */
    #[Group('units')]
    public function testIngestDoesNotStoreAnythingIfOneEventIsInvalid(): void
    {
        $client = $this->createMock(ClickHouseClientInterface::class);
        $client->expects($this->never())->method('insert');

        $this->expectException(ValidationException::class);

        $this->service($client)->ingest(['events' => [$this->event(), ['player_id' => 'x']]]);
    }
}
