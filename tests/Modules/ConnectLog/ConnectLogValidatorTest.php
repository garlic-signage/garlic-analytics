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

use App\Framework\Exceptions\ValidationException;
use App\Framework\Validation\BatchValidator;
use App\Framework\Validation\FieldValidator;
use App\Modules\ConnectLog\ConnectLogValidator;
use DateTimeImmutable;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

class ConnectLogValidatorTest extends TestCase
{
    private ConnectLogValidator $validator;
    private DateTimeImmutable $now;

    protected function setUp(): void
    {
        $this->validator = new ConnectLogValidator(new BatchValidator(new FieldValidator(), 3, 90, 86400));
        $this->now       = new DateTimeImmutable('2026-10-03T12:00:00Z');
    }

    /** @return array<string,mixed> */
    private function connect(): array
    {
        return ['player_id' => 'player-1', 'connected_at' => '2026-10-03T13:00:00+02:00', 'refresh' => 300];
    }

    /**
     * @param array<string,mixed> $connect
     * @return array<string,string>
     */
    private function errorsOf(array $connect): array
    {
        try
        {
            $this->validator->validate(['events' => [$connect]], $this->now);
        }
        catch (ValidationException $e)
        {
            return $e->getErrors();
        }
        static::fail('ValidationException expected');
    }

    #[Group('units')]
    public function testValidConnectBecomesDto(): void
    {
        $events = $this->validator->validate(['events' => [$this->connect()]], $this->now);

        static::assertCount(1, $events);
        static::assertSame('player-1', $events[0]->playerId);
        static::assertSame('2026-10-03 11:00:00', $events[0]->connectedAt->format('Y-m-d H:i:s'));
        static::assertSame(300, $events[0]->refresh);
    }

    #[Group('units')]
    public function testSeveralConnectsInOneRequestAreAccepted(): void
    {
        static::assertCount(3, $this->validator->validate(['events' => array_fill(0, 3, $this->connect())], $this->now));
    }

    #[Group('units')]
    public function testRequiredValuesAreChecked(): void
    {
        static::assertSame([
            'events.0.player_id'    => 'is required',
            'events.0.refresh'      => 'is required',
            'events.0.connected_at' => 'is required',
        ], $this->errorsOf([]));
    }

    #[Group('units')]
    public function testRefreshMustBeBetweenOneAndOneDay(): void
    {
        $connect            = $this->connect();
        $connect['refresh'] = 0;
        static::assertSame(['events.0.refresh' => 'must not be less than 1'], $this->errorsOf($connect));

        $connect['refresh'] = 86401;
        static::assertSame(['events.0.refresh' => 'must not be greater than 86400'], $this->errorsOf($connect));

        $connect['refresh'] = '300';
        static::assertSame(['events.0.refresh' => 'must be an integer'], $this->errorsOf($connect));

        $connect['refresh'] = 86400;
        static::assertCount(1, $this->validator->validate(['events' => [$connect]], $this->now));
    }

    #[Group('units')]
    public function testTooOldAndFutureTimesAreRejected(): void
    {
        $connect                 = $this->connect();
        $connect['connected_at'] = '2026-06-01T00:00:00Z';
        static::assertSame(['events.0.connected_at' => 'must not be older than 90 days'], $this->errorsOf($connect));

        $connect['connected_at'] = '2026-10-05T00:00:00Z';
        static::assertSame(['events.0.connected_at' => 'must not be in the future'], $this->errorsOf($connect));
    }
}
