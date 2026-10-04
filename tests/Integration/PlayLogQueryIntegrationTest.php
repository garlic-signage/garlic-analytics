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
 * GET /v1/playlog through the whole application against a real ClickHouse.
 */
#[Group('integration')]
class PlayLogQueryIntegrationTest extends AppIntegrationTestCase
{
    private const array COLUMNS = ['player_id', 'content_id', 'start_time', 'end_time'];

    protected function setUp(): void
    {
        parent::setUp();

        self::client()->insert('play_log', [
            ['p1', 'c1', '2026-10-01 00:00:00', '2026-10-01 00:00:10'],  // exactly "from"
            ['p1', 'c2', '2026-10-01 06:00:00', '2026-10-01 06:00:30'],
            ['p1', 'c3', '2026-10-01 12:00:00', '2026-10-01 12:01:00'],
            ['p1', 'c4', '2026-10-01 18:00:00', '2026-10-01 18:00:05'],
            ['p1', 'c5', '2026-10-02 00:00:00', '2026-10-02 00:00:10'],  // exactly "to", not included
            ['p1', 'c0', '2026-09-30 23:59:59', '2026-10-01 00:00:05'],  // before "from"
            ['p2', 'cx', '2026-10-01 12:00:00', '2026-10-01 12:00:10'],  // another player
            ["p'1", 'q1', '2026-10-01 12:00:00', '2026-10-01 12:00:20'], // special characters in the id
        ], self::COLUMNS);
    }

    /**
     * @param array<string,string> $params
     */
    private function get(array $params, string $key = self::READ_KEY): ResponseInterface
    {
        $defaults = ['player_id' => 'p1', 'from' => '2026-10-01T00:00:00Z', 'to' => '2026-10-02T00:00:00Z'];
        $request  = new ServerRequestFactory()->createServerRequest('GET', '/v1/playlog')
            ->withQueryParams([...$defaults, ...$params])
            ->withHeader('Authorization', 'Bearer ' . $key);

        return $this->handle($request);
    }

    /**
     * @param array<string,string> $params
     * @return array{total: int, limit: int, offset: int, items: list<array<string,int|string>>}
     */
    private function page(array $params = []): array
    {
        $response = $this->get($params);
        static::assertSame(200, $response->getStatusCode());

        /** @var array{total: int, limit: int, offset: int, items: list<array<string,int|string>>} $data */
        $data = json_decode((string) $response->getBody(), true, 512, JSON_THROW_ON_ERROR);

        return $data;
    }

    /**
     * @param array{total: int, limit: int, offset: int, items: list<array<string,int|string>>} $page
     * @return list<int|string>
     */
    private function contentIds(array $page): array
    {
        return array_column($page['items'], 'content_id');
    }

    #[Group('integration')]
    public function testNewestFirstWithTotalAndTheTimeRangeHalfOpen(): void
    {
        $page = $this->page();

        static::assertSame(4, $page['total']); // c1..c4, not c5 (= to) and not c0 (before from)
        static::assertSame(100, $page['limit']);
        static::assertSame(0, $page['offset']);
        static::assertSame(['c4', 'c3', 'c2', 'c1'], $this->contentIds($page));
        static::assertSame(
            ['content_id' => 'c3', 'start_time' => '2026-10-01T12:00:00Z', 'end_time' => '2026-10-01T12:01:00Z', 'duration_s' => 60],
            $page['items'][1]
        );
    }

    #[Group('integration')]
    public function testAscendingOrder(): void
    {
        static::assertSame(['c1', 'c2', 'c3', 'c4'], $this->contentIds($this->page(['order' => 'asc'])));
    }

    #[Group('integration')]
    public function testPagesFollowEachOtherWithoutGapsAndKeepTheTotal(): void
    {
        $first  = $this->page(['limit' => '3', 'order' => 'asc']);
        $second = $this->page(['limit' => '3', 'offset' => '3', 'order' => 'asc']);
        $beyond = $this->page(['limit' => '3', 'offset' => '30', 'order' => 'asc']);

        static::assertSame(['c1', 'c2', 'c3'], $this->contentIds($first));
        static::assertSame(['c4'], $this->contentIds($second));
        static::assertSame(4, $first['total']);
        static::assertSame(4, $second['total']);
        static::assertSame(3, $second['offset']);
        static::assertSame([], $beyond['items']);
        static::assertSame(4, $beyond['total']);
    }

    #[Group('integration')]
    public function testTimesWithAnOffsetAreConvertedToUtc(): void
    {
        // 14:00+02:00 = 12:00Z up to 20:00+02:00 = 18:00Z: c3 only (c4 starts at 18:00Z, which is excluded)
        $page = $this->page(['from' => '2026-10-01T14:00:00+02:00', 'to' => '2026-10-01T20:00:00+02:00']);

        static::assertSame(['c3'], $this->contentIds($page));
    }

    #[Group('integration')]
    public function testOnlyTheAskedPlayerIsReturnedAlsoWithSpecialCharacters(): void
    {
        static::assertSame(['q1'], $this->contentIds($this->page(['player_id' => "p'1"])));
        static::assertSame(['cx'], $this->contentIds($this->page(['player_id' => 'p2'])));
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
        $request = new ServerRequestFactory()->createServerRequest('GET', '/v1/playlog')->withQueryParams(['player_id' => 'p1']);

        static::assertSame(401, $this->handle($request)->getStatusCode());
    }
}
