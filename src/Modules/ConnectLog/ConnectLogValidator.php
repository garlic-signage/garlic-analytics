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

namespace App\Modules\ConnectLog;

use App\Framework\Exceptions\ValidationException;
use App\Framework\Ingest\IngestValidatorInterface;
use App\Framework\Validation\BatchValidator;
use DateMalformedStringException;
use DateTimeImmutable;

/**
 * Validates the body of POST /v1/connectlog and turns it into events.
 *
 * Body: {"events": [{"player_id", "connected_at", "refresh"}, ...]}
 * The sender may collect connects and send them together.
 *
 * @implements IngestValidatorInterface<ConnectLogEvent>
 */
readonly class ConnectLogValidator implements IngestValidatorInterface
{
    private const int MAX_LENGTH_ID = 128;
    private const int MIN_REFRESH   = 1;
    private const int MAX_REFRESH   = 86400;

    public function __construct(private BatchValidator $batch) {}

    /**
     * @return list<ConnectLogEvent>
     * @throws ValidationException
     */
    public function validate(mixed $body, DateTimeImmutable $now = new DateTimeImmutable()): array
    {
        return $this->batch->validate(
            $body,
            fn(mixed $item, string $prefix): ConnectLogEvent|array => $this->validateEvent($item, $now, $prefix)
        );
    }

    /**
     * @return ConnectLogEvent|array<string,string> the event or its errors
     * @throws DateMalformedStringException
     */
    private function validateEvent(mixed $item, DateTimeImmutable $now, string $prefix): ConnectLogEvent|array
    {
        if (!is_array($item))
            return [$prefix => 'must be an object'];

        $errors = [];
        $valid  = $this->batch->strings($item, ['player_id'], self::MAX_LENGTH_ID, $prefix, $errors);
        $valid  = $this->batch->integer($item, 'refresh', self::MAX_REFRESH, true, $prefix, $errors, self::MIN_REFRESH) && $valid;
        $time   = $this->batch->time($item['connected_at'] ?? null, $prefix . '.connected_at', $errors);

        if (!$valid || $time === null)
            return $errors;

        $error = $this->batch->tooOld($time, $now) ?? $this->batch->inFuture($time, $now);
        if ($error !== null)
            return [$prefix . '.connected_at' => $error];

        /** @var string $playerId */
        $playerId = $item['player_id'];
        /** @var int $refresh */
        $refresh = $item['refresh'];

        return new ConnectLogEvent($playerId, $time, $refresh);
    }
}
