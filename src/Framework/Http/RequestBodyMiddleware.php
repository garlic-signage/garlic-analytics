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

namespace App\Framework\Http;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Message\StreamFactoryInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Slim\Exception\HttpBadRequestException;
use Slim\Exception\HttpException;

/**
 * Limits the size of the request body and unpacks a gzip body ("Content-Encoding: gzip"), so the
 * body parsing and everything behind it see plain JSON.
 *
 * It has to run before the body parsing, which means before the authentication too. The limit is
 * therefore applied while unpacking: the data is inflated in small pieces and the request is stopped as soon
 * as the unpacked size exceeds the limit, so a small request cannot use up the memory (zip bomb).
 *
 * - no or "identity" encoding: body is passed on unchanged
 * - "gzip" (or "x-gzip"): body is replaced by the unpacked data, Content-Encoding is removed
 * - any other encoding: 415
 * - broken or incomplete gzip data: 400
 * - packed or unpacked body larger than the limit: 413
 */
readonly class RequestBodyMiddleware implements MiddlewareInterface
{
    public const int DEFAULT_MAX_BODY_BYTES = 8388608;

    // deflate expands at most about 1000 times, so one step of 4 KiB gives at most about 4 MiB
    private const int CHUNK_BYTES = 4096;

    public function __construct(
        private StreamFactoryInterface $streamFactory,
        private int $maxBodyBytes = self::DEFAULT_MAX_BODY_BYTES
    ) {}

    /**
     * @throws HttpException
     */
    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $encoding = strtolower(trim($request->getHeaderLine('Content-Encoding')));
        $this->assertNotLargerThanLimit($request, (int) $request->getHeaderLine('Content-Length'));
        $this->assertNotLargerThanLimit($request, $request->getBody()->getSize() ?? 0);

        if ($encoding === '' || $encoding === 'identity')
            return $handler->handle($request);

        if ($encoding !== 'gzip' && $encoding !== 'x-gzip')
            throw new HttpException($request, 'Unsupported Content-Encoding, only gzip is supported.', 415);

        $plain  = $this->inflate($request);
        $stream = $this->streamFactory->createStream($plain);

        return $handler->handle(
            $request->withBody($stream)
                ->withoutHeader('Content-Encoding')
                ->withHeader('Content-Length', (string) strlen($plain))
        );
    }

    /**
     * @throws HttpException
     */
    private function inflate(ServerRequestInterface $request): string
    {
        $context = inflate_init(ZLIB_ENCODING_GZIP);
        if ($context === false)
            throw new HttpException($request, 'Internal Server Error', 500);

        $body = $request->getBody();
        $body->rewind();

        $plain = '';
        while (!$body->eof())
        {
            $chunk = $body->read(self::CHUNK_BYTES);
            if ($chunk === '')
                break;

            $part = @inflate_add($context, $chunk);
            if ($part === false)
                throw new HttpBadRequestException($request, 'Request body is not valid gzip data.');

            $plain .= $part;
            $this->assertNotLargerThanLimit($request, strlen($plain));
        }

        if (inflate_get_status($context) !== ZLIB_STREAM_END)
            throw new HttpBadRequestException($request, 'Request body is not valid gzip data.');

        return $plain;
    }

    /**
     * @throws HttpException
     */
    private function assertNotLargerThanLimit(ServerRequestInterface $request, int $bytes): void
    {
        if ($bytes > $this->maxBodyBytes)
            throw new HttpException($request, 'Request body must not be larger than ' . $this->maxBodyBytes . ' bytes.', 413);
    }
}
