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

namespace App\Framework\Query;

use App\Framework\Exceptions\DatabaseException;
use App\Framework\Exceptions\ValidationException;
use JsonException;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * GET /v1/<module>: one page of the raw events of a player (scope "read", checked by ApiKeyMiddleware).
 * The modules extend it, the repository is the only part that differs.
 *
 * 200 {"total": n, "limit": n, "offset": n, "items": [...]}, 422 on invalid parameters, 500 if the database fails.
 */
abstract readonly class QueryController
{
    public function __construct(private QueryService $service) {}

    /**
     * @throws ValidationException
     * @throws DatabaseException
     * @throws JsonException
     */
    public function list(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $page = $this->service->query($this->parameters($request));

        $response->getBody()->write(json_encode($page->toArray(), JSON_THROW_ON_ERROR));

        return $response->withHeader('Content-Type', 'application/json');
    }

    /**
     * The parameters of the query: the query string, a group controller reads the body instead.
     *
     * @return array<array-key,mixed>
     * @throws ValidationException
     */
    protected function parameters(ServerRequestInterface $request): array
    {
        return $request->getQueryParams();
    }
}
