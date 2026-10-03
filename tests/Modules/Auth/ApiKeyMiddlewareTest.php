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

namespace Tests\Modules\Auth;

use App\Framework\Core\Crypt;
use App\Modules\Auth\ApiClient;
use App\Modules\Auth\ApiKeyMiddleware;
use App\Modules\Auth\ApiKeyStoreInterface;
use App\Modules\Auth\Scope;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Slim\Exception\HttpForbiddenException;
use Slim\Exception\HttpUnauthorizedException;
use Slim\Psr7\Factory\ResponseFactory;
use Slim\Psr7\Factory\ServerRequestFactory;

class ApiKeyMiddlewareTest extends TestCase
{
    private ApiKeyMiddleware $middleware;
    /** @var RequestHandlerInterface&object{handled: ?ServerRequestInterface} */
    private RequestHandlerInterface $handler;

    protected function setUp(): void
    {
        $collector = new ApiClient('collector', [Scope::Ingest]);
        $store     = static::createStub(ApiKeyStoreInterface::class);
        $store->method('findByKeyHash')
            ->willReturnCallback(fn(string $hash) => hash_equals(hash('sha256', 'valid-key'), $hash) ? $collector : null);

        $this->middleware = new ApiKeyMiddleware($store, new Crypt());
        $this->handler    = new class implements RequestHandlerInterface
        {
            public ?ServerRequestInterface $handled = null;

            public function handle(ServerRequestInterface $request): ResponseInterface
            {
                $this->handled = $request;
                return new ResponseFactory()->createResponse(204);
            }
        };
    }

    #[Group('units')]
    public function testValidKeyWithScopePassesClientOn(): void
    {
        $response = $this->middleware->process($this->request('POST', 'Bearer valid-key'), $this->handler);

        static::assertSame(204, $response->getStatusCode());
        static::assertNotNull($this->handler->handled);
        $client = $this->handler->handled->getAttribute(ApiKeyMiddleware::ATTRIBUTE_CLIENT);
        static::assertInstanceOf(ApiClient::class, $client);
        static::assertSame('collector', $client->name);
    }

    #[Group('units')]
    public function testSchemeIsCaseInsensitive(): void
    {
        $response = $this->middleware->process($this->request('POST', 'bearer valid-key'), $this->handler);

        static::assertSame(204, $response->getStatusCode());
    }

    #[Group('units')]
    public function testMissingHeaderIsUnauthorized(): void
    {
        $this->expectException(HttpUnauthorizedException::class);
        $this->expectExceptionMessage('Missing API key.');
        $this->middleware->process($this->request('POST', null), $this->handler);
    }

    #[Group('units')]
    public function testOtherSchemeIsUnauthorized(): void
    {
        $this->expectException(HttpUnauthorizedException::class);
        $this->middleware->process($this->request('POST', 'Basic dXNlcjpwYXNz'), $this->handler);
    }

    #[Group('units')]
    public function testUnknownKeyIsUnauthorized(): void
    {
        $this->expectException(HttpUnauthorizedException::class);
        $this->expectExceptionMessage('Invalid API key.');
        $this->middleware->process($this->request('POST', 'Bearer wrong-key'), $this->handler);
    }

    #[Group('units')]
    public function testMissingScopeIsForbiddenAndStopsTheRequest(): void
    {
        try
        {
            $this->middleware->process($this->request('GET', 'Bearer valid-key'), $this->handler);
            static::fail('Expected HttpForbiddenException');
        }
        catch (HttpForbiddenException $e)
        {
            static::assertSame(403, $e->getCode());
            static::assertNull($this->handler->handled);
        }
    }

    private function request(string $method, ?string $authorization): ServerRequestInterface
    {
        $request = new ServerRequestFactory()->createServerRequest($method, '/v1/playlog');
        if ($authorization !== null)
            $request = $request->withHeader('Authorization', $authorization);

        return $request;
    }
}
