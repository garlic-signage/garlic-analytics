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

require __DIR__ . '/../vendor/autoload.php';

use App\Modules\Auth\ApiKeyMiddleware;
use App\Modules\EventLog\EventLogController;
use App\Modules\PlayLog\PlayLogController;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Slim\App;
use Slim\Routing\RouteCollectorProxy;

return function (App $app): void
{
    $app->get('/v1/health', function (Request $request, Response $response)
    {
        $response->getBody()->write(json_encode(['status' => 'ok'], JSON_THROW_ON_ERROR));
        return $response->withHeader('Content-Type', 'application/json');
    });

    // everything else under /v1 needs an API key
    $app->group('/v1', function (RouteCollectorProxy $group): void
    {
        $group->post('/playlog', [PlayLogController::class, 'ingest']);
        $group->post('/eventlog', [EventLogController::class, 'ingest']);
    })->add(ApiKeyMiddleware::class);
};