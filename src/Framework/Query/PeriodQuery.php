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

/**
 * The SQL and the labels of the periods of an hourly aggregate (column "hour", UTC), shared by the read
 * endpoints of the modules. The resolution and the time zone are the filters of PeriodFilterValidator.
 *
 * A period belongs to a time range by the start of its hours: an hour counts if it starts at from or later
 * and before to.
 */
final readonly class PeriodQuery
{
    /**
     * The expression of the period for GROUP BY and ORDER BY: the Unix time for hours (sorts right, formatted by
     * label()), the local date ("2026-10-03") for days and the local month ("2026-10") for months, both sort
     * right as strings. Only fixed strings, the time zone is a parameter (see parameters()).
     */
    public static function expression(PageQuery $query): string
    {
        return match (self::resolution($query))
        {
            'day'   => "formatDateTime(hour, '%Y-%m-%d', {time_zone:String})",
            'month' => "formatDateTime(hour, '%Y-%m', {time_zone:String})",
            default => 'toUnixTimestamp(hour)',
        };
    }

    /**
     * The period of a result row as the API shows it: hours as ISO 8601 in UTC, days and months as they are.
     */
    public static function label(PageQuery $query, mixed $value): string
    {
        if (self::resolution($query) === 'hour')
            return gmdate('Y-m-d\TH:i:s\Z', is_int($value) || is_string($value) ? (int) $value : 0);

        return is_string($value) ? $value : '';
    }

    /**
     * The time zone is a parameter of the SQL only if a day or a month needs it.
     *
     * @return array<string,string>
     */
    public static function parameters(PageQuery $query): array
    {
        $timeZone = $query->filters['time_zone'] ?? 'UTC';

        return self::resolution($query) !== 'hour' && is_string($timeZone) ? ['time_zone' => $timeZone] : [];
    }

    public static function resolution(PageQuery $query): string
    {
        $resolution = $query->filters['resolution'] ?? 'hour';

        return is_string($resolution) ? $resolution : 'hour';
    }
}
