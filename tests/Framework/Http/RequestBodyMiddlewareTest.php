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

namespace Tests\Framework\Http;

use App\Framework\Http\RequestBodyMiddleware;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Slim\Exception\HttpBadRequestException;
use Slim\Exception\HttpException;
use Slim\Psr7\Factory\ResponseFactory;
use Slim\Psr7\Factory\ServerRequestFactory;
use Slim\Psr7\Factory\StreamFactory;

class RequestBodyMiddlewareTest extends TestCase
{
    private const int LIMIT = 1000;

    private RequestBodyMiddleware $middleware;
    /** @var RequestHandlerInterface&object{handled: ?ServerRequestInterface} */
    private RequestHandlerInterface $handler;

    protected function setUp(): void
    {
        $this->middleware = new RequestBodyMiddleware(new StreamFactory(), self::LIMIT);
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

    private function request(string $body, ?string $encoding = null): ServerRequestInterface
    {
        $request = new ServerRequestFactory()->createServerRequest('POST', '/v1/playlog')
            ->withBody(new StreamFactory()->createStream($body));
        if ($encoding !== null)
            $request = $request->withHeader('Content-Encoding', $encoding);

        return $request;
    }

    private function handledBody(): string
    {
        static::assertNotNull($this->handler->handled);

        return (string) $this->handler->handled->getBody();
    }

    #[Group('units')]
    public function testBodyWithoutEncodingIsPassedOnUnchanged(): void
    {
        $this->middleware->process($this->request('{"events":[]}'), $this->handler);

        static::assertSame('{"events":[]}', $this->handledBody());
    }

    #[Group('units')]
    public function testIdentityEncodingIsPassedOnUnchanged(): void
    {
        $this->middleware->process($this->request('{"events":[]}', 'identity'), $this->handler);

        static::assertSame('{"events":[]}', $this->handledBody());
    }

    #[Group('units')]
    public function testGzipBodyIsUnpackedAndTheHeadersFollow(): void
    {
        $plain = '{"events":[{"player_id":"p1"}]}';

        $response = $this->middleware->process($this->request((string) gzencode($plain), 'gzip'), $this->handler);

        static::assertSame(204, $response->getStatusCode());
        static::assertSame($plain, $this->handledBody());
        $handled = $this->handler->handled;
        static::assertNotNull($handled);
        static::assertFalse($handled->hasHeader('Content-Encoding'));
        static::assertSame((string) strlen($plain), $handled->getHeaderLine('Content-Length'));
    }

    #[Group('units')]
    public function testEncodingNameIsNotCaseSensitiveAndXGzipIsAccepted(): void
    {
        $this->middleware->process($this->request((string) gzencode('abc'), ' GZIP '), $this->handler);
        static::assertSame('abc', $this->handledBody());

        $this->middleware->process($this->request((string) gzencode('def'), 'x-gzip'), $this->handler);
        static::assertSame('def', $this->handledBody());
    }

    #[Group('units')]
    public function testBodyLargerThanTheLimitAfterUnpackingGives413WithoutUnpackingItAll(): void
    {
        $limit = 100_000;
        $bomb  = (string) gzencode(str_repeat('a', 50_000_000), 9);
        static::assertLessThan($limit, strlen($bomb));

        $this->expectException(HttpException::class);
        $this->expectExceptionCode(413);

        new RequestBodyMiddleware(new StreamFactory(), $limit)->process($this->request($bomb, 'gzip'), $this->handler);
    }

    #[Group('units')]
    public function testPlainBodyLargerThanTheLimitGives413(): void
    {
        $this->expectException(HttpException::class);
        $this->expectExceptionCode(413);

        $this->middleware->process($this->request(str_repeat('a', self::LIMIT + 1)), $this->handler);
    }

    #[Group('units')]
    public function testPackedBodyLargerThanTheLimitGives413(): void
    {
        $noise = (string) gzencode(random_bytes(self::LIMIT * 2), 0);

        $this->expectException(HttpException::class);
        $this->expectExceptionCode(413);

        $this->middleware->process($this->request($noise, 'gzip'), $this->handler);
    }

    #[Group('units')]
    public function testContentLengthHeaderLargerThanTheLimitGives413(): void
    {
        $request = $this->request('{}')->withHeader('Content-Length', (string) (self::LIMIT + 1));

        $this->expectException(HttpException::class);
        $this->expectExceptionCode(413);

        $this->middleware->process($request, $this->handler);
    }

    #[Group('units')]
    public function testBrokenGzipDataGives400(): void
    {
        $this->expectException(HttpBadRequestException::class);

        $this->middleware->process($this->request('this is not gzip', 'gzip'), $this->handler);
    }

    #[Group('units')]
    public function testIncompleteGzipDataGives400(): void
    {
        $packed = (string) gzencode(str_repeat('{"events":[]}', 20));

        $this->expectException(HttpBadRequestException::class);

        $this->middleware->process($this->request(substr($packed, 0, intdiv(strlen($packed), 2)), 'gzip'), $this->handler);
    }

    #[Group('units')]
    public function testOtherEncodingGives415(): void
    {
        $this->expectException(HttpException::class);
        $this->expectExceptionCode(415);

        $this->middleware->process($this->request('abc', 'br'), $this->handler);
    }

    #[Group('units')]
    public function testSeveralEncodingsGive415(): void
    {
        $this->expectException(HttpException::class);
        $this->expectExceptionCode(415);

        $this->middleware->process($this->request('abc', 'gzip, gzip'), $this->handler);
    }
}
