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

namespace Tests\Framework\Validation;

use App\Framework\Validation\FieldValidator;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

class FieldValidatorTest extends TestCase
{
    #[Group('units')]
    public function testStringAcceptsValueWithinLimit(): void
    {
        static::assertNull(new FieldValidator()->string('player-1', 8));
    }

    #[Group('units')]
    public function testStringRejectsMissingEmptyWrongTypeAndTooLong(): void
    {
        $validator = new FieldValidator();

        static::assertSame('is required', $validator->string(null, 8));
        static::assertSame('must be a non-empty string', $validator->string('  ', 8));
        static::assertSame('must be a non-empty string', $validator->string(42, 8));
        static::assertSame('must not be longer than 8 characters', $validator->string('123456789', 8));
    }

    #[Group('units')]
    #[DataProvider('validDateTimes')]
    public function testParseDateTimeReturnsUtc(string $input, string $expected): void
    {
        $result = new FieldValidator()->parseDateTime($input);

        static::assertNotNull($result);
        static::assertSame($expected, $result->format('Y-m-d H:i:s'));
        static::assertSame('UTC', $result->getTimezone()->getName());
    }

    /** @return array<string,array{string,string}> */
    public static function validDateTimes(): array
    {
        return [
            'zulu'            => ['2026-10-03T12:00:00Z', '2026-10-03 12:00:00'],
            'positive offset' => ['2026-10-03T15:30:27+02:00', '2026-10-03 13:30:27'],
            'negative offset' => ['2026-10-03T07:00:00-05:00', '2026-10-03 12:00:00'],
        ];
    }

    #[Group('units')]
    #[DataProvider('invalidDateTimes')]
    public function testParseDateTimeRejectsInvalid(string $input): void
    {
        static::assertNull(new FieldValidator()->parseDateTime($input));
    }

    /** @return array<string,array{string}> */
    public static function invalidDateTimes(): array
    {
        return [
            'no offset'        => ['2026-10-03T12:00:00'],
            'date only'        => ['2026-10-03'],
            'space separator'  => ['2026-10-03 12:00:00Z'],
            'month 13'         => ['2026-13-03T12:00:00Z'],
            'february 30'      => ['2026-02-30T12:00:00Z'],
            'hour 25'          => ['2026-10-03T25:00:00Z'],
            'milliseconds'     => ['2026-10-03T12:00:00.123Z'],
            'microseconds'     => ['2026-10-03T12:00:00.123456Z'],
            'garbage'          => ['yesterday'],
        ];
    }
}
