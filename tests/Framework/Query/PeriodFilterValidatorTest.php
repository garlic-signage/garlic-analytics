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

namespace Tests\Framework\Query;

use App\Framework\Query\PeriodFilterValidator;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

class PeriodFilterValidatorTest extends TestCase
{
    /**
     * @param array<string,mixed> $params
     * @return array{0: array<string,string>, 1: array<string,string>} the filters and the errors
     */
    private function check(array $params): array
    {
        $errors  = [];
        $filters = new PeriodFilterValidator()->validate($params, $errors);

        return [$filters, $errors];
    }

    #[Group('units')]
    public function testDefaultsAreHoursInUtc(): void
    {
        static::assertSame([['resolution' => 'hour', 'time_zone' => 'UTC'], []], $this->check([]));
    }

    #[Group('units')]
    public function testResolutionAndTimeZoneAreTaken(): void
    {
        foreach (['hour', 'day', 'month'] as $resolution)
            static::assertSame([['resolution' => $resolution, 'time_zone' => 'Europe/Berlin'], []], $this->check(['resolution' => $resolution, 'time_zone' => 'Europe/Berlin']));
    }

    #[Group('units')]
    public function testUnknownResolutionsAreRejected(): void
    {
        foreach (['week', 'year', 'Day', '', ['day']] as $value)
            static::assertSame(['resolution' => 'must be one of hour, day, month'], $this->check(['resolution' => $value])[1]);
    }

    #[Group('units')]
    public function testUnknownTimeZonesAreRejected(): void
    {
        foreach (['Mars/Base', 'europe/berlin', '', '+02:00', ['UTC']] as $value)
            static::assertSame(['time_zone' => 'must be a time zone name like Europe/Berlin'], $this->check(['time_zone' => $value])[1]);
    }

    #[Group('units')]
    public function testBothErrorsAreCollected(): void
    {
        static::assertSame(['resolution', 'time_zone'], array_keys($this->check(['resolution' => 'x', 'time_zone' => 'y'])[1]));
    }
}
