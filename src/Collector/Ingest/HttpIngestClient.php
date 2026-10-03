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

namespace App\Collector\Ingest;

use App\Collector\Exceptions\RejectedIngestException;
use App\Collector\Exceptions\RetryableIngestException;
use App\Collector\PlayLogRecord;
use GuzzleHttp\ClientInterface;
use GuzzleHttp\Exception\GuzzleException;
use Psr\Http\Message\ResponseInterface;

/**
 * Sends the records to POST /v1/playlog of the ingest API with the API key of the collector.
 *
 * Only 400, 413 and 422 mean the data is wrong. Every other answer that is not 2xx leaves the file
 * where it is: 401, 403, 404 and 405 point to a wrong key, rights or URL, which must not send
 * good files to error/. Redirects are not followed.
 */
readonly class HttpIngestClient implements IngestClientInterface
{
    private const array REJECTED_STATUS = [400, 413, 422];
    private const int MAX_ERRORS_SHOWN  = 5;

    public function __construct(
        private ClientInterface $http,
        private string          $baseUrl,
        private string          $apiKey
    ) {}

    public function ensureConfigured(): void
    {
        if (trim($this->baseUrl) === '')
            throw new RetryableIngestException('COLLECTOR_API_URL is not set');
        if (trim($this->apiKey) === '')
            throw new RetryableIngestException('COLLECTOR_API_KEY is not set, create a key with "bin/console apikey:create collector --scope=ingest"');
    }

    public function sendPlayLog(array $records): void
    {
        $this->ensureConfigured();

        try
        {
            $response = $this->http->request('POST', rtrim($this->baseUrl, '/') . '/v1/playlog', [
                'headers'         => ['Authorization' => 'Bearer ' . $this->apiKey, 'Accept' => 'application/json'],
                'json'            => ['events' => array_map(static fn(PlayLogRecord $record): array => $record->toArray(), $records)],
                'http_errors'     => false,
                'allow_redirects' => false,
            ]);
        }
        catch (GuzzleException $e)
        {
            throw new RetryableIngestException('API not reachable: ' . $e->getMessage(), 0, $e);
        }

        $status = $response->getStatusCode();
        if ($status >= 200 && $status < 300)
            return;

        $message = 'HTTP ' . $status . ': ' . $this->describe($response);
        if (in_array($status, self::REJECTED_STATUS, true))
            throw new RejectedIngestException($message);

        throw new RetryableIngestException($message);
    }

    /**
     * The error text of the API ({"error": "...", "errors": {field: message}}), or the start of the body.
     */
    private function describe(ResponseInterface $response): string
    {
        $body = trim((string) $response->getBody());
        $data = json_decode($body, true);

        if (is_array($data) && isset($data['error']) && is_string($data['error']))
        {
            $text = $data['error'];
            if (isset($data['errors']) && is_array($data['errors']) && $data['errors'] !== [])
            {
                $parts = [];
                foreach (array_slice($data['errors'], 0, self::MAX_ERRORS_SHOWN, true) as $field => $error)
                {
                    if (is_string($error))
                        $parts[] = $field . ' ' . $error;
                }
                $more  = count($data['errors']) > self::MAX_ERRORS_SHOWN ? '; ...' : '';
                $text .= ' (' . implode('; ', $parts) . $more . ')';
            }

            return $text;
        }

        return $body === '' ? $response->getReasonPhrase() : mb_substr($body, 0, 200);
    }
}
