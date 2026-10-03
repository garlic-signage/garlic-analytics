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
use App\Framework\Validation\BatchValidator;
use App\Framework\Validation\FieldValidator;
use App\Modules\ConnectLog\ConnectLogController;
use App\Modules\ConnectLog\ConnectLogRepository;
use App\Modules\ConnectLog\ConnectLogService;
use App\Modules\ConnectLog\ConnectLogValidator;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Slim\Psr7\Factory\ResponseFactory;
use Slim\Psr7\Factory\ServerRequestFactory;

class ConnectLogControllerTest extends TestCase
{
    private function controller(ClickHouseClientInterface $client): ConnectLogController
    {
        return new ConnectLogController(new ConnectLogService(
            new ConnectLogValidator(new BatchValidator(new FieldValidator(), 10, 36500, 86400)),
            new ConnectLogRepository($client)
        ));
    }

    #[Group('units')]
    public function testIngestAnswersWith201AndCount(): void
    {
        $client = $this->createMock(ClickHouseClientInterface::class);
        $client->expects($this->once())->method('insert')->with('connect_log', static::countOf(1), static::anything(), static::isString());

        $request = new ServerRequestFactory()->createServerRequest('POST', '/v1/connectlog')->withParsedBody([
            'events' => [['player_id' => 'player-1', 'connected_at' => '2026-10-03T13:00:00+02:00', 'refresh' => 300]]
        ]);

        $response = $this->controller($client)->ingest($request, new ResponseFactory()->createResponse());

        static::assertSame(201, $response->getStatusCode());
        static::assertSame('{"accepted":1}', (string) $response->getBody());
    }

    #[Group('units')]
    public function testInvalidBodyIsAValidationErrorAndStoresNothing(): void
    {
        $client = $this->createMock(ClickHouseClientInterface::class);
        $client->expects($this->never())->method('insert');

        $this->expectException(ValidationException::class);

        $this->controller($client)->ingest(
            new ServerRequestFactory()->createServerRequest('POST', '/v1/connectlog'),
            new ResponseFactory()->createResponse()
        );
    }
}
