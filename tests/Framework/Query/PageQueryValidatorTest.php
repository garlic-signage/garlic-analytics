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

use App\Framework\Exceptions\ValidationException;
use App\Framework\Query\FilterValidatorInterface;
use App\Framework\Query\PageQueryValidator;
use App\Framework\Validation\FieldValidator;
use DateTimeImmutable;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

class PageQueryValidatorTest extends TestCase
{
    private PageQueryValidator $validator;

    protected function setUp(): void
    {
        $this->validator = new PageQueryValidator(new FieldValidator(), 100, 1000);
    }

    /**
     * @param array<string,mixed> $override
     * @return array<string,mixed>
     */
    private function params(array $override = []): array
    {
        return [...['player_id' => 'p1', 'from' => '2026-10-01T00:00:00Z', 'to' => '2026-10-02T00:00:00+02:00'], ...$override];
    }

    /**
     * @param array<string,mixed> $params
     * @return array<string,string>
     */
    private function errorsOf(array $params): array
    {
        try
        {
            $this->validator->validate($params);
        }
        catch (ValidationException $e)
        {
            return $e->getErrors();
        }

        static::fail('ValidationException expected');
    }

    #[Group('units')]
    public function testValidParametersGetTheDefaults(): void
    {
        $query = $this->validator->validate($this->params());

        static::assertSame('p1', $query->playerId);
        static::assertSame(new DateTimeImmutable('2026-10-01T00:00:00Z')->getTimestamp(), $query->from->getTimestamp());
        static::assertSame(new DateTimeImmutable('2026-10-01T22:00:00Z')->getTimestamp(), $query->to->getTimestamp()); // +02:00
        static::assertSame(100, $query->limit);
        static::assertSame(0, $query->offset);
        static::assertTrue($query->descending);
    }

    #[Group('units')]
    public function testLimitOffsetAndOrderAreTaken(): void
    {
        $query = $this->validator->validate($this->params(['limit' => '1000', 'offset' => '250', 'order' => 'asc']));

        static::assertSame(1000, $query->limit);
        static::assertSame(250, $query->offset);
        static::assertFalse($query->descending);
    }

    #[Group('units')]
    public function testMissingRequiredParametersAreCollected(): void
    {
        $errors = $this->errorsOf([]);

        static::assertSame(['player_id', 'from', 'to'], array_keys($errors));
        static::assertSame('is required', $errors['from']);
    }

    #[Group('units')]
    public function testBlankPlayerIdIsRejected(): void
    {
        static::assertArrayHasKey('player_id', $this->errorsOf($this->params(['player_id' => '  '])));
        static::assertArrayHasKey('player_id', $this->errorsOf($this->params(['player_id' => str_repeat('a', 129)])));
        static::assertArrayHasKey('player_id', $this->errorsOf($this->params(['player_id' => ['a']])));
    }

    #[Group('units')]
    public function testTimesMustBeIso8601WithOffset(): void
    {
        $errors = $this->errorsOf($this->params(['from' => '2026-10-01', 'to' => '2026-10-02T00:00:00.5Z']));

        static::assertArrayHasKey('from', $errors);
        static::assertArrayHasKey('to', $errors);
    }

    #[Group('units')]
    public function testToMustBeAfterFrom(): void
    {
        static::assertSame(['to' => 'must be after from'], $this->errorsOf($this->params(['to' => '2026-10-01T00:00:00Z'])));
        static::assertSame(['to' => 'must be after from'], $this->errorsOf($this->params(['to' => '2026-09-30T00:00:00Z'])));
    }

    #[Group('units')]
    public function testTimesOutsideOfTheClickHouseRangeAreRejected(): void
    {
        static::assertArrayHasKey('from', $this->errorsOf($this->params(['from' => '1960-01-01T00:00:00Z'])));
        static::assertArrayHasKey('to', $this->errorsOf($this->params(['to' => '2200-01-01T00:00:00Z'])));
    }

    #[Group('units')]
    public function testLimitMustBeBetweenOneAndTheMaximum(): void
    {
        static::assertArrayHasKey('limit', $this->errorsOf($this->params(['limit' => '0'])));
        static::assertArrayHasKey('limit', $this->errorsOf($this->params(['limit' => '1001'])));
        static::assertArrayHasKey('limit', $this->errorsOf($this->params(['limit' => 'ten'])));
        static::assertArrayHasKey('limit', $this->errorsOf($this->params(['limit' => '-5'])));
        static::assertArrayHasKey('limit', $this->errorsOf($this->params(['limit' => ['1']])));
    }

