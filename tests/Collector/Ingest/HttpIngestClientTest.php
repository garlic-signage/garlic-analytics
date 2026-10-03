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

namespace Tests\Collector\Ingest;

use App\Collector\Exceptions\RejectedIngestException;
use App\Collector\Exceptions\RetryableIngestException;
use App\Collector\Ingest\HttpIngestClient;
use App\Collector\EventLogRecord;
use App\Collector\LogType;
use App\Collector\PlayLogRecord;
use App\Collector\SystemLogRecord;
use ArrayObject;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Response;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\RequestInterface;

class HttpIngestClientTest extends TestCase
{
    /** @var ArrayObject<int, array<string,mixed>> */
    private ArrayObject $history;

    /**
     * @param list<Response|\Throwable> $queue
     */
    private function client(array $queue, string $url = 'http://api.test', string $key = 'secret-key'): HttpIngestClient
    {
        $history       = new ArrayObject();
        $this->history = $history; // before the by-reference call below, which widens the type for PHPStan
        $stack         = HandlerStack::create(new MockHandler($queue));
        $stack->push(Middleware::history($history));

        return new HttpIngestClient(new Client(['handler' => $stack]), $url, $key);
    }

    private function lastRequest(): RequestInterface
    {
        $entry = $this->history[0] ?? null;
        static::assertIsArray($entry);
        static::assertInstanceOf(RequestInterface::class, $entry['request']);

        return $entry['request'];
    }

    /** @return list<PlayLogRecord> */
    private function records(): array
    {
        return [new PlayLogRecord('p1', 'c1', '2026-10-03T15:30:27+02:00', '2026-10-03T15:30:37+02:00')];
    }

    #[Group('units')]
    public function testPostsEventsWithBearerKey(): void
    {
        $this->client([new Response(201, [], '{"accepted":1}')])->send(LogType::PlayLog, $this->records());

        static::assertCount(1, $this->history);
        $request = $this->lastRequest();
        static::assertSame('POST', $request->getMethod());
        static::assertSame('http://api.test/v1/playlog', (string) $request->getUri());
        static::assertSame('Bearer secret-key', $request->getHeaderLine('Authorization'));
        static::assertStringContainsString('application/json', $request->getHeaderLine('Content-Type'));
        static::assertSame(
            ['events' => [['player_id' => 'p1', 'content_id' => 'c1', 'start_time' => '2026-10-03T15:30:27+02:00', 'end_time' => '2026-10-03T15:30:37+02:00']]],
            json_decode((string) $request->getBody(), true)
        );
    }

    #[Group('units')]
    public function testEventsGoToTheEventLogEndpoint(): void
    {
        $records = [
            new EventLogRecord('p1', '2026-10-03T15:30:27+02:00', 'warning', 'ContentManager', 'FETCH_FAILED', ['resourceURI' => 'http://x']),
            new EventLogRecord('p1', '2026-10-03T15:30:28+02:00', 'informational', 'System', 'STARTED'),
        ];

        $this->client([new Response(201)])->send(LogType::Event, $records);

        static::assertSame('http://api.test/v1/eventlog', (string) $this->lastRequest()->getUri());
        static::assertSame(
            ['events' => [
                ['player_id' => 'p1', 'event_time' => '2026-10-03T15:30:27+02:00', 'event_type' => 'warning', 'event_source' => 'ContentManager', 'event_name' => 'FETCH_FAILED', 'metadata' => ['resourceURI' => 'http://x']],
                ['player_id' => 'p1', 'event_time' => '2026-10-03T15:30:28+02:00', 'event_type' => 'informational', 'event_source' => 'System', 'event_name' => 'STARTED'],
            ]],
            json_decode((string) $this->lastRequest()->getBody(), true)
        );
    }

    #[Group('units')]
    public function testSystemReportsGoToTheSystemLogEndpoint(): void
    {
        $records = [new SystemLogRecord('p1', '2026-10-03T15:30:27+02:00', '2026-10-03T03:00:00+02:00', 'MEZ', 100, 40, null, null, null, null)];

        $this->client([new Response(201)])->send(LogType::System, $records);

        static::assertSame('http://api.test/v1/systemlog', (string) $this->lastRequest()->getUri());
        static::assertSame(
            ['events' => [['player_id' => 'p1', 'reported_at' => '2026-10-03T15:30:27+02:00', 'system_start' => '2026-10-03T03:00:00+02:00', 'time_zone' => 'MEZ', 'disk_total' => 100, 'disk_free' => 40]]],
            json_decode((string) $this->lastRequest()->getBody(), true)
        );
    }

