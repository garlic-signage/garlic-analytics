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

namespace Tests\Modules\PlayLog;

use App\Framework\Validation\FieldValidator;
use App\Modules\PlayLog\PlayLogStatsFilterValidator;
use App\Modules\PlayLog\PlayLogStatsPeriodFilterValidator;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

class PlayLogStatsFilterValidatorTest extends TestCase
{
    /**
     * @param array<string,mixed> $params
     * @return array{0: array<string,string>, 1: array<string,string>} the filters and the errors
     */
    private function content(array $params): array
    {
        $errors  = [];
        $filters = new PlayLogStatsFilterValidator(new FieldValidator())->validate($params, $errors);

        return [$filters, $errors];
    }

    /**
     * @param array<string,mixed> $params
     * @return array{0: array<string,string>, 1: array<string,string>} the filters and the errors
     */
    private function period(array $params): array
    {
        $errors  = [];
        $filters = new PlayLogStatsPeriodFilterValidator(new FieldValidator())->validate($params, $errors);

        return [$filters, $errors];
    }

    #[Group('units')]
    public function testContentStatisticsSortByContentIdWithoutFilter(): void
    {
        static::assertSame([['sort' => 'content_id'], []], $this->content([]));
    }

    #[Group('units')]
    public function testSortAndContentIdAreTaken(): void
    {
        foreach (['content_id', 'plays', 'duration_s'] as $sort)
            static::assertSame([['sort' => $sort, 'content_id' => 'spot-1'], []], $this->content(['sort' => $sort, 'content_id' => 'spot-1']));
    }

    #[Group('units')]
    public function testUnknownSortsAreRejected(): void
    {
        foreach (['best', 'Plays', '', ['plays']] as $value)
            static::assertSame(['sort' => 'must be one of content_id, plays, duration_s'], $this->content(['sort' => $value])[1]);
    }

    #[Group('units')]
    public function testContentIdMustBeANonEmptyStringNotTooLong(): void
    {
        static::assertSame(['content_id' => 'must be a non-empty string'], $this->content(['content_id' => ''])[1]);
        static::assertSame(['content_id' => 'must be a non-empty string'], $this->content(['content_id' => ['a']])[1]);
        static::assertSame(['content_id' => 'must not be longer than 128 characters'], $this->content(['content_id' => str_repeat('x', 129)])[1]);
        static::assertSame([['sort' => 'content_id', 'content_id' => str_repeat('x', 128)], []], $this->content(['content_id' => str_repeat('x', 128)]));
    }

    #[Group('units')]
    public function testPeriodStatisticsTakeResolutionTimeZoneAndContentId(): void
    {
        static::assertSame([['resolution' => 'hour', 'time_zone' => 'UTC'], []], $this->period([]));
        static::assertSame(
            [['resolution' => 'day', 'time_zone' => 'Europe/Berlin', 'content_id' => 'spot-1'], []],
            $this->period(['resolution' => 'day', 'time_zone' => 'Europe/Berlin', 'content_id' => 'spot-1'])
        );
    }

    #[Group('units')]
    public function testPeriodStatisticsCollectAllErrors(): void
    {
        static::assertSame(
            ['resolution', 'time_zone', 'content_id'],
            array_keys($this->period(['resolution' => 'x', 'time_zone' => 'y', 'content_id' => ''])[1])
        );
    }
}
