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

namespace App\Modules\EventLog;

use App\Framework\Exceptions\ValidationException;
use App\Framework\Ingest\IngestValidatorInterface;
use App\Framework\Validation\BatchValidator;
use DateMalformedStringException;
use DateTimeImmutable;

/**
 * Validates the body of POST /v1/eventlog and turns it into events.
 *
 * Body: {"events": [{"player_id", "event_time", "event_type", "event_source", "event_name", "metadata"?}, ...]}
 * event_type is one of the names of EventType, metadata an optional object with string values.
 *
 * @implements IngestValidatorInterface<EventLogEvent>
 */
readonly class EventLogValidator implements IngestValidatorInterface
{
    private const int MAX_LENGTH_ID         = 128;
    private const int MAX_LENGTH_NAME       = 128;
    private const int MAX_META_ENTRIES      = 20;
    private const int MAX_META_KEY_LENGTH   = 64;
    private const int MAX_META_VALUE_LENGTH = 1024;

    public function __construct(private BatchValidator $batch) {}

    /**
     * @return list<EventLogEvent>
     * @throws ValidationException
     */
    public function validate(mixed $body, DateTimeImmutable $now = new DateTimeImmutable()): array
    {
        return $this->batch->validate(
            $body,
            fn(mixed $item, string $prefix): EventLogEvent|array => $this->validateEvent($item, $now, $prefix)
        );
    }

    /**
     * @return EventLogEvent|array<string,string> the event or its errors
     * @throws DateMalformedStringException
     */
    private function validateEvent(mixed $item, DateTimeImmutable $now, string $prefix): EventLogEvent|array
    {
        if (!is_array($item))
            return [$prefix => 'must be an object'];

        $errors = [];
        $valid  = $this->batch->strings($item, ['player_id'], self::MAX_LENGTH_ID, $prefix, $errors);
        $valid  = $this->batch->strings($item, ['event_source', 'event_name'], self::MAX_LENGTH_NAME, $prefix, $errors) && $valid;
        $time   = $this->batch->time($item['event_time'] ?? null, $prefix . '.event_time', $errors);
        $type   = $this->type($item['event_type'] ?? null, $prefix . '.event_type', $errors);
        $meta   = $this->metadata($item['metadata'] ?? [], $prefix . '.metadata', $errors);

        if (!$valid || $time === null || $type === null || $meta === null)
            return $errors;

        $error = $this->batch->tooOld($time, $now) ?? $this->batch->inFuture($time, $now);
        if ($error !== null)
            return [$prefix . '.event_time' => $error];

        /** @var string $playerId */
        $playerId = $item['player_id'];
        /** @var string $source */
        $source = $item['event_source'];
        /** @var string $name */
        $name = $item['event_name'];

        return new EventLogEvent($playerId, $time, $type, $source, $name, $meta);
    }

    /**
     * @param array<string,string> $errors
     */
    private function type(mixed $value, string $field, array &$errors): ?EventType
    {
        if ($value === null)
        {
            $errors[$field] = 'is required';
            return null;
        }

        $type = is_string($value) ? EventType::tryFrom($value) : null;
        if ($type === null)
            $errors[$field] = 'must be one of ' . implode(', ', array_column(EventType::cases(), 'value'));

        return $type;
    }

    /**
     * @param array<string,string> $errors
     * @return array<string,string>|null
     */
    private function metadata(mixed $value, string $field, array &$errors): ?array
    {
        if (!is_array($value) || ($value !== [] && array_is_list($value)))
        {
            $errors[$field] = 'must be an object';
            return null;
        }
        if (count($value) > self::MAX_META_ENTRIES)
        {
            $errors[$field] = 'must not contain more than ' . self::MAX_META_ENTRIES . ' entries';
            return null;
        }

        $metadata = [];
        foreach ($value as $key => $item)
        {
            $key = (string) $key; // JSON keys like "1" become integers in PHP arrays
            if ($key === '' || mb_strlen($key) > self::MAX_META_KEY_LENGTH)
                $errors[$field] = 'keys must have 1 to ' . self::MAX_META_KEY_LENGTH . ' characters';
            elseif (!is_string($item) || mb_strlen($item) > self::MAX_META_VALUE_LENGTH)
                $errors[$field . '.' . $key] = 'must be a string of up to ' . self::MAX_META_VALUE_LENGTH . ' characters';
            else
                $metadata[$key] = $item;
        }

        return count($metadata) === count($value) ? $metadata : null;
    }
}
