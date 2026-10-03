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

namespace App\Modules\SystemLog;

use App\Framework\Exceptions\ValidationException;
use App\Framework\Ingest\IngestValidatorInterface;
use App\Framework\Validation\BatchValidator;
use DateMalformedStringException;
use DateTimeImmutable;

/**
 * Validates the body of POST /v1/systemlog and turns it into events.
 *
 * Body: {"events": [{"player_id", "reported_at", "system_start", "time_zone", "disk_total", "disk_free",
 * "cpu_usage"?, "memory_total"?, "memory_used"?, "hdmi_output"?}, ...]}
 *
 * Only the age of reported_at is checked: a player can run for longer than the retention, so system_start
 * may be old. The values are not compared with each other, a player with a measuring error keeps its report.
 *
 * @implements IngestValidatorInterface<SystemLogEvent>
 */
readonly class SystemLogValidator implements IngestValidatorInterface
{
    private const int MAX_LENGTH_ID   = 128;
    private const int MAX_LENGTH_ZONE = 64;
    private const int MAX_LENGTH_HDMI = 64;
    private const int MAX_PERCENT     = 100;

    public function __construct(private BatchValidator $batch) {}

    /**
     * @return list<SystemLogEvent>
     * @throws ValidationException
     */
    public function validate(mixed $body, DateTimeImmutable $now = new DateTimeImmutable()): array
    {
        return $this->batch->validate(
            $body,
            fn(mixed $item, string $prefix): SystemLogEvent|array => $this->validateEvent($item, $now, $prefix)
        );
    }

    /**
     * @return SystemLogEvent|array<string,string> the event or its errors
     * @throws DateMalformedStringException
     */
    private function validateEvent(mixed $item, DateTimeImmutable $now, string $prefix): SystemLogEvent|array
    {
        if (!is_array($item))
            return [$prefix => 'must be an object'];

        $errors = [];
        $valid  = $this->batch->strings($item, ['player_id'], self::MAX_LENGTH_ID, $prefix, $errors);
        $valid  = $this->batch->strings($item, ['time_zone'], self::MAX_LENGTH_ZONE, $prefix, $errors) && $valid;
        foreach (['disk_total' => true, 'disk_free' => true, 'memory_total' => false, 'memory_used' => false] as $name => $required)
            $valid = $this->batch->integer($item, $name, PHP_INT_MAX, $required, $prefix, $errors) && $valid;
        $valid  = $this->batch->integer($item, 'cpu_usage', self::MAX_PERCENT, false, $prefix, $errors) && $valid;

        $hdmi = $item['hdmi_output'] ?? '';
        if (!is_string($hdmi) || mb_strlen($hdmi) > self::MAX_LENGTH_HDMI)
        {
            $errors[$prefix . '.hdmi_output'] = 'must be a string of up to ' . self::MAX_LENGTH_HDMI . ' characters';
            $valid = false;
        }

        $reported = $this->batch->time($item['reported_at'] ?? null, $prefix . '.reported_at', $errors);
        $start    = $this->batch->time($item['system_start'] ?? null, $prefix . '.system_start', $errors);

        if (!$valid || $reported === null || $start === null)
            return $errors;

        $error = $this->batch->tooOld($reported, $now) ?? $this->batch->inFuture($reported, $now);
        if ($error !== null)
            return [$prefix . '.reported_at' => $error];

        /** @var string $hdmi */
        /** @var string $playerId */
        $playerId = $item['player_id'];
        /** @var string $timeZone */
        $timeZone = $item['time_zone'];
        /** @var int $diskTotal */
        $diskTotal = $item['disk_total'];
        /** @var int $diskFree */
        $diskFree = $item['disk_free'];
        /** @var int|null $cpu */
        $cpu = $item['cpu_usage'] ?? null;
        /** @var int|null $memoryTotal */
        $memoryTotal = $item['memory_total'] ?? null;
        /** @var int|null $memoryUsed */
        $memoryUsed = $item['memory_used'] ?? null;

        return new SystemLogEvent($playerId, $reported, $start, $timeZone, $diskTotal, $diskFree, $cpu, $memoryTotal, $memoryUsed, $hdmi);
    }
}