    #[Group('units')]
    public function testOffsetMustBeAWholeNumberWithinTheRange(): void
    {
        static::assertArrayHasKey('offset', $this->errorsOf($this->params(['offset' => '-1'])));
        static::assertArrayHasKey('offset', $this->errorsOf($this->params(['offset' => '1.5'])));
        static::assertArrayHasKey('offset', $this->errorsOf($this->params(['offset' => '4294967296'])));
        static::assertArrayHasKey('offset', $this->errorsOf($this->params(['offset' => '99999999999'])));
    }

    #[Group('units')]
    public function testOrderMustBeAscOrDesc(): void
    {
        static::assertSame(['order' => 'must be asc or desc'], $this->errorsOf($this->params(['order' => 'up'])));
        static::assertArrayHasKey('order', $this->errorsOf($this->params(['order' => 'ASC'])));
    }

    private function filterValidator(): FilterValidatorInterface
    {
        return new class implements FilterValidatorInterface
        {
            public function validate(array $params, array &$errors): array
            {
                if (isset($params['bad']))
                    $errors['bad'] = 'is bad';

                return isset($params['kind']) && is_string($params['kind']) ? ['kind' => $params['kind']] : [];
            }
        };
    }

    #[Group('units')]
    public function testWithoutAFilterValidatorThereAreNoFilters(): void
    {
        static::assertSame([], $this->validator->validate($this->params(['kind' => 'x']))->filters);
    }

    #[Group('units')]
    public function testFiltersOfTheModuleAreTaken(): void
    {
        $validator = new PageQueryValidator(new FieldValidator(), 100, 1000, $this->filterValidator());

        static::assertSame(['kind' => 'x'], $validator->validate($this->params(['kind' => 'x']))->filters);
        static::assertSame([], $validator->validate($this->params())->filters);
    }

    #[Group('units')]
    public function testErrorsOfTheFiltersAreCollectedWithTheOthers(): void
    {
        $validator = new PageQueryValidator(new FieldValidator(), 100, 1000, $this->filterValidator());

        try
        {
            $validator->validate($this->params(['bad' => '1', 'order' => 'up']));
            static::fail('ValidationException expected');
        }
        catch (ValidationException $e)
        {
            static::assertSame(['order' => 'must be asc or desc', 'bad' => 'is bad'], $e->getErrors());
        }
    }

    #[Group('units')]
    public function testTheRangeIsLimitedIfTheModuleSetsAMaximum(): void
    {
        $validator = new PageQueryValidator(new FieldValidator(), 100, 1000, null, 25 * 3600);
        $params    = ['player_id' => 'p1', 'from' => '2026-10-01T00:00:00Z'];

        static::assertSame(
            1790899200 + 3600,
            $validator->validate($params + ['to' => '2026-10-02T01:00:00Z'])->to->getTimestamp() // exactly 25 hours is allowed
        );

        try
        {
            $validator->validate($params + ['to' => '2026-10-02T01:00:01Z']);
            static::fail('ValidationException expected');
        }
        catch (ValidationException $e)
        {
            static::assertSame(['to' => 'must not be more than 25 hours after from'], $e->getErrors());
        }
    }

    #[Group('units')]
    public function testTheRangeIsNotLimitedWithoutAMaximum(): void
    {
        $query = $this->validator->validate($this->params(['from' => '2020-01-01T00:00:00Z', 'to' => '2026-01-01T00:00:00Z']));

        static::assertSame(1767225600, $query->to->getTimestamp());
    }

    #[Group('units')]
    public function testAMaximumInSecondsIsNamedInSeconds(): void
    {
        $validator = new PageQueryValidator(new FieldValidator(), 100, 1000, null, 90);

        try
        {
            $validator->validate(['player_id' => 'p1', 'from' => '2026-10-01T00:00:00Z', 'to' => '2026-10-01T00:02:00Z']);
            static::fail('ValidationException expected');
        }
        catch (ValidationException $e)
        {
            static::assertSame(['to' => 'must not be more than 90 seconds after from'], $e->getErrors());
        }
    }
}
