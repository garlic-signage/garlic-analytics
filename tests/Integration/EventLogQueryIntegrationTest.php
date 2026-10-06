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

use PHPUnit\Framework\Attributes\Group;
use Psr\Http\Message\ResponseInterface;
use Slim\Psr7\Factory\ServerRequestFactory;

/**
 * GET /v1/eventlog through the whole application against a real ClickHouse.
 */
#[Group('integration')]
class EventLogQueryIntegrationTest extends AppIntegrationTestCase
{
    private const array COLUMNS = ['player_id', 'event_time', 'event_type', 'event_source', 'event_name', 'metadata'];

    protected function setUp(): void
    {
        parent::setUp();

        self::client()->insert('event_log', [
            ['p1', '2026-10-01 00:00:00', 'informational', 'src', 'e1', []],                    // exactly "from"
            ['p1', '2026-10-01 06:00:00', 'warning', 'ContentManager', 'e2', ['errorMessage' => 'Host not found']],
            ['p1', '2026-10-01 12:00:00', 'error', 'src', 'e3', []],
            ['p1', '2026-10-01 18:00:00', 'fatal', 'src', 'e4', []],
            ['p1', '2026-10-02 00:00:00', 'debug', 'src', 'e5', []],                                                  // exactly "to", not included
            ['p1', '2026-09-30 23:59:59', 'debug', 'src', 'e0', []],                                                  // before "from"
            ['p2', '2026-10-01 12:00:00', 'notice', 'src', 'ex', []],                                                 // another player
            ["p'1", '2026-10-01 12:00:00', 'critical', 'src', 'q1', []],                                              // special characters in the id
        ], self::COLUMNS);
    }

    /**
     * @param array<string,string> $params
     */
    private function get(array $params, string $key = self::READ_KEY): ResponseInterface
    {
        $defaults = ['player_id' => 'p1', 'from' => '2026-10-01T00:00:00Z', 'to' => '2026-10-02T00:00:00Z'];
        $request  = new ServerRequestFactory()->createServerRequest('GET', '/v1/eventlog')
            ->withQueryParams([...$defaults, ...$params])
            ->withHeader('Authorization', 'Bearer ' . $key);

        return $this->handle($request);
    }

    /**
     * @param array<string,string> $params
     * @return array{total: int, limit: int, offset: int, items: list<array<string,mixed>>}
     */
    private function page(array $params = []): array
    {
        $response = $this->get($params);
        static::assertSame(200, $response->getStatusCode());

        /** @var array{total: int, limit: int, offset: int, items: list<array<string,mixed>>} $data */
        $data = json_decode((string) $response->getBody(), true, 512, JSON_THROW_ON_ERROR);

        return $data;
    }

    /**
     * @param array{total: int, limit: int, offset: int, items: list<array<string,mixed>>} $page
     * @return list<mixed>
     */
    private function names(array $page): array
    {
        return array_column($page['items'], 'event_name');
    }

    #[Group('integration')]
    public function testNewestFirstWithTotalAndTheTimeRangeHalfOpen(): void
    {
        $page = $this->page();

        static::assertSame(4, $page['total']); // e1..e4, not e5 (= to) and not e0 (before from)
        static::assertSame(100, $page['limit']);
        static::assertSame(0, $page['offset']);
        static::assertSame(['e4', 'e3', 'e2', 'e1'], $this->names($page));
        static::assertSame(
            ['event_time' => '2026-10-01T06:00:00Z', 'event_type' => 'warning', 'event_source' => 'ContentManager', 'event_name' => 'e2', 'metadata' => ['errorMessage' => 'Host not found']],
            $page['items'][2]
        );
    }

    #[Group('integration')]
    public function testMetadataIsAlwaysAJsonObject(): void
    {
        $response = $this->get(['order' => 'asc', 'limit' => '1']);

        static::assertStringContainsString('"metadata":{}', (string) $response->getBody());
    }

    #[Group('integration')]
    public function testAscendingOrder(): void
    {
        static::assertSame(['e1', 'e2', 'e3', 'e4'], $this->names($this->page(['order' => 'asc'])));
    }

    #[Group('integration')]
    public function testPagesFollowEachOtherWithoutGapsAndKeepTheTotal(): void
    {
        $first  = $this->page(['limit' => '3', 'order' => 'asc']);
        $second = $this->page(['limit' => '3', 'offset' => '3', 'order' => 'asc']);
        $beyond = $this->page(['limit' => '3', 'offset' => '30', 'order' => 'asc']);

        static::assertSame(['e1', 'e2', 'e3'], $this->names($first));
        static::assertSame(['e4'], $this->names($second));
        static::assertSame(4, $first['total']);
        static::assertSame(4, $second['total']);
        static::assertSame(3, $second['offset']);
        static::assertSame([], $beyond['items']);
        static::assertSame(4, $beyond['total']);
    }

    #[Group('integration')]
    public function testTimesWithAnOffsetAreConvertedToUtc(): void
    {
        // 14:00+02:00 = 12:00Z up to 20:00+02:00 = 18:00Z: e3 only (e4 happens at 18:00Z, which is excluded)
        $page = $this->page(['from' => '2026-10-01T14:00:00+02:00', 'to' => '2026-10-01T20:00:00+02:00']);

        static::assertSame(['e3'], $this->names($page));
    }

    #[Group('integration')]
    public function testOnlyTheAskedPlayerIsReturnedAlsoWithSpecialCharacters(): void
    {
        static::assertSame(['q1'], $this->names($this->page(['player_id' => "p'1"])));
        static::assertSame(['ex'], $this->names($this->page(['player_id' => 'p2'])));
    }

    #[Group('integration')]
    public function testUnknownPlayerIsAnEmptyPageNotAnError(): void
    {
        static::assertSame(['total' => 0, 'limit' => 100, 'offset' => 0, 'items' => []], $this->page(['player_id' => 'nobody']));
    }

    #[Group('integration')]
    public function testInvalidParametersGive422WithTheErrorsPerParameter(): void
    {
        $response = $this->get(['limit' => '5000', 'order' => 'up', 'from' => 'yesterday']);

        static::assertSame(422, $response->getStatusCode());
        /** @var array{error: string, errors: array<string,string>} $data */
        $data = json_decode((string) $response->getBody(), true, 512, JSON_THROW_ON_ERROR);
        static::assertSame('Validation failed', $data['error']);
        static::assertSame(['from', 'limit', 'order'], array_keys($data['errors']));
    }

    #[Group('integration')]
    public function testAnIngestKeyMayNotRead(): void
    {
        static::assertSame(403, $this->get([], self::INGEST_KEY)->getStatusCode());
    }

    #[Group('integration')]
    public function testWithoutKeyIs401(): void
    {
        $request = new ServerRequestFactory()->createServerRequest('GET', '/v1/eventlog')->withQueryParams(['player_id' => 'p1']);

        static::assertSame(401, $this->handle($request)->getStatusCode());
    }
}
