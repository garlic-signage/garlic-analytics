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

namespace Tests\Modules\SystemLog;

use App\Framework\Database\ClickHouseClientInterface;
use App\Framework\Exceptions\ValidationException;
use App\Framework\Query\PageQueryValidator;
use App\Framework\Query\QueryService;
use App\Framework\Validation\FieldValidator;
use App\Modules\SystemLog\SystemLogQueryController;
use App\Modules\SystemLog\SystemLogQueryRepository;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Slim\Psr7\Factory\ResponseFactory;
use Slim\Psr7\Factory\ServerRequestFactory;

class SystemLogQueryControllerTest extends TestCase
{
    private function controller(ClickHouseClientInterface $client): SystemLogQueryController
    {
        return new SystemLogQueryController(new QueryService(
            new PageQueryValidator(new FieldValidator(), 100, 1000),
            new SystemLogQueryRepository($client)
        ));
    }

    #[Group('units')]
    public function testListAnswersWith200TotalAndItems(): void
    {
        $client = static::createStub(ClickHouseClientInterface::class);
        $client->method('select')->willReturnCallback(
            static fn(string $sql): array => str_contains($sql, 'count()')
                ? [['total' => 3]]
                : [[
                    'reported_ts' => 1790856000, 'start_ts' => 1789888323, 'time_zone' => 'MEZ',
                    'disk_total' => '100', 'disk_free' => '40', 'cpu_usage' => null,
                    'memory_total' => null, 'memory_used' => null, 'hdmi_output' => '',
                ]]
        );
        $request = new ServerRequestFactory()->createServerRequest('GET', '/v1/systemlog')
            ->withQueryParams(['player_id' => 'p1', 'from' => '2026-10-01T00:00:00Z', 'to' => '2026-10-02T00:00:00Z', 'limit' => '1']);

        $response = $this->controller($client)->list($request, new ResponseFactory()->createResponse());

        static::assertSame(200, $response->getStatusCode());
        static::assertSame('application/json', $response->getHeaderLine('Content-Type'));
        static::assertSame(
            '{"total":3,"limit":1,"offset":0,"items":[{"reported_at":"2026-10-01T12:00:00Z","system_start":"2026-09-20T07:12:03Z","time_zone":"MEZ","disk_total":100,"disk_free":40,"cpu_usage":null,"memory_total":null,"memory_used":null,"hdmi_output":""}]}',
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
            new ServerRequestFactory()->createServerRequest('GET', '/v1/systemlog'),
            new ResponseFactory()->createResponse()
        );
    }
}