    #[Group('units')]
    public function testTrailingSlashOfTheUrlIsIgnored(): void
    {
        $this->client([new Response(201)], 'http://api.test/')->send(LogType::PlayLog, $this->records());

        static::assertSame('http://api.test/v1/playlog', (string) $this->lastRequest()->getUri());
    }

    #[Group('units')]
    #[DataProvider('refusedStatus')]
    public function testDataErrorsAreRejected(int $status): void
    {
        $this->expectException(RejectedIngestException::class);
        $this->expectExceptionMessage('HTTP ' . $status);

        $this->client([new Response($status, [], '{"error":"nope"}')])->send(LogType::PlayLog, $this->records());
    }

    /** @return array<string,array{int}> */
    public static function refusedStatus(): array
    {
        return ['bad request' => [400], 'too large' => [413], 'validation' => [422]];
    }

    #[Group('units')]
    #[DataProvider('retryStatus')]
    public function testEverythingElseKeepsTheFile(int $status): void
    {
        $this->expectException(RetryableIngestException::class);
        $this->expectExceptionMessage('HTTP ' . $status);

        $this->client([new Response($status, [], '{"error":"nope"}')])->send(LogType::PlayLog, $this->records());
    }

    /** @return array<string,array{int}> */
    public static function retryStatus(): array
    {
        return [
            'invalid key'       => [401],
            'no scope'          => [403],
            'wrong url'         => [404],
            'wrong method'      => [405],
            'timeout'           => [408],
            'conflict'          => [409],
            'too many requests' => [429],
            'server error'      => [500],
            'bad gateway'       => [502],
            'unavailable'       => [503],
            'redirect'          => [302],
        ];
    }

    #[Group('units')]
    public function testRefusalNamesTheFieldsOfTheApi(): void
    {
        $body = json_encode([
            'error'  => 'Validation failed',
            'errors' => ['events.0.start_time' => 'is required', 'events.1.player_id' => 'is required'],
        ], JSON_THROW_ON_ERROR);

        try
        {
            $this->client([new Response(422, [], $body)])->send(LogType::PlayLog, $this->records());
            static::fail('RejectedIngestException expected');
        }
        catch (RejectedIngestException $e)
        {
            static::assertSame('HTTP 422: Validation failed (events.0.start_time is required; events.1.player_id is required)', $e->getMessage());
        }
    }

    #[Group('units')]
    public function testLongListOfErrorsIsCut(): void
    {
        $errors = [];
        for ($i = 0; $i < 20; $i++)
            $errors['events.' . $i . '.player_id'] = 'is required';

        try
        {
            $this->client([new Response(422, [], json_encode(['error' => 'Validation failed', 'errors' => $errors], JSON_THROW_ON_ERROR))])->send(LogType::PlayLog, $this->records());
            static::fail('RejectedIngestException expected');
        }
        catch (RejectedIngestException $e)
        {
            static::assertStringContainsString('events.4.player_id is required; ...)', $e->getMessage());
            static::assertStringNotContainsString('events.5.', $e->getMessage());
        }
    }

    #[Group('units')]
    public function testNonJsonBodyIsShortened(): void
    {
        try
        {
            $this->client([new Response(502, [], str_repeat('x', 500))])->send(LogType::PlayLog, $this->records());
            static::fail('RetryableIngestException expected');
        }
        catch (RetryableIngestException $e)
        {
            static::assertSame('HTTP 502: ' . str_repeat('x', 200), $e->getMessage());
        }
    }

    #[Group('units')]
    public function testUnreachableApiIsRetryable(): void
    {
        $this->expectException(RetryableIngestException::class);
        $this->expectExceptionMessage('API not reachable');

        $this->client([new ConnectException('Connection refused', new Request('POST', '/v1/playlog'))])->send(LogType::PlayLog, $this->records());
    }

    #[Group('units')]
    public function testMissingKeyOrUrlIsReportedBeforeAnyRequest(): void
    {
        foreach ([['http://api.test', ''], ['', 'key'], ['  ', 'key']] as [$url, $key])
        {
            try
            {
                $this->client([], $url, $key)->send(LogType::PlayLog, $this->records());
                static::fail('RetryableIngestException expected');
            }
            catch (RetryableIngestException $e)
            {
                static::assertStringContainsString('COLLECTOR_API_', $e->getMessage());
            }
            static::assertCount(0, $this->history);
        }
    }

    #[Group('units')]
    public function testEnsureConfiguredMentionsHowToCreateTheKey(): void
    {
        $this->expectException(RetryableIngestException::class);
        $this->expectExceptionMessage('apikey:create collector --scope=ingest');

        $this->client([], 'http://api.test', '')->ensureConfigured();
    }
}
