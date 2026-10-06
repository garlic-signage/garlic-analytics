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

namespace Tests\Modules\ConnectLog;

use App\Framework\Database\ClickHouseClientInterface;
use App\Framework\Exceptions\ValidationException;
use App\Framework\Query\PageQueryValidator;
use App\Framework\Query\QueryController;
use App\Framework\Query\QueryService;
use App\Framework\Validation\FieldValidator;
use App\Modules\ConnectLog\ConnectLogFilterValidator;
use App\Modules\ConnectLog\ConnectLogQueryController;
use App\Modules\ConnectLog\ConnectLogQueryRepository;
use App\Modules\ConnectLog\ConnectLogRawQueryController;
use App\Modules\ConnectLog\ConnectLogRawQueryRepository;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ServerRequestInterface;
use Slim\Psr7\Factory\ResponseFactory;
use Slim\Psr7\Factory\ServerRequestFactory;

class ConnectLogQueryControllerTest extends TestCase
{
    private function aggregate(ClickHouseClientInterface $client): QueryController
    {
        return new ConnectLogQueryController(new QueryService(
            new PageQueryValidator(new FieldValidator(), 100, 1000, new ConnectLogFilterValidator()),
            new ConnectLogQueryRepository($client)
        ));
    }

    private function raw(ClickHouseClientInterface $client): QueryController
    {
        return new ConnectLogRawQueryController(new QueryService(
            new PageQueryValidator(new FieldValidator(), 100, 1000, null, 25 * 3600),
            new ConnectLogRawQueryRepository($client)
        ));
    }

    /**
     * @param array<string,string> $params
     */
    private function request(string $path, array $params = []): ServerRequestInterface
    {
        return new ServerRequestFactory()->createServerRequest('GET', $path)
            ->withQueryParams([...['player_id' => 'p1', 'from' => '2026-10-01T00:00:00Z', 'to' => '2026-10-02T00:00:00Z'], ...$params]);
    }

    #[Group('units')]
    public function testAggregateAnswersWith200TotalAndItems(): void
    {
        $client = static::createStub(ClickHouseClientInterface::class);
        $client->method('select')->willReturnCallback(
            static fn(string $sql): array => str_contains($sql, 'count()')
                ? [['total' => 2]]
                : [['period' => '2026-10-01', 'total_connects' => '1440', 'total_covered_s' => '86400']]
        );

        $response = $this->aggregate($client)->list($this->request('/v1/connectlog', ['resolution' => 'day', 'limit' => '1']), new ResponseFactory()->createResponse());

        static::assertSame(200, $response->getStatusCode());
        static::assertSame(
            '{"total":2,"limit":1,"offset":0,"items":[{"period":"2026-10-01","connects":1440,"covered_s":86400}]}',
            (string) $response->getBody()
        );
    }

    #[Group('units')]
    public function testRawAnswersWith200TotalAndItems(): void
    {
        $client = static::createStub(ClickHouseClientInterface::class);
        $client->method('select')->willReturnCallback(
            static fn(string $sql): array => str_contains($sql, 'count()') ? [['total' => 1]] : [['connected_ts' => 1790856000, 'refresh' => 60]]
        );

        $response = $this->raw($client)->list($this->request('/v1/connectlog/raw'), new ResponseFactory()->createResponse());

        static::assertSame(
            '{"total":1,"limit":100,"offset":0,"items":[{"connected_at":"2026-10-01T12:00:00Z","refresh":60}]}',
            (string) $response->getBody()
        );
    }

    #[Group('units')]
    public function testInvalidParametersAskNoDatabase(): void
    {
        foreach ([$this->aggregate(...), $this->raw(...)] as $controller)
        {
            $client = $this->createMock(ClickHouseClientInterface::class);
            $client->expects($this->never())->method('select');

            try
            {
                $controller($client)->list($this->request('/v1/connectlog', ['resolution' => 'week', 'to' => '2026-10-05T00:00:00Z']), new ResponseFactory()->createResponse());
                static::fail('ValidationException expected');
            }
            catch (ValidationException $e)
            {
                static::assertNotSame([], $e->getErrors());
            }
        }
    }
}
