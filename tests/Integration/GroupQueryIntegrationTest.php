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
use Slim\Psr7\Factory\StreamFactory;

/**
 * The queries of a group of players (POST with the IDs in the body) through the whole application against a real
 * ClickHouse: POST /v1/playlog/stats/group, /v1/playlog/stats/group/period and /v1/connectlog/group.
 */
#[Group('integration')]
class GroupQueryIntegrationTest extends AppIntegrationTestCase
{
    private const string CONTENTS = '/v1/playlog/stats/group';
    private const string PERIODS  = '/v1/playlog/stats/group/period';
    private const string CONNECTS = '/v1/connectlog/group';

    protected function setUp(): void
    {
        parent::setUp();

        $plays = ['player_id', 'content_id', 'start_time', 'end_time'];
        self::client()->insert('play_log', [
            ['g1', 'c-a', '2026-10-01 10:00:00', '2026-10-01 10:00:30'],  // 30 s
            ['g1', 'c-b', '2026-10-01 11:00:00', '2026-10-01 11:00:10'],  // 10 s
        ], $plays);
        self::client()->insert('play_log', [
            ['g2', 'c-a', '2026-10-01 10:30:00', '2026-10-01 10:31:00'],  // 60 s, the same hour as g1
            ['g2', 'c-a', '2026-10-02 09:00:00', '2026-10-02 09:00:20'],  // 20 s
            ['g3', 'c-c', '2026-10-01 12:00:00', '2026-10-01 12:00:05'],  // 5 s
            ['o1', 'c-a', '2026-10-01 10:15:00', '2026-10-01 10:16:40'],  // 100 s, a player that is in no group
        ], $plays);

        $connects = ['player_id', 'connected_at', 'refresh'];
        self::client()->insert('connect_log', [
            ['g1', '2026-10-01 10:00:00', 60],
            ['g1', '2026-10-01 10:30:00', 60],
        ], $connects);
        self::client()->insert('connect_log', [
            ['g2', '2026-10-01 10:10:00', 30],
            ['g3', '2026-10-01 11:00:00', 60],
            ['o1', '2026-10-01 10:05:00', 15],
        ], $connects);
    }

    /**
     * @param array<string,mixed> $body
     */
    private function post(string $path, array $body, string $key = self::READ_KEY): ResponseInterface
    {
        $defaults = ['player_ids' => ['g1', 'g2'], 'from' => '2026-10-01T00:00:00Z', 'to' => '2026-10-03T00:00:00Z'];
        $request  = new ServerRequestFactory()->createServerRequest('POST', $path)
            ->withHeader('Content-Type', 'application/json')
            ->withHeader('Authorization', 'Bearer ' . $key)
            ->withBody(new StreamFactory()->createStream(json_encode([...$defaults, ...$body], JSON_THROW_ON_ERROR)));

        return $this->handle($request);
    }

    /**
     * @param array<string,mixed> $body
     * @return array{total: int, limit: int, offset: int, items: list<array<string,mixed>>}
     */
    private function page(string $path, array $body = []): array
    {
        $response = $this->post($path, $body);
        static::assertSame(200, $response->getStatusCode(), (string) $response->getBody());

        /** @var array{total: int, limit: int, offset: int, items: list<array<string,mixed>>} $data */
        $data = json_decode((string) $response->getBody(), true, 512, JSON_THROW_ON_ERROR);

        return $data;
    }

    /**
     * @param array{total: int, limit: int, offset: int, items: list<array<string,mixed>>} $page
     * @param list<string> $numbers the names of the numbers of an item
     * @return array<string,string> the key of the item => its numbers joined with "/"
     */
    private function tally(array $page, string $key, array $numbers): array
    {
        $rows = [];
        foreach ($page['items'] as $item)
        {
            $name = $item[$key];
            static::assertIsString($name);
            $rows[$name] = implode('/', array_map(static fn(string $number): string => (string) (is_int($item[$number]) ? $item[$number] : -1), $numbers));
        }

        return $rows;
    }

    /**
     * @param array<string,mixed> $body
     * @return array<string,string>
     */
    private function contents(array $body = []): array
    {
        return $this->tally($this->page(self::CONTENTS, $body), 'content_id', ['plays', 'duration_s']);
    }

    /**
     * @param array<string,mixed> $body
     * @return array<string,string>
     */
    private function periods(string $path, array $body, string $numbers = 'plays'): array
    {
        return $this->tally($this->page($path, $body), 'period', $numbers === 'plays' ? ['plays', 'duration_s'] : ['connects', 'covered_s']);
    }

