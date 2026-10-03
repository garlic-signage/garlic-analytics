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
use App\Framework\Ingest\IngestValidatorInterface;
use App\Framework\Validation\BatchValidator;
use DateMalformedStringException;
use DateTimeImmutable;

/**
 * Validates the body of POST /v1/playlog and turns it into events.
 *
 * Body: {"events": [{"player_id", "content_id", "start_time", "end_time"}, ...]}
 *
 * @implements IngestValidatorInterface<PlayLogEvent>
 */
readonly class PlayLogValidator implements IngestValidatorInterface
{
    private const int MAX_LENGTH_ID = 128;

    public function __construct(private BatchValidator $batch) {}

    /**
     * @return list<PlayLogEvent>
     * @throws ValidationException
     */
    public function validate(mixed $body, DateTimeImmutable $now = new DateTimeImmutable()): array
    {
        return $this->batch->validate(
            $body,
            fn(mixed $item, string $prefix): PlayLogEvent|array => $this->validateEvent($item, $now, $prefix)
        );
    }

    /**
     * @return PlayLogEvent|array<string,string> the event or its errors
     * @throws DateMalformedStringException
     */
    private function validateEvent(mixed $item, DateTimeImmutable $now, string $prefix): PlayLogEvent|array
    {
        if (!is_array($item))
            return [$prefix => 'must be an object'];

        $errors = [];
        $valid = $this->batch->strings($item, ['player_id', 'content_id'], self::MAX_LENGTH_ID, $prefix, $errors);
        $start = $this->batch->time($item['start_time'] ?? null, $prefix . '.start_time', $errors);
        $end   = $this->batch->time($item['end_time'] ?? null, $prefix . '.end_time', $errors);

        if (!$valid || $start === null || $end === null)
            return $errors;

        $error = $this->checkTimes($start, $end, $now);
        if ($error !== null)
            return [$prefix . '.' . $error[0] => $error[1]];

        /** @var string $playerId */
        $playerId = $item['player_id'];
        /** @var string $contentId */
        $contentId = $item['content_id'];

        return new PlayLogEvent($playerId, $contentId, $start, $end);
    }

    /**
     * @return array{string,string}|null field and message of the first problem
     * @throws DateMalformedStringException
     */
    private function checkTimes(DateTimeImmutable $start, DateTimeImmutable $end, DateTimeImmutable $now): ?array
    {
        if ($end < $start)
            return ['end_time', 'must not be before start_time'];

        $error = $this->batch->tooOld($start, $now);
        if ($error !== null)
            return ['start_time', $error];

        $error = $this->batch->inFuture($end, $now);
        if ($error !== null)
            return ['end_time', $error];

        return null;
    }
}
