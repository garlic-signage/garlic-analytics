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

use App\Framework\Query\PageQuery;
use App\Framework\Query\PeriodQuery;
use DateTimeImmutable;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

class PeriodQueryTest extends TestCase
{
    /**
     * @param array<string,string|list<string>> $filters
     */
    private function query(array $filters): PageQuery
    {
        return new PageQuery('p1', new DateTimeImmutable('2026-10-01T00:00:00Z'), new DateTimeImmutable('2026-10-02T00:00:00Z'), 10, 0, true, $filters);
    }

    #[Group('units')]
    public function testExpressionAndParametersFollowTheResolution(): void
    {
        $hour  = $this->query(['resolution' => 'hour', 'time_zone' => 'Europe/Berlin']);
        $day   = $this->query(['resolution' => 'day', 'time_zone' => 'Europe/Berlin']);
        $month = $this->query(['resolution' => 'month', 'time_zone' => 'Asia/Tokyo']);

        static::assertSame('toUnixTimestamp(hour)', PeriodQuery::expression($hour));
        static::assertSame([], PeriodQuery::parameters($hour));
        static::assertSame("formatDateTime(hour, '%Y-%m-%d', {time_zone:String})", PeriodQuery::expression($day));
        static::assertSame(['time_zone' => 'Europe/Berlin'], PeriodQuery::parameters($day));
        static::assertSame("formatDateTime(hour, '%Y-%m', {time_zone:String})", PeriodQuery::expression($month));
        static::assertSame(['time_zone' => 'Asia/Tokyo'], PeriodQuery::parameters($month));
    }

    #[Group('units')]
    public function testWithoutFiltersItIsHoursInUtc(): void
    {
        $query = $this->query([]);

        static::assertSame('hour', PeriodQuery::resolution($query));
        static::assertSame('toUnixTimestamp(hour)', PeriodQuery::expression($query));
        static::assertSame([], PeriodQuery::parameters($query));
    }

    #[Group('units')]
    public function testLabelsAreUtcForHoursAndTheLocalPeriodOtherwise(): void
    {
        static::assertSame('2026-10-01T12:00:00Z', PeriodQuery::label($this->query(['resolution' => 'hour']), 1790856000));
        static::assertSame('2026-10-01T12:00:00Z', PeriodQuery::label($this->query([]), '1790856000'));
        static::assertSame('2026-10-03', PeriodQuery::label($this->query(['resolution' => 'day']), '2026-10-03'));
        static::assertSame('2026-10', PeriodQuery::label($this->query(['resolution' => 'month']), '2026-10'));
    }
}
