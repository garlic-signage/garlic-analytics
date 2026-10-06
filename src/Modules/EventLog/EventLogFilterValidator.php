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

use App\Framework\Query\FilterValidatorInterface;
use App\Framework\Validation\FieldValidator;

/**
 * The optional filters of GET /v1/eventlog: min_type (this severity and above), event_type (a list of
 * severities), event_source and event_name (exact). min_type is resolved to the list of severities, so
 * the repository only knows event_type, event_source and event_name.
 */
readonly class EventLogFilterValidator implements FilterValidatorInterface
{
    private const int MAX_LENGTH_NAME = 128;

    public function __construct(private FieldValidator $fields) {}

    /**
     * @param array<array-key,mixed> $params
     * @param array<string,string>   $errors
     * @return array<string,string|list<string>>
     */
    public function validate(array $params, array &$errors): array
    {
        $filters = [];

        $types = $this->types($params, $errors);
        if ($types !== null)
            $filters['event_type'] = $types;

        foreach (['event_source', 'event_name'] as $name)
        {
            if (!array_key_exists($name, $params))
                continue;

            $value = $params[$name];
            $error = $this->fields->string($value, self::MAX_LENGTH_NAME);
            if ($error !== null)
                $errors[$name] = $error;
            elseif (is_string($value))
                $filters[$name] = $value;
        }

        return $filters;
    }

    /**
     * @param array<array-key,mixed> $params
     * @param array<string,string>   $errors
     * @return list<string>|null the severities to match, null if neither min_type nor event_type is given
     */
    private function types(array $params, array &$errors): ?array
    {
        $hasMin  = array_key_exists('min_type', $params);
        $hasList = array_key_exists('event_type', $params);

        if ($hasMin && $hasList)
        {
            $errors['event_type'] = 'cannot be combined with min_type';
            return null;
        }

        if ($hasMin)
            return $this->fromMinimum($params['min_type'], $errors);

        if ($hasList)
            return $this->fromList($params['event_type'], $errors);

        return null;
    }

    /**
     * @param array<string,string> $errors
     * @return list<string>|null
     */
    private function fromMinimum(mixed $value, array &$errors): ?array
    {
        $type = is_string($value) ? EventType::tryFrom($value) : null;
        if ($type === null)
        {
            $errors['min_type'] = $this->mustBeOneOf();
            return null;
        }

        $names = [];
        foreach (EventType::cases() as $case)
        {
            if ($names !== [] || $case === $type)
                $names[] = $case->value;
        }

        return $names;
    }

    /**
     * @param array<string,string> $errors
     * @return list<string>|null
     */
    private function fromList(mixed $value, array &$errors): ?array
    {
        if (!is_string($value))
        {
            $errors['event_type'] = 'must be a comma separated list of severities';
            return null;
        }

        $names = [];
        foreach (explode(',', $value) as $part)
        {
            $type = EventType::tryFrom($part);
            if ($type === null)
            {
                $errors['event_type'] = $this->mustBeOneOf();
                return null;
            }
            $names[$type->value] = $type->value; // no duplicates
        }

        return array_values($names);
    }

    private function mustBeOneOf(): string
    {
        return 'must be one of ' . implode(', ', array_map(static fn(EventType $type): string => $type->value, EventType::cases()));
    }
}
