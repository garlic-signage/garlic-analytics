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

use DateTimeImmutable;
use DateTimeZone;

/**
 * Checks single values of a request.
 *
 * The check methods return an error message or null if the value is valid,
 * so the caller can collect the messages per field.
 */
readonly class FieldValidator
{
    private const string PATTERN_DATE_TIME = '/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}(Z|[+-]\d{2}:\d{2})$/';

    public function string(mixed $value, int $maxLength): ?string
    {
        if ($value === null)
            return 'is required';

        if (!is_string($value) || trim($value) === '')
            return 'must be a non-empty string';

        if (mb_strlen($value) > $maxLength)
            return 'must not be longer than ' . $maxLength . ' characters';

        return null;
    }

    /**
     * Checks a JSON integer (a string like "5" is no integer).
     */
    public function integer(mixed $value, int $min, int $max): ?string
    {
        if ($value === null)
            return 'is required';

        if (!is_int($value))
            return 'must be an integer';

        if ($value < $min)
            return 'must not be less than ' . $min;

        if ($value > $max)
            return 'must not be greater than ' . $max;

        return null;
    }

    /**
     * Parses ISO 8601 to the second with an offset ("2026-10-03T15:30:27+02:00", "2026-10-03T13:30:27Z")
     * and returns it in UTC. Fractions of seconds are not accepted. Returns null if the value is invalid.
     */
    public function parseDateTime(string $value): ?DateTimeImmutable
    {
        if (preg_match(self::PATTERN_DATE_TIME, $value) !== 1)
            return null;

        $dateTime = DateTimeImmutable::createFromFormat('Y-m-d\TH:i:sP', $value);
        if ($dateTime === false || DateTimeImmutable::getLastErrors() !== false) // e.g. month 13 is only a warning
            return null;

        return $dateTime->setTimezone(new DateTimeZone('UTC'));
    }
}
