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

use App\Framework\Query\FilterValidatorInterface;
use DateTimeZone;

/**
 * The parameters of GET /v1/connectlog besides the common ones: resolution (hour, day or month, default hour)
 * and time_zone (an IANA name, default UTC). The filters always contain both, with the defaults filled in.
 * Needs nothing to check them, the DI passes a FieldValidator to every filter validator and this one ignores it.
 */
readonly class ConnectLogFilterValidator implements FilterValidatorInterface
{
    public const array RESOLUTIONS = ['hour', 'day', 'month'];

    /**
     * @param array<array-key,mixed> $params
     * @param array<string,string>   $errors
     * @return array<string,string>
     */
    public function validate(array $params, array &$errors): array
    {
        $resolution = $params['resolution'] ?? 'hour';
        if (!is_string($resolution) || !in_array($resolution, self::RESOLUTIONS, true))
        {
            $errors['resolution'] = 'must be one of ' . implode(', ', self::RESOLUTIONS);
            $resolution           = 'hour';
        }

        $timeZone = $params['time_zone'] ?? 'UTC';
        if (!is_string($timeZone) || !in_array($timeZone, DateTimeZone::listIdentifiers(), true))
        {
            $errors['time_zone'] = 'must be a time zone name like Europe/Berlin';
            $timeZone            = 'UTC';
        }

        return ['resolution' => $resolution, 'time_zone' => $timeZone];
    }
}
