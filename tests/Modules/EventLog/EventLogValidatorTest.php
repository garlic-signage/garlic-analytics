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

use App\Framework\Exceptions\ValidationException;
use App\Framework\Validation\BatchValidator;
use App\Framework\Validation\FieldValidator;
use App\Modules\EventLog\EventLogValidator;
use App\Modules\EventLog\EventType;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use DateTimeImmutable;

class EventLogValidatorTest extends TestCase
{
    private EventLogValidator $validator;
    private DateTimeImmutable $now;

    protected function setUp(): void
    {
        $this->validator = new EventLogValidator(new BatchValidator(new FieldValidator(), 3, 180, 86400));
        $this->now       = new DateTimeImmutable('2026-10-03T12:00:00Z');
    }

    /** @return array<string,mixed> */
    private function event(): array
    {
        return [
            'player_id'    => 'player-1',
            'event_time'   => '2026-10-03T10:00:00Z',
            'event_type'   => 'error',
            'event_source' => 'player',
            'event_name'   => 'download_failed',
            'metadata'     => ['file' => 'a.mp4', '1' => 'x'],
        ];
    }

    /**
     * @param array<string,mixed> $event
     * @return array<string,string>
     */
    private function errorsOf(array $event): array
    {
        try
        {
            $this->validator->validate(['events' => [$event]], $this->now);
        }
        catch (ValidationException $e)
        {
            return $e->getErrors();
        }
        static::fail('ValidationException expected');
    }

    #[Group('units')]
    public function testValidEventBecomesDto(): void
    {
        $events = $this->validator->validate(['events' => [$this->event()]], $this->now);

        static::assertCount(1, $events);
        static::assertSame('player-1', $events[0]->playerId);
        static::assertSame(EventType::Error, $events[0]->eventType);
        static::assertSame('download_failed', $events[0]->eventName);
        static::assertSame('2026-10-03 10:00:00', $events[0]->eventTime->format('Y-m-d H:i:s'));
        static::assertSame(['file' => 'a.mp4', '1' => 'x'], $events[0]->metadata);
    }

    #[Group('units')]
    public function testMetadataIsOptional(): void
    {
        $event = $this->event();
        unset($event['metadata']);

        static::assertSame([], $this->validator->validate(['events' => [$event]], $this->now)[0]->metadata);
    }

    #[Group('units')]
    public function testUnknownAndMissingTypeAreRejected(): void
    {
        $event               = $this->event();
        $event['event_type'] = 'loud';
        static::assertSame(
            ['events.0.event_type' => 'must be one of debug, informational, notice, warning, error, critical, fatal'],
            $this->errorsOf($event)
        );

        unset($event['event_type']);
        static::assertSame(['events.0.event_type' => 'is required'], $this->errorsOf($event));
    }

    #[Group('units')]
    public function testErrorsAreCollectedPerField(): void
    {
        $event = $this->event();
        unset($event['event_source']);
        $event['player_id']  = '';
        $event['event_time'] = 'yesterday';

        static::assertSame([
            'events.0.player_id'    => 'must be a non-empty string',
            'events.0.event_source' => 'is required',
            'events.0.event_time'   => 'must be ISO 8601 with offset, e.g. 2026-10-03T15:30:27+02:00',
        ], $this->errorsOf($event));
    }

    #[Group('units')]
    public function testTooOldAndFutureTimesAreRejected(): void
    {
        $event               = $this->event();
        $event['event_time'] = '2026-01-01T00:00:00Z';
        static::assertSame(['events.0.event_time' => 'must not be older than 180 days'], $this->errorsOf($event));

        $event['event_time'] = '2026-10-05T00:00:00Z';
        static::assertSame(['events.0.event_time' => 'must not be in the future'], $this->errorsOf($event));
    }

    #[Group('units')]
    public function testInvalidMetadataIsRejected(): void
    {
        $event             = $this->event();
        $event['metadata'] = ['a', 'b'];
        static::assertSame(['events.0.metadata' => 'must be an object'], $this->errorsOf($event));

        $event['metadata'] = ['a' => 5];
        static::assertSame(['events.0.metadata.a' => 'must be a string of up to 1024 characters'], $this->errorsOf($event));

        $event['metadata'] = ['a' => str_repeat('x', 1025)];
        static::assertSame(['events.0.metadata.a' => 'must be a string of up to 1024 characters'], $this->errorsOf($event));

        $event['metadata'] = [str_repeat('k', 65) => 'x'];
        static::assertSame(['events.0.metadata' => 'keys must have 1 to 64 characters'], $this->errorsOf($event));

        $event['metadata'] = array_fill_keys(range('a', 'u'), 'x'); // 21 entries
        static::assertSame(['events.0.metadata' => 'must not contain more than 20 entries'], $this->errorsOf($event));
    }
}
