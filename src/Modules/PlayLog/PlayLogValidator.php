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

namespace App\Modules\PlayLog;

use App\Framework\Exceptions\ValidationException;
use App\Framework\Validation\FieldValidator;
use DateMalformedStringException;
use DateTimeImmutable;

/**
 * Validates the body of POST /v1/playlog and turns it into events.
 *
 * Body: {"events": [{"player_id", "content_id", "start_time", "end_time"}, ...]}
 *
 * All or nothing: if one event is invalid, a ValidationException with the errors of all
 * events is thrown (field "events.<index>.<name>"), so the client can fix and resend the whole batch.
 */
readonly class PlayLogValidator
{
    private const int MAX_LENGTH_ID    = 128;
    private const int MAX_ERRORS       = 100;

    public function __construct(
        private FieldValidator $fields,
        private int            $maxEvents,
        private int            $maxAgeDays,
        private int            $maxFutureSeconds
    ) {}

    /**
     * @param mixed $body
     * @param DateTimeImmutable $now
     * @return list<PlayLogEvent>
     * @throws DateMalformedStringException
     */
    public function validate(mixed $body, DateTimeImmutable $now = new DateTimeImmutable()): array
    {
        if (!is_array($body) || !isset($body['events']) || !is_array($body['events']) || !array_is_list($body['events']))
            throw new ValidationException(['events' => 'must be a list of events']);

        $items = $body['events'];
        if ($items === [])
            throw new ValidationException(['events' => 'must contain at least one event']);
        if (count($items) > $this->maxEvents)
            throw new ValidationException(['events' => 'must not contain more than ' . $this->maxEvents . ' events']);

        $errors = [];
        $events = [];
        foreach ($items as $index => $item)
        {
            $event = $this->validateEvent($item, $now, 'events.' . $index, $errors);
            if ($event !== null)
                $events[] = $event;

            if (count($errors) >= self::MAX_ERRORS)
                break;
        }

        if ($errors !== [])
            throw new ValidationException($errors);

        return $events;
    }

    /**
     * @param mixed $item
     * @param DateTimeImmutable $now
     * @param string $prefix
     * @param array<string,string> $errors collects the messages
     * @return PlayLogEvent|null
     * @throws DateMalformedStringException
     */
    private function validateEvent(mixed $item, DateTimeImmutable $now, string $prefix, array &$errors): ?PlayLogEvent
    {
        if (!is_array($item))
        {
            $errors[$prefix] = 'must be an object';
            return null;
        }

        $failed = false;
        foreach (['player_id', 'content_id'] as $name)
        {
            $error = $this->fields->string($item[$name] ?? null, self::MAX_LENGTH_ID);
            if ($error !== null)
            {
                $errors[$prefix . '.' . $name] = $error;
                $failed = true;
            }
        }

        $start = $this->parseTime($item['start_time'] ?? null, $prefix . '.start_time', $errors);
        $end   = $this->parseTime($item['end_time'] ?? null, $prefix . '.end_time', $errors);

        if ($failed || $start === null || $end === null)
            return null;

        $error = $this->checkTimes($start, $end, $now);
        if ($error !== null)
        {
            $errors[$prefix . '.' . $error[0]] = $error[1];
            return null;
        }

        /** @var string $playerId */
        $playerId = $item['player_id'];
        /** @var string $contentId */
        $contentId = $item['content_id'];

        return new PlayLogEvent(
            $playerId,
            $contentId,
            $start,
            $end
        );
    }

    /**
     * @param array<string,string> $errors
     */
    private function parseTime(mixed $value, string $field, array &$errors): ?DateTimeImmutable
    {
        if ($value === null)
        {
            $errors[$field] = 'is required';
            return null;
        }

        $time = is_string($value) ? $this->fields->parseDateTime($value) : null;
        if ($time === null)
            $errors[$field] = 'must be ISO 8601 with offset, e.g. 2026-10-03T15:30:27+02:00';

        return $time;
    }

    /**
     * @return array{string,string}|null field and message of the first problem
     * @throws DateMalformedStringException
     */
    private function checkTimes(DateTimeImmutable $start, DateTimeImmutable $end, DateTimeImmutable $now): ?array
    {
        if ($end < $start)
            return ['end_time', 'must not be before start_time'];
        if ($start < $now->modify('-' . $this->maxAgeDays . ' days'))
            return ['start_time', 'must not be older than ' . $this->maxAgeDays . ' days'];
        if ($end > $now->modify('+' . $this->maxFutureSeconds . ' seconds'))
            return ['end_time', 'must not be in the future'];

        return null;
    }
}
