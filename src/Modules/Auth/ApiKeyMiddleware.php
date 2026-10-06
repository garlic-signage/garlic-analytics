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

namespace App\Modules\Auth;

use App\Framework\Core\Crypt;
use App\Framework\Exceptions\CoreException;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Slim\Exception\HttpForbiddenException;
use Slim\Exception\HttpUnauthorizedException;
use Slim\Interfaces\RouteInterface;
use Slim\Routing\RouteContext;

/**
 * Checks the API key sent as "Authorization: Bearer <key>".
 *
 * Added to the /v1 route group, /v1/health stays outside of it.
 * The required scope follows from the HTTP method (see Scope::forMethod). A route can set it itself with the
 * route argument "scope" (->setArgument('scope', 'read')), for a POST that only reads (the query of a group of players).
 *
 * - missing or unknown key: 401
 * - key without the required scope: 403
 * - otherwise the ApiClient is passed on as request attribute "apiClient"
 */
readonly class ApiKeyMiddleware implements MiddlewareInterface
{
    public const string ATTRIBUTE_CLIENT = 'apiClient';

    public function __construct(
        private ApiKeyStoreInterface $store,
        private Crypt                $crypt
    ) {}

    /**
     * @throws HttpUnauthorizedException
     * @throws HttpForbiddenException
     * @throws CoreException
     */
    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $key = $this->extractBearerToken($request);
        if ($key === null)
            throw new HttpUnauthorizedException($request, 'Missing API key.');

        $client = $this->store->findByKeyHash($this->crypt->createSha256Hash($key));
        if ($client === null)
            throw new HttpUnauthorizedException($request, 'Invalid API key.');

        $scope = $this->requiredScope($request);
        if (!$client->hasScope($scope))
            throw new HttpForbiddenException($request, 'API key has no scope "' . $scope->value . '".');

        return $handler->handle($request->withAttribute(self::ATTRIBUTE_CLIENT, $client));
    }

    /**
     * The scope of the route if it sets one, otherwise the one of the HTTP method. A scope the route names but
     * Scope does not know is a mistake in the routes (ValueError, answered as 500), never a fall back to a weaker scope.
     */
    private function requiredScope(ServerRequestInterface $request): Scope
    {
        $route    = $request->getAttribute(RouteContext::ROUTE);
        $argument = $route instanceof RouteInterface ? $route->getArgument('scope') : null;

        return $argument === null ? Scope::forMethod($request->getMethod()) : Scope::from($argument);
    }

    private function extractBearerToken(ServerRequestInterface $request): ?string
    {
        if (preg_match('/^Bearer\s+(\S+)\s*$/i', $request->getHeaderLine('Authorization'), $matches) !== 1)
            return null;

        return $matches[1];
    }
}
