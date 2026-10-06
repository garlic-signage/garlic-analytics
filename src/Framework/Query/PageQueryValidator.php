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

namespace App\Framework\Query;

use App\Framework\Exceptions\ValidationException;
use App\Framework\Validation\FieldValidator;
use DateTimeImmutable;

/**
 * Validates the query parameters of GET /v1/<module>:
 *
 * - player_id: required
 * - from, to: required, ISO 8601 with offset, from is included, to is excluded and must be after from,
 *   and at most $maxRangeSeconds after from if the module sets a maximum for the range
 * - limit: optional, 1 up to the maximum of the module, default of the module
 * - offset: optional, 0 or more
 * - order: optional, "asc" or "desc" (default) by time
 *
 * All errors are collected, the key is the name of the parameter.
 */
readonly class PageQueryValidator
{
    private const int MAX_LENGTH_ID = 128;
    private const int MAX_TIMESTAMP = 4294967295;   // ClickHouse DateTime: 1970 up to 2106
    private const int MAX_OFFSET    = 4294967295;

    public function __construct(
        private FieldValidator $fields,
        private int            $defaultLimit,
        private int            $maxLimit,
        private ?FilterValidatorInterface $filterValidator = null,
        private ?int           $maxRangeSeconds = null
    ) {}

    /**
     * @param array<array-key,mixed> $params the query parameters of the request
     * @throws ValidationException
     */
    public function validate(array $params): PageQuery
    {
        $errors = [];

        $playerError = $this->fields->string($params['player_id'] ?? null, self::MAX_LENGTH_ID);
        if ($playerError !== null)
            $errors['player_id'] = $playerError;

        $from = $this->time($params['from'] ?? null, 'from', $errors);
        $to   = $this->time($params['to'] ?? null, 'to', $errors);
        if ($from !== null && $to !== null)
        {
            if ($to <= $from)
                $errors['to'] = 'must be after from';
            elseif ($this->maxRangeSeconds !== null && $to->getTimestamp() - $from->getTimestamp() > $this->maxRangeSeconds)
                $errors['to'] = 'must not be more than ' . $this->describeRange($this->maxRangeSeconds) . ' after from';
        }

        $limit  = $this->number($params, 'limit', 1, $this->maxLimit, $this->defaultLimit, $errors);
        $offset = $this->number($params, 'offset', 0, self::MAX_OFFSET, 0, $errors);

        $order = $params['order'] ?? 'desc';
        if ($order !== 'asc' && $order !== 'desc')
            $errors['order'] = 'must be asc or desc';

        $filters = $this->filterValidator?->validate($params, $errors) ?? [];

        if ($errors !== [] || $from === null || $to === null)
            throw new ValidationException($errors);

        /** @var string $playerId */
        $playerId = $params['player_id'];

        return new PageQuery($playerId, $from, $to, $limit ?? $this->defaultLimit, $offset ?? 0, $order === 'desc', $filters);
    }

    private function describeRange(int $seconds): string
    {
        return $seconds % 3600 === 0 ? ($seconds / 3600) . ' hours' : $seconds . ' seconds';
    }

    /**
     * @param array<string,string> $errors collects the messages
     */
    private function time(mixed $value, string $name, array &$errors): ?DateTimeImmutable
    {
        if ($value === null)
        {
            $errors[$name] = 'is required';
            return null;
        }

        $time = is_string($value) ? $this->fields->parseDateTime($value) : null;
        if ($time === null)
        {
            $errors[$name] = 'must be ISO 8601 with offset, e.g. 2026-10-03T15:30:27+02:00';
            return null;
        }

        if ($time->getTimestamp() < 0 || $time->getTimestamp() > self::MAX_TIMESTAMP)
        {
            $errors[$name] = 'must be between 1970 and 2106';
            return null;
        }

        return $time;
    }

    /**
     * An optional whole number from the query string.
     *
     * @param array<array-key,mixed> $params
     * @param array<string,string>   $errors collects the messages
     */
    private function number(array $params, string $name, int $min, int $max, int $default, array &$errors): ?int
    {
        $value = $params[$name] ?? null;
        if ($value === null)
            return $default;

        if (!is_string($value) || preg_match('/^\d{1,10}$/', $value) !== 1)
        {
            $errors[$name] = 'must be an integer';
            return null;
        }

        $number = (int) $value;
        if ($number < $min || $number > $max)
        {
            $errors[$name] = 'must be between ' . $min . ' and ' . $max;
            return null;
        }

        return $number;
    }
}
