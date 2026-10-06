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

namespace Tests\Modules\EventLog;

use App\Modules\EventLog\EventLogFilterValidator;
use App\Framework\Validation\FieldValidator;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

class EventLogFilterValidatorTest extends TestCase
{
    private EventLogFilterValidator $validator;

    protected function setUp(): void
    {
        $this->validator = new EventLogFilterValidator(new FieldValidator());
    }

    /**
     * @param array<string,mixed> $params
     * @return array{0: array<string,string|list<string>>, 1: array<string,string>} the filters and the errors
     */
    private function check(array $params): array
    {
        $errors  = [];
        $filters = $this->validator->validate($params, $errors);

        return [$filters, $errors];
    }

    #[Group('units')]
    public function testNoFilterGivesNoFilterAndNoError(): void
    {
        static::assertSame([[], []], $this->check([]));
    }

    #[Group('units')]
    public function testMinTypeIsResolvedToThisSeverityAndAbove(): void
    {
        static::assertSame(
            [['event_type' => ['warning', 'error', 'critical', 'fatal']], []],
            $this->check(['min_type' => 'warning'])
        );
        static::assertSame([['event_type' => ['fatal']], []], $this->check(['min_type' => 'fatal']));
        static::assertSame(
            ['debug', 'informational', 'notice', 'warning', 'error', 'critical', 'fatal'],
            $this->check(['min_type' => 'debug'])[0]['event_type']
        );
    }

    #[Group('units')]
    public function testEventTypeIsAListWithoutDuplicates(): void
    {
        static::assertSame([['event_type' => ['error', 'fatal']], []], $this->check(['event_type' => 'error,fatal,error']));
        static::assertSame([['event_type' => ['notice']], []], $this->check(['event_type' => 'notice']));
    }

    #[Group('units')]
    public function testUnknownOrMalformedTypesAreRejected(): void
    {
        $oneOf = 'must be one of debug, informational, notice, warning, error, critical, fatal';

        static::assertSame($oneOf, $this->check(['min_type' => 'loud'])[1]['min_type']);
        static::assertSame($oneOf, $this->check(['min_type' => ''])[1]['min_type']);
        static::assertSame($oneOf, $this->check(['event_type' => 'error,loud'])[1]['event_type']);
        static::assertSame($oneOf, $this->check(['event_type' => 'error,'])[1]['event_type']);
        static::assertSame($oneOf, $this->check(['event_type' => 'Error'])[1]['event_type']);
        static::assertSame('must be a comma separated list of severities', $this->check(['event_type' => ['error']])[1]['event_type']);
        static::assertSame($oneOf, $this->check(['min_type' => ['error']])[1]['min_type']);
    }

    #[Group('units')]
    public function testMinTypeAndEventTypeCannotBeCombined(): void
    {
        [$filters, $errors] = $this->check(['min_type' => 'warning', 'event_type' => 'error']);

        static::assertSame([], $filters);
        static::assertSame(['event_type' => 'cannot be combined with min_type'], $errors);
    }

    #[Group('units')]
    public function testSourceAndNameAreTakenAsTheyAre(): void
    {
        static::assertSame(
            [['event_source' => 'ContentManager', 'event_name' => 'FETCH_FAILED'], []],
            $this->check(['event_source' => 'ContentManager', 'event_name' => 'FETCH_FAILED'])
        );
    }

    #[Group('units')]
    public function testSourceAndNameMustBeNonEmptyAndNotTooLong(): void
    {
        [$filters, $errors] = $this->check(['event_source' => '', 'event_name' => str_repeat('x', 129)]);

        static::assertSame([], $filters);
        static::assertSame(
            ['event_source' => 'must be a non-empty string', 'event_name' => 'must not be longer than 128 characters'],
            $errors
        );
        static::assertSame('must be a non-empty string', $this->check(['event_name' => ['a']])[1]['event_name']);
    }
}
