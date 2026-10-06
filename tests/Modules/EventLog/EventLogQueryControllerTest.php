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

use App\Framework\Database\ClickHouseClientInterface;
use App\Framework\Exceptions\ValidationException;
use App\Framework\Query\PageQueryValidator;
use App\Framework\Query\QueryService;
use App\Framework\Validation\FieldValidator;
use App\Modules\EventLog\EventLogQueryController;
use App\Modules\EventLog\EventLogQueryRepository;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Slim\Psr7\Factory\ResponseFactory;
use Slim\Psr7\Factory\ServerRequestFactory;

class EventLogQueryControllerTest extends TestCase
{
    private function controller(ClickHouseClientInterface $client): EventLogQueryController
    {
        return new EventLogQueryController(new QueryService(
            new PageQueryValidator(new FieldValidator(), 100, 1000),
            new EventLogQueryRepository($client)
        ));
    }

    #[Group('units')]
    public function testListAnswersWith200TotalAndItems(): void
    {
        $client = static::createStub(ClickHouseClientInterface::class);
        $client->method('select')->willReturnCallback(
            static fn(string $sql): array => str_contains($sql, 'count()')
                ? [['total' => 3]]
                : [['event_ts' => 1790856000, 'event_type' => 'warning', 'event_source' => 'ContentManager', 'event_name' => 'FETCH_FAILED', 'metadata' => []]]
        );
        $request = new ServerRequestFactory()->createServerRequest('GET', '/v1/eventlog')
            ->withQueryParams(['player_id' => 'p1', 'from' => '2026-10-01T00:00:00Z', 'to' => '2026-10-02T00:00:00Z', 'limit' => '1']);

        $response = $this->controller($client)->list($request, new ResponseFactory()->createResponse());

        static::assertSame(200, $response->getStatusCode());
        static::assertSame('application/json', $response->getHeaderLine('Content-Type'));
        static::assertSame(
            '{"total":3,"limit":1,"offset":0,"items":[{"event_time":"2026-10-01T12:00:00Z","event_type":"warning","event_source":"ContentManager","event_name":"FETCH_FAILED","metadata":{}}]}',
            (string) $response->getBody()
        );
    }

    #[Group('units')]
    public function testInvalidParametersAreAValidationErrorAndAskNoDatabase(): void
    {
        $client = $this->createMock(ClickHouseClientInterface::class);
        $client->expects($this->never())->method('select');

        $this->expectException(ValidationException::class);

        $this->controller($client)->list(
            new ServerRequestFactory()->createServerRequest('GET', '/v1/eventlog'),
            new ResponseFactory()->createResponse()
        );
    }
}