    #[Group('integration')]
    public function testContentsOfTheGroupAreSummedOverItsPlayers(): void
    {
        $page = $this->page(self::CONTENTS);

        static::assertSame(2, $page['total']);
        // c-a: g1 at 10:00 and g2 at 10:30 (the same hour) and 10-02, not the player o1 that is not in the group
        static::assertSame(['c-a' => '3/110', 'c-b' => '1/10'], $this->tally($page, 'content_id', ['plays', 'duration_s']));
    }

    #[Group('integration')]
    public function testOnlyThePlayersOfTheGroupCountAndDuplicatesAndUnknownIdsDoNoHarm(): void
    {
        static::assertSame(['c-a' => '1/30', 'c-b' => '1/10'], $this->contents(['player_ids' => ['g1']]));
        static::assertSame(['c-a' => '3/110', 'c-b' => '1/10', 'c-c' => '1/5'], $this->contents(['player_ids' => ['g1', 'g2', 'g3', 'g1', 'nobody']]));
        static::assertSame(['total' => 0, 'limit' => 100, 'offset' => 0, 'items' => []], $this->page(self::CONTENTS, ['player_ids' => ['nobody']]));
    }

    #[Group('integration')]
    public function testSortContentFilterAndPagesOfTheGroup(): void
    {
        $everyone = ['player_ids' => ['g1', 'g2', 'g3']];

        static::assertSame(['c-a', 'c-b', 'c-c'], array_keys($this->contents($everyone)));                                     // default: content_id ascending
        static::assertSame(['c-a', 'c-b', 'c-c'], array_keys($this->contents($everyone + ['sort' => 'duration_s', 'order' => 'desc']))); // 110 s, 10 s, 5 s
        static::assertSame(['c-c', 'c-b', 'c-a'], array_keys($this->contents($everyone + ['sort' => 'duration_s', 'order' => 'asc'])));
        static::assertSame(['c-b' => '1/10'], $this->contents($everyone + ['content_id' => 'c-b']));

        $second = $this->page(self::CONTENTS, $everyone + ['limit' => 1, 'offset' => 1]);   // limit and offset as JSON integers
        static::assertSame(['c-b'], array_keys($this->tally($second, 'content_id', ['plays'])));
        static::assertSame(3, $second['total']);
        static::assertSame(1, $second['limit']);
        static::assertSame(1, $second['offset']);

        static::assertSame(['c-b'], array_keys($this->contents($everyone + ['limit' => '1', 'offset' => '1']))); // and as strings
    }

    #[Group('integration')]
    public function testPlaysOfTheGroupPerPeriod(): void
    {
        static::assertSame(
            ['2026-10-02T09:00:00Z' => '1/20', '2026-10-01T11:00:00Z' => '1/10', '2026-10-01T10:00:00Z' => '2/90'],
            $this->periods(self::PERIODS, [])
        );
        static::assertSame(['2026-10-02' => '1/20', '2026-10-01' => '3/100'], $this->periods(self::PERIODS, ['resolution' => 'day']));
        static::assertSame(['2026-10' => '4/120'], $this->periods(self::PERIODS, ['resolution' => 'month', 'time_zone' => 'Europe/Berlin']));
        static::assertSame(
            ['2026-10-02T09:00:00Z' => '1/20', '2026-10-01T10:00:00Z' => '2/90'],
            $this->periods(self::PERIODS, ['content_id' => 'c-a'])
        );
    }

    #[Group('integration')]
    public function testConnectsOfTheGroupPerPeriod(): void
    {
        $page = $this->page(self::CONNECTS, ['resolution' => 'hour']);

        static::assertSame(1, $page['total']);
        static::assertSame(['2026-10-01T10:00:00Z' => '3/150'], $this->tally($page, 'period', ['connects', 'covered_s'])); // g1 twice and g2, not o1
        static::assertSame(['2026-10-01' => '4/210'], $this->periods(self::CONNECTS, ['resolution' => 'day', 'player_ids' => ['g1', 'g2', 'g3']], 'connects'));
    }

    #[Group('integration')]
    public function testAGroupOfExactlyThe1000AllowedPlayersWorksAndOneMoreIsRejected(): void
    {
        $ids = ['g1', 'g2'];
        for ($i = count($ids); $i < 1000; $i++)
            $ids[] = 'player-with-a-long-id-like-a-uuid-' . str_pad((string) $i, 8, '0', STR_PAD_LEFT);

        static::assertSame(['c-a' => '3/110', 'c-b' => '1/10'], $this->contents(['player_ids' => $ids]));
        static::assertSame(['2026-10-01T10:00:00Z' => '3/150'], $this->periods(self::CONNECTS, ['player_ids' => $ids], 'connects'));

        $ids[]    = 'one-more';
        $response = $this->post(self::CONTENTS, ['player_ids' => $ids]);
        static::assertSame(422, $response->getStatusCode());
        static::assertStringContainsString('must not contain more than 1000 player IDs', (string) $response->getBody());
    }

