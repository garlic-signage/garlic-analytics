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

use App\Framework\Exceptions\ValidationException;
use App\Framework\Validation\FieldValidator;
use App\Modules\PlayLog\PlayLogValidator;
use DateMalformedStringException;
use DateTimeImmutable;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

class PlayLogValidatorTest extends TestCase
{
    private PlayLogValidator $validator;
    private DateTimeImmutable $now;

    protected function setUp(): void
    {
        $this->validator = new PlayLogValidator(new FieldValidator(), 3, 730, 86400);
        $this->now       = new DateTimeImmutable('2026-10-03T12:00:00Z');
    }

    /** @return array<string,mixed> */
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
     * @param array<string,mixed> $body
     * @return array<string,string>
     * @throws DateMalformedStringException
     */
    private function errorsOf(array $body): array
    {
        try
        {
            $this->validator->validate($body, $this->now);
        }
        catch (ValidationException $e)
        {
            return $e->getErrors();
        }
        static::fail('ValidationException expected');
    }

    /**
     * @throws DateMalformedStringException
     */
    #[Group('units')]
    public function testValidEventsBecomeDtos(): void
    {
        $events = $this->validator->validate(['events' => [$this->event()]], $this->now);

        static::assertCount(1, $events);
        static::assertSame('player-1', $events[0]->playerId);
        static::assertSame('content-1', $events[0]->contentId);
        static::assertSame('2026-10-03 10:00:10', $events[0]->endTime->format('Y-m-d H:i:s'));
    }

    /**
     * @throws DateMalformedStringException
     */
    #[Group('units')]
    public function testBodyWithoutEventListIsRejected(): void
    {
        static::assertSame(['events' => 'must be a list of events'], $this->errorsOf([]));
        static::assertSame(['events' => 'must be a list of events'], $this->errorsOf(['events' => ['a' => $this->event()]]));
        static::assertSame(['events' => 'must be a list of events'], $this->errorsOf(['events' => 'x']));
    }

    /**
     * @throws DateMalformedStringException
     */
    #[Group('units')]
    public function testEmptyAndTooLargeBatchesAreRejected(): void
    {
        static::assertSame(['events' => 'must contain at least one event'], $this->errorsOf(['events' => []]));
        static::assertSame(
            ['events' => 'must not contain more than 3 events'],
            $this->errorsOf(['events' => array_fill(0, 4, $this->event())])
        );
    }

    /**
     * @throws DateMalformedStringException
     */
    #[Group('units')]
    public function testFractionsOfSecondsAreRejected(): void
    {
        $event               = $this->event();
        $event['start_time'] = '2026-10-03T10:00:00.500Z';

        static::assertSame(
            ['events.0.start_time' => 'must be ISO 8601 with offset, e.g. 2026-10-03T15:30:27+02:00'],
            $this->errorsOf(['events' => [$event]])
        );
    }

    /**
     * @throws DateMalformedStringException
     */
    #[Group('units')]
    public function testOffsetIsConvertedToUtc(): void
    {
        $event               = $this->event();
        $event['start_time'] = '2026-10-03T12:30:27+02:00';
        $event['end_time']   = '2026-10-03T12:30:37+02:00';

        $events = $this->validator->validate(['events' => [$event]], $this->now);

        static::assertSame('2026-10-03 10:30:27', $events[0]->startTime->format('Y-m-d H:i:s'));
        static::assertSame('2026-10-03 10:30:37', $events[0]->endTime->format('Y-m-d H:i:s'));
    }

    /**
     * @throws DateMalformedStringException
     */
    #[Group('units')]
    public function testErrorsAreCollectedPerEventAndField(): void
    {
        $broken               = $this->event();
        $broken['player_id']  = '';
        $broken['start_time'] = 'yesterday';
        unset($broken['content_id']);

        $errors = $this->errorsOf(['events' => [$this->event(), $broken, 'nope']]);

        static::assertSame([
            'events.1.player_id'  => 'must be a non-empty string',
            'events.1.content_id' => 'is required',
            'events.1.start_time' => 'must be ISO 8601 with offset, e.g. 2026-10-03T15:30:27+02:00',
            'events.2'            => 'must be an object',
        ], $errors);
    }

    /**
     * @throws DateMalformedStringException
     */
    #[Group('units')]
    public function testEndBeforeStartIsRejected(): void
    {
        $event             = $this->event();
        $event['end_time'] = '2026-10-03T09:59:59Z';

        static::assertSame(['events.0.end_time' => 'must not be before start_time'], $this->errorsOf(['events' => [$event]]));
    }

    /**
     * @throws DateMalformedStringException
     */
    #[Group('units')]
    public function testTooOldEventIsRejected(): void
    {
        $event               = $this->event();
        $event['start_time'] = '2024-10-01T00:00:00Z';
        $event['end_time']   = '2024-10-01T00:00:10Z';

        static::assertSame(['events.0.start_time' => 'must not be older than 730 days'], $this->errorsOf(['events' => [$event]]));
    }

    /**
     * @throws DateMalformedStringException
     */
    #[Group('units')]
    public function testEventInTheFutureIsRejectedBeyondTolerance(): void
    {
        $event               = $this->event();
        $event['start_time'] = '2026-10-04T13:00:00Z';
        $event['end_time']   = '2026-10-04T13:00:10Z';

        static::assertSame(['events.0.end_time' => 'must not be in the future'], $this->errorsOf(['events' => [$event]]));
    }

    /**
     * @throws DateMalformedStringException
     */
    #[Group('units')]
    public function testErrorsAreCapped(): void
    {
        $validator = new PlayLogValidator(new FieldValidator(), 1000, 730, 86400);

        try
        {
            $validator->validate(['events' => array_fill(0, 500, [])], $this->now);
            static::fail('ValidationException expected');
        }
        catch (ValidationException $e)
        {
            static::assertLessThan(500 * 5, count($e->getErrors()));
            static::assertGreaterThanOrEqual(100, count($e->getErrors()));
            static::assertLessThan(110, count($e->getErrors()));
        }
    }
}
