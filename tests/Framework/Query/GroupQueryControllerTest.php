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
use App\Framework\Query\GroupQueryController;
use App\Framework\Query\PageQuery;
use App\Framework\Query\PageQueryValidator;
use App\Framework\Query\QueryRepositoryInterface;
use App\Framework\Query\QueryService;
use App\Framework\Validation\FieldValidator;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Slim\Psr7\Factory\ResponseFactory;
use Slim\Psr7\Factory\ServerRequestFactory;

class GroupQueryControllerTest extends TestCase
{
    /** @var list<PageQuery> */
    private array $queries = [];

    private function controller(): GroupQueryController
    {
        $repository = static::createStub(QueryRepositoryInterface::class);
        $repository->method('count')->willReturnCallback(function (PageQuery $query): int
        {
            $this->queries[] = $query;

            return 1;
        });
        $repository->method('find')->willReturn([['n' => 7]]);

        return new readonly class (new QueryService(new PageQueryValidator(new FieldValidator(), 100, 1000, null, null, true, 5), $repository)) extends GroupQueryController {};
    }

    #[Group('units')]
    public function testTheParametersComeFromTheBodyNotFromTheQueryString(): void
    {
        $request = new ServerRequestFactory()->createServerRequest('POST', '/v1/x')
            ->withQueryParams(['player_ids' => ['from-the-url']])
            ->withParsedBody(['player_ids' => ['a', 'b', 'a'], 'from' => '2026-10-01T00:00:00Z', 'to' => '2026-10-02T00:00:00Z', 'limit' => 7]);

        $response = $this->controller()->list($request, new ResponseFactory()->createResponse());

        static::assertSame(200, $response->getStatusCode());
        static::assertSame('application/json', $response->getHeaderLine('Content-Type'));
        static::assertSame('{"total":1,"limit":7,"offset":0,"items":[{"n":7}]}', (string) $response->getBody());
        static::assertSame(['a', 'b'], $this->queries[0]->playerIds);
        static::assertSame('', $this->queries[0]->playerId);
    }

    #[Group('units')]
    public function testAMissingBodyIsAValidationError(): void
    {
        $request = new ServerRequestFactory()->createServerRequest('POST', '/v1/x'); // no body that could be parsed: null

        try
        {
            $this->controller()->list($request, new ResponseFactory()->createResponse());
            static::fail('ValidationException expected');
        }
        catch (ValidationException $e)
        {
            static::assertSame(['body' => 'must be a JSON object'], $e->getErrors());
        }

        static::assertSame([], $this->queries);
    }

    #[Group('units')]
    public function testAnEmptyBodyObjectIsCheckedLikeAnyOther(): void
    {
        $request = new ServerRequestFactory()->createServerRequest('POST', '/v1/x')->withParsedBody([]);

        try
        {
            $this->controller()->list($request, new ResponseFactory()->createResponse());
            static::fail('ValidationException expected');
        }
        catch (ValidationException $e)
        {
            static::assertSame(['player_ids', 'from', 'to'], array_keys($e->getErrors()));
        }
    }
}