    #[Group('integration')]
    public function testInvalidPlayersAndParametersGive422WithTheErrorsPerParameter(): void
    {
        foreach ([self::CONTENTS, self::PERIODS, self::CONNECTS] as $path)
        {
            $response = $this->post($path, ['player_ids' => ['g1', '', 5], 'limit' => 5000, 'from' => 'yesterday']);

            static::assertSame(422, $response->getStatusCode());
            /** @var array{errors: array<string,string>} $data */
            $data = json_decode((string) $response->getBody(), true, 512, JSON_THROW_ON_ERROR);
            static::assertSame(['player_ids.1', 'player_ids.2', 'from', 'limit'], array_keys($data['errors']));
        }

        foreach (['player_ids' => 'g1', 'empty' => [], 'object' => ['a' => 'g1']] as $name => $value)
        {
            $response = $this->post(self::CONTENTS, ['player_ids' => $value]);
            static::assertSame(422, $response->getStatusCode(), $name);
        }

        static::assertStringContainsString('"player_ids":"is required"', (string) $this->postRaw(self::CONTENTS, '{"from":"2026-10-01T00:00:00Z","to":"2026-10-02T00:00:00Z"}')->getBody());
    }

    #[Group('integration')]
    public function testABodyThatIsNoJsonObjectGives422(): void
    {
        $response = $this->postRaw(self::CONTENTS, '');

        static::assertSame(422, $response->getStatusCode());
        static::assertStringContainsString('"body":"must be a JSON object"', (string) $response->getBody());
    }

    #[Group('integration')]
    public function testPeriodParametersAreCheckedLikeForOnePlayer(): void
    {
        $response = $this->post(self::PERIODS, ['resolution' => 'week', 'time_zone' => 'Mars/Base', 'content_id' => '']);

        static::assertSame(422, $response->getStatusCode());
        /** @var array{errors: array<string,string>} $data */
        $data = json_decode((string) $response->getBody(), true, 512, JSON_THROW_ON_ERROR);
        static::assertSame(['resolution', 'time_zone', 'content_id'], array_keys($data['errors']));
    }

    #[Group('integration')]
    public function testTheScopeReadIsEnoughAndIngestIsNot(): void
    {
        foreach ([self::CONTENTS, self::PERIODS, self::CONNECTS] as $path)
        {
            static::assertSame(200, $this->post($path, [])->getStatusCode(), $path);
            static::assertSame(403, $this->post($path, [], self::INGEST_KEY)->getStatusCode(), $path);

            $request = new ServerRequestFactory()->createServerRequest('POST', $path)->withHeader('Content-Type', 'application/json');
            static::assertSame(401, $this->handle($request)->getStatusCode(), $path);
        }
    }

    #[Group('integration')]
    public function testIngestRoutesStillNeedTheIngestScope(): void
    {
        $body = '{"events":[{"player_id":"g9","content_id":"c-z","start_time":"2026-10-01T10:00:00Z","end_time":"2026-10-01T10:00:10Z"}]}';

        static::assertSame(403, $this->postRaw('/v1/playlog', $body, self::READ_KEY)->getStatusCode());
        static::assertSame(201, $this->postRaw('/v1/playlog', $body, self::INGEST_KEY)->getStatusCode());
    }

    #[Group('integration')]
    public function testTheGroupRoutesAreOnlyForPost(): void
    {
        foreach ([self::CONTENTS, self::PERIODS, self::CONNECTS] as $path)
        {
            $request = new ServerRequestFactory()->createServerRequest('GET', $path)->withHeader('Authorization', 'Bearer ' . self::READ_KEY);

            static::assertSame(405, $this->handle($request)->getStatusCode(), $path);
        }
    }

    private function postRaw(string $path, string $body, string $key = self::READ_KEY): ResponseInterface
    {
        $request = new ServerRequestFactory()->createServerRequest('POST', $path)
            ->withHeader('Content-Type', 'application/json')
            ->withHeader('Authorization', 'Bearer ' . $key)
            ->withBody(new StreamFactory()->createStream($body));

        return $this->handle($request);
    }
}
