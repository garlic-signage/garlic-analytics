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

namespace Tests\Modules\PlayLog;

use App\Framework\Database\ClickHouseClientInterface;
use App\Framework\Exceptions\ValidationException;
use App\Framework\Validation\FieldValidator;
use App\Modules\PlayLog\PlayLogController;
use App\Modules\PlayLog\PlayLogRepository;
use App\Modules\PlayLog\PlayLogService;
use App\Modules\PlayLog\PlayLogValidator;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Slim\Psr7\Factory\ResponseFactory;
use Slim\Psr7\Factory\ServerRequestFactory;

class PlayLogControllerTest extends TestCase
{
    private function controller(ClickHouseClientInterface $client): PlayLogController
    {
        return new PlayLogController(new PlayLogService(
            new PlayLogValidator(new FieldValidator(), 10, 36500, 86400),
            new PlayLogRepository($client)
        ));
    }

    #[Group('units')]
    public function testIngestAnswersWith201AndCount(): void
    {
        $request = new ServerRequestFactory()->createServerRequest('POST', '/v1/playlog')->withParsedBody([
            'events' => [[
                'player_id'  => 'player-1',
                'content_id' => 'content-1',
                'start_time' => '2026-10-03T10:00:00Z',
                'end_time'   => '2026-10-03T10:00:10Z',
            ]]
        ]);

        $response = $this->controller(static::createStub(ClickHouseClientInterface::class))
            ->ingest($request, new ResponseFactory()->createResponse());

        static::assertSame(201, $response->getStatusCode());
        static::assertSame('application/json', $response->getHeaderLine('Content-Type'));
        static::assertSame('{"accepted":1}', (string) $response->getBody());
    }

    #[Group('units')]
    public function testMissingBodyIsAValidationError(): void
    {
        $request = new ServerRequestFactory()->createServerRequest('POST', '/v1/playlog'); // no parsed body

        $this->expectException(ValidationException::class);

        $this->controller(static::createStub(ClickHouseClientInterface::class))
            ->ingest($request, new ResponseFactory()->createResponse());
    }
}
