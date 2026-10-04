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
use App\Framework\Query\PageQuery;
use App\Framework\Query\PageQueryValidator;
use App\Framework\Query\QueryRepositoryInterface;
use App\Framework\Query\QueryService;
use App\Framework\Validation\FieldValidator;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

class QueryServiceTest extends TestCase
{
    private const array PARAMS = ['player_id' => 'p1', 'from' => '2026-10-01T00:00:00Z', 'to' => '2026-10-02T00:00:00Z', 'limit' => '2', 'offset' => '4'];

    private function service(QueryRepositoryInterface $repository): QueryService
    {
        return new QueryService(new PageQueryValidator(new FieldValidator(), 100, 1000), $repository);
    }

    #[Group('units')]
    public function testReturnsTheTotalAndThePage(): void
    {
        $items      = [['content_id' => 'c1', 'start_time' => '2026-10-01T10:00:00Z', 'end_time' => '2026-10-01T10:00:10Z', 'duration_s' => 10]];
        $repository = $this->createMock(QueryRepositoryInterface::class);
        $repository->expects($this->once())->method('count')->with(static::isInstanceOf(PageQuery::class))->willReturn(7);
        $repository->expects($this->once())->method('find')->with(static::callback(
            static fn(PageQuery $q): bool => $q->playerId === 'p1' && $q->limit === 2 && $q->offset === 4 && $q->descending
        ))->willReturn($items);

        $page = $this->service($repository)->query(self::PARAMS);

        static::assertSame(['total' => 7, 'limit' => 2, 'offset' => 4, 'items' => $items], $page->toArray());
    }

    #[Group('units')]
    public function testDoesNotReadAPageIfNothingMatches(): void
    {
        $repository = $this->createMock(QueryRepositoryInterface::class);
        $repository->method('count')->willReturn(0);
        $repository->expects($this->never())->method('find');

        static::assertSame(['total' => 0, 'limit' => 2, 'offset' => 4, 'items' => []], $this->service($repository)->query(self::PARAMS)->toArray());
    }

    #[Group('units')]
    public function testInvalidParametersTouchNoRepository(): void
    {
        $repository = $this->createMock(QueryRepositoryInterface::class);
        $repository->expects($this->never())->method('count');
        $repository->expects($this->never())->method('find');

        $this->expectException(ValidationException::class);

        $this->service($repository)->query([]);
    }
}
