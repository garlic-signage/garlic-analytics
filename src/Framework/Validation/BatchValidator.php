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

namespace App\Framework\Validation;

use App\Framework\Exceptions\ValidationException;
use DateMalformedStringException;
use DateTimeImmutable;

/**
 * The parts of the validation of an ingest body {"events": [...]} which are the same for all event types.
 *
 * All or nothing: if one event is invalid, a ValidationException with the errors of all
 * events is thrown (field "events.<index>.<name>"), so the client can fix and resend the whole batch.
 */
readonly class BatchValidator
{
    private const int MAX_ERRORS = 100;

    public function __construct(
        private FieldValidator $fields,
        private int            $maxEvents,
        private int            $maxAgeDays,
        private int            $maxFutureSeconds
    ) {}

    /**
     * Checks the list around the events and runs $validateEvent for every one of them.
     *
     * @template T of object
     * @param callable(mixed, string): (T|array<string,string>) $validateEvent gets the item and its field prefix,
     *        returns the event or the errors found
     * @return list<T>
     * @throws ValidationException
     */
    public function validate(mixed $body, callable $validateEvent): array
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
            $event = $validateEvent($item, 'events.' . $index);
            if (is_array($event))
                $errors = [...$errors, ...$event];
            else
                $events[] = $event;

            if (count($errors) >= self::MAX_ERRORS)
                break;
        }

        if ($errors !== [])
            throw new ValidationException($errors);

        return $events;
    }

    /**
     * Checks required string fields of an event.
     *
     * @param array<array-key,mixed> $item
     * @param list<string>           $names
     * @param array<string,string>   $errors collects the messages
     * @return bool true if all fields are valid
     */
    public function strings(array $item, array $names, int $maxLength, string $prefix, array &$errors): bool
    {
        $valid = true;
        foreach ($names as $name)
        {
            $error = $this->fields->string($item[$name] ?? null, $maxLength);
            if ($error !== null)
            {
                $errors[$prefix . '.' . $name] = $error;
                $valid = false;
            }
        }

        return $valid;
    }

    /**
     * Checks an integer field of an event, 0 up to $max. An optional field may be missing or null.
     *
     * @param array<array-key,mixed> $item
     * @param array<string,string>   $errors collects the messages
     * @return bool true if the field is valid
     */
    public function integer(array $item, string $name, int $max, bool $required, string $prefix, array &$errors): bool
    {
        $value = $item[$name] ?? null;
        if ($value === null && !$required)
            return true;

        $error = $this->fields->integer($value, 0, $max);
        if ($error !== null)
            $errors[$prefix . '.' . $name] = $error;

        return $error === null;
    }

    /**
     * @param array<string,string> $errors collects the messages
     */
    public function time(mixed $value, string $field, array &$errors): ?DateTimeImmutable
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
     * @throws DateMalformedStringException
     */
    public function tooOld(DateTimeImmutable $time, DateTimeImmutable $now): ?string
    {
        return $time < $now->modify('-' . $this->maxAgeDays . ' days')
            ? 'must not be older than ' . $this->maxAgeDays . ' days'
            : null;
    }

    /**
     * @throws DateMalformedStringException
     */
    public function inFuture(DateTimeImmutable $time, DateTimeImmutable $now): ?string
    {
        return $time > $now->modify('+' . $this->maxFutureSeconds . ' seconds')
            ? 'must not be in the future'
            : null;
    }
}
