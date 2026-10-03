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

namespace App\Framework\Ingest;

use App\Framework\Exceptions\DatabaseException;
use App\Framework\Exceptions\ValidationException;
use JsonException;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * POST /v1/<module>: takes a batch of events (scope "ingest", checked by ApiKeyMiddleware).
 * The modules extend it with their service.
 *
 * 201 {"accepted": n}, 422 on invalid data, 500 if the database fails.
 */
abstract readonly class IngestController
{
    /**
     * @param IngestService<covariant object> $service
     */
    public function __construct(private IngestService $service) {}

    /**
     * @throws ValidationException
     * @throws DatabaseException
     * @throws JsonException
     */
    public function ingest(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $accepted = $this->service->ingest($request->getParsedBody());

        $response->getBody()->write(json_encode(['accepted' => $accepted], JSON_THROW_ON_ERROR));

        return $response->withStatus(201)->withHeader('Content-Type', 'application/json');
    }
}
