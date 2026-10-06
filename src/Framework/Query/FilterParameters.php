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

use App\Framework\Validation\FieldValidator;

/**
 * Helpers for the filter validators of the modules (FilterValidatorInterface).
 */
final readonly class FilterParameters
{
    /**
     * An optional string parameter: not given is fine, a given one must be a non-empty string up to the length.
     *
     * @param array<array-key,mixed> $params
     * @param array<string,string>   $errors collects the messages, the key is the name of the parameter
     * @return string|null the value, null if the client did not send it or it is invalid
     */
    public static function optionalString(FieldValidator $fields, array $params, string $name, int $maxLength, array &$errors): ?string
    {
        if (!array_key_exists($name, $params))
            return null;

        $value = $params[$name];
        $error = $fields->string($value, $maxLength);
        if ($error !== null)
        {
            $errors[$name] = $error;
            return null;
        }

        return is_string($value) ? $value : null;
    }
}
