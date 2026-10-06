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

use App\Framework\Query\FilterParameters;
use App\Framework\Validation\FieldValidator;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

class FilterParametersTest extends TestCase
{
    #[Group('units')]
    public function testAParameterThatIsNotSentIsNotAnError(): void
    {
        $errors = [];

        static::assertNull(FilterParameters::optionalString(new FieldValidator(), ['other' => 'x'], 'name', 10, $errors));
        static::assertSame([], $errors);
    }

    #[Group('units')]
    public function testAValidStringIsReturned(): void
    {
        $errors = [];

        static::assertSame('spot-1', FilterParameters::optionalString(new FieldValidator(), ['name' => 'spot-1'], 'name', 10, $errors));
        static::assertSame([], $errors);
    }

    #[Group('units')]
    public function testInvalidValuesAreCollectedUnderTheNameOfTheParameter(): void
    {
        $fields = new FieldValidator();

        foreach ([['', 'must be a non-empty string'], ['toolongvalue', 'must not be longer than 10 characters']] as [$value, $message])
        {
            $errors = [];
            static::assertNull(FilterParameters::optionalString($fields, ['name' => $value], 'name', 10, $errors));
            static::assertSame(['name' => $message], $errors);
        }

        $errors = [];
        static::assertNull(FilterParameters::optionalString($fields, ['name' => ['a']], 'name', 10, $errors));
        static::assertSame(['name' => 'must be a non-empty string'], $errors);
    }
}
