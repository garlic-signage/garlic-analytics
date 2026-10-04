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

namespace Tests\Integration;

use DateTimeImmutable;
use PHPUnit\Framework\Attributes\Group;
use Psr\Http\Message\ResponseInterface;
use Slim\Psr7\Factory\ServerRequestFactory;
use Slim\Psr7\Factory\StreamFactory;

/**
 * A request through the whole application (config/middleware.php with the real services against the
 * test database): gzip bodies, the size limit and the order of the middleware.
 */
#[Group('integration')]
class RequestBodyIntegrationTest extends AppIntegrationTestCase
{
    private function body(): string
    {
        $time = new DateTimeImmutable('-1 hour');

        return json_encode(['events' => [[
            'player_id'  => 'p1',
            'content_id' => 'c1',
            'start_time' => $time->format('Y-m-d\TH:i:sP'),
            'end_time'   => $time->modify('+10 seconds')->format('Y-m-d\TH:i:sP'),
        ]]], JSON_THROW_ON_ERROR);
    }

    private function post(string $body, ?string $encoding, ?string $key = self::INGEST_KEY): ResponseInterface
    {
        $request = new ServerRequestFactory()->createServerRequest('POST', '/v1/playlog')
            ->withHeader('Content-Type', 'application/json')
            ->withBody(new StreamFactory()->createStream($body));
        if ($encoding !== null)
            $request = $request->withHeader('Content-Encoding', $encoding);
        if ($key !== null)
            $request = $request->withHeader('Authorization', 'Bearer ' . $key);

        return $this->handle($request);
    }

    private function playLogRows(): int
    {
        $count = $this->rows('SELECT count() AS n FROM play_log')[0]['n'] ?? null;

        return is_int($count) ? $count : -1;
    }

    #[Group('integration')]
    public function testGzipBodyIsStored(): void
    {
        $response = $this->post((string) gzencode($this->body()), 'gzip');

        static::assertSame(201, $response->getStatusCode());
        static::assertSame('{"accepted":1}', (string) $response->getBody());
        static::assertSame(1, $this->playLogRows());
    }

    #[Group('integration')]
    public function testPlainBodyIsStillStored(): void
    {
        $response = $this->post($this->body(), null);

        static::assertSame(201, $response->getStatusCode());
        static::assertSame(1, $this->playLogRows());
    }

    #[Group('integration')]
    public function testGzipAndPlainRequestWithTheSameContentAreTheSameBatch(): void
    {
        $body = $this->body();

        $this->post((string) gzencode($body), 'gzip');
        $this->post($body, null);

        static::assertSame(1, $this->playLogRows());
    }

    #[Group('integration')]
    public function testBrokenGzipGives400AsJson(): void
    {
        $response = $this->post('not gzip', 'gzip');

        static::assertSame(400, $response->getStatusCode());
        static::assertSame('application/json', $response->getHeaderLine('Content-Type'));
        static::assertSame(0, $this->playLogRows());
    }

    #[Group('integration')]
    public function testOtherEncodingGives415(): void
    {
        $response = $this->post($this->body(), 'br');

        static::assertSame(415, $response->getStatusCode());
        static::assertSame(0, $this->playLogRows());
    }

    #[Group('integration')]
    public function testGzipBodyWithoutKeyIsStopped(): void
    {
        $response = $this->post((string) gzencode($this->body()), 'gzip', null);

        static::assertSame(401, $response->getStatusCode());
        static::assertSame(0, $this->playLogRows());
    }

    #[Group('integration')]
    public function testZipBombIsStoppedWithoutKey(): void
    {
        $bomb = (string) gzencode(str_repeat('a', 40_000_000), 9);

        $response = $this->post($bomb, 'gzip', null);

        static::assertSame(413, $response->getStatusCode());
    }
}
