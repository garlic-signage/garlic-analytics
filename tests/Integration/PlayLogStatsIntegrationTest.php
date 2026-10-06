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
 * GET /v1/playlog/stats and GET /v1/playlog/stats/period through the whole application against a real ClickHouse.
 * The plays are inserted into play_log, play_hourly is filled by the real materialized view.
 */
#[Group('integration')]
class PlayLogStatsIntegrationTest extends AppIntegrationTestCase
{
    private const array COLUMNS = ['player_id', 'content_id', 'start_time', 'end_time'];

    protected function setUp(): void
    {
        parent::setUp();

        // The hour 2026-10-01 00:00Z of c-a comes in two inserts: two parts of the aggregate that are not merged yet
        self::client()->insert('play_log', [
            ['p1', 'c-a', '2026-10-01 00:10:00', '2026-10-01 00:10:30'],  // 30 s
            ['p1', 'c-a', '2026-09-30 23:59:59', '2026-10-01 00:00:09'],  // 10 s, the hour before "from"
        ], self::COLUMNS);
        self::client()->insert('play_log', [
            ['p1', 'c-a', '2026-10-01 00:40:00', '2026-10-01 00:41:00'],  // 60 s
            ['p1', 'c-b', '2026-10-01 01:05:00', '2026-10-01 01:05:10'],  // 10 s
            ['p1', 'c-c', '2026-10-01 05:00:00', '2026-10-01 05:01:00'],  // 60 s
            ['p1', 'c-a', '2026-10-01 22:30:00', '2026-10-01 22:32:00'],  // 120 s, 00:30 of 2026-10-02 in Berlin (UTC+2)
            ['p1', 'c-c', '2026-10-01 23:59:55', '2026-10-02 00:00:05'],  // 10 s, starts in the hour 23:00 and counts there
            ['p1', 'c-b', '2026-10-02 10:00:00', '2026-10-02 10:00:20'],  // 20 s
            ['p1', 'c-a', '2026-10-31 23:30:00', '2026-10-31 23:31:00'],  // 60 s, 00:30 of 2026-11-01 in Berlin (UTC+1)
            ['p2', 'c-a', '2026-10-01 00:20:00', '2026-10-01 00:20:15'],  // another player
            ["p'1", 'c-x', '2026-10-01 00:20:00', '2026-10-01 00:20:45'], // special characters in the id
        ], self::COLUMNS);
    }

    /**
     * @param array<string,string> $params
     */
    private function get(string $path, array $params, string $key = self::READ_KEY): ResponseInterface
    {
        $defaults = ['player_id' => 'p1', 'from' => '2026-10-01T00:00:00Z', 'to' => '2026-10-03T00:00:00Z'];
        $request  = new ServerRequestFactory()->createServerRequest('GET', $path)
            ->withQueryParams([...$defaults, ...$params])
            ->withHeader('Authorization', 'Bearer ' . $key);

        return $this->handle($request);
    }

    /**
     * @param array<string,string> $params
     * @return array{total: int, limit: int, offset: int, items: list<array<string,mixed>>}
     */
    private function page(string $path, array $params = []): array
    {
        $response = $this->get($path, $params);
        static::assertSame(200, $response->getStatusCode());

        /** @var array{total: int, limit: int, offset: int, items: list<array<string,mixed>>} $data */
        $data = json_decode((string) $response->getBody(), true, 512, JSON_THROW_ON_ERROR);

        return $data;
    }

    /**
     * @param array{total: int, limit: int, offset: int, items: list<array<string,mixed>>} $page
     * @return array<string,string> content_id or period => "plays/duration_s", in the order of the page
     */
    private function tally(array $page, string $key): array
    {
        $rows = [];
        foreach ($page['items'] as $item)
        {
            $name = $item[$key];
            static::assertIsString($name);
            static::assertIsInt($item['plays']);
            static::assertIsInt($item['duration_s']);
            $rows[$name] = $item['plays'] . '/' . $item['duration_s'];
        }

        return $rows;
    }

    /**
     * @param array<string,string> $params
     * @return array<string,string>
     */
    private function contents(array $params = []): array
    {
        return $this->tally($this->page('/v1/playlog/stats', $params), 'content_id');
    }

    /**
     * @param array<string,string> $params
     * @return array<string,string>
     */
    private function periods(array $params = []): array
    {
        return $this->tally($this->page('/v1/playlog/stats/period', $params), 'period');
    }

    #[Group('integration')]
    public function testPerPlayerHowOftenAndHowLongEachContentWasPlayedSortedByContentId(): void
    {
        $page = $this->page('/v1/playlog/stats');

        static::assertSame(3, $page['total']);
        static::assertSame(100, $page['limit']);
        // c-a: 00:10 and 00:40 (two inserts) and 22:30, not the play of 23:59:59 of the day before and not the one of October 31
        static::assertSame(['c-a' => '3/210', 'c-b' => '2/30', 'c-c' => '2/70'], $this->tally($page, 'content_id'));
    }

    #[Group('integration')]
    public function testSortByPlaysOrDurationAndDescendingOrder(): void
    {
        static::assertSame(['c-a', 'c-b', 'c-c'], array_keys($this->contents(['sort' => 'plays', 'order' => 'desc'])));
        // c-b and c-c have two plays each, the tie is decided by content_id
        static::assertSame(['c-b', 'c-c', 'c-a'], array_keys($this->contents(['sort' => 'plays', 'order' => 'asc'])));
        static::assertSame(['c-a', 'c-c', 'c-b'], array_keys($this->contents(['sort' => 'duration_s', 'order' => 'desc'])));
        static::assertSame(['c-c', 'c-b', 'c-a'], array_keys($this->contents(['order' => 'desc'])));
    }

    #[Group('integration')]
    public function testContentFilterIsOptional(): void
    {
        $page = $this->page('/v1/playlog/stats', ['content_id' => 'c-b']);

        static::assertSame(['c-b' => '2/30'], $this->tally($page, 'content_id'));
        static::assertSame(1, $page['total']);
        static::assertSame([], $this->contents(['content_id' => 'c']));  // exact, no part of a name
    }

    #[Group('integration')]
    public function testPagesFollowEachOtherAndKeepTheTotal(): void
    {
        $first  = $this->page('/v1/playlog/stats', ['limit' => '2', 'sort' => 'plays', 'order' => 'asc']);
        $second = $this->page('/v1/playlog/stats', ['limit' => '2', 'offset' => '2', 'sort' => 'plays', 'order' => 'asc']);

        static::assertSame(['c-b', 'c-c'], array_keys($this->tally($first, 'content_id')));
        static::assertSame(['c-a'], array_keys($this->tally($second, 'content_id')));
        static::assertSame(3, $first['total']);
        static::assertSame(3, $second['total']);
    }

    #[Group('integration')]
    public function testOnlyTheAskedPlayerAndNothingForAnUnknownOne(): void
    {
        static::assertSame(['c-x' => '1/45'], $this->contents(['player_id' => "p'1"]));
        static::assertSame(['c-a' => '1/15'], $this->contents(['player_id' => 'p2']));
        static::assertSame(['total' => 0, 'limit' => 100, 'offset' => 0, 'items' => []], $this->page('/v1/playlog/stats', ['player_id' => 'nobody']));
    }

    #[Group('integration')]
    public function testTheAggregateLivesLongerThanTheRawRecords(): void
    {
        // a row of the aggregate for which play_log has no record any more (the raw table has a TTL, the aggregate not)
        self::client()->insert('play_hourly', [['2020-01-01 10:00:00', 'old', 'p3', 5, 50]], ['hour', 'content_id', 'player_id', 'plays', 'duration_s']);

        static::assertSame(
            ['old' => '5/50'],
            $this->contents(['player_id' => 'p3', 'from' => '2020-01-01T00:00:00Z', 'to' => '2020-01-02T00:00:00Z'])
        );
    }

    #[Group('integration')]
    public function testHoursOfTheTimeSeries(): void
    {
        $page = $this->page('/v1/playlog/stats/period');

        static::assertSame(6, $page['total']);
        static::assertSame(
            [
                '2026-10-02T10:00:00Z' => '1/20',
                '2026-10-01T23:00:00Z' => '1/10', // the play of 23:59:55 starts in this hour and counts completely here
                '2026-10-01T22:00:00Z' => '1/120',
                '2026-10-01T05:00:00Z' => '1/60',
                '2026-10-01T01:00:00Z' => '1/10',
                '2026-10-01T00:00:00Z' => '2/90',  // two plays from two inserts
            ],
            $this->tally($page, 'period')
        );
        static::assertSame(['2026-10-01T00:00:00Z'], array_slice(array_keys($this->periods(['order' => 'asc'])), 0, 1));
    }

    #[Group('integration')]
    public function testDaysAndMonthsInTheTimeZoneOfTheCms(): void
    {
        static::assertSame(['2026-10-02' => '1/20', '2026-10-01' => '6/290'], $this->periods(['resolution' => 'day']));

        $berlin = ['resolution' => 'day', 'time_zone' => 'Europe/Berlin', 'from' => '2026-10-01T00:00:00+02:00', 'to' => '2026-10-04T00:00:00+02:00'];
        static::assertSame(['2026-10-02' => '3/150', '2026-10-01' => '5/170'], $this->periods($berlin));

        $month = ['resolution' => 'month', 'to' => '2026-11-02T00:00:00Z'];
        static::assertSame(['2026-10' => '8/370'], $this->periods($month));
        static::assertSame(
            ['2026-11' => '1/60', '2026-10' => '8/320'],
            $this->periods(['time_zone' => 'Europe/Berlin', 'from' => '2026-10-01T00:00:00+02:00', 'to' => '2026-11-02T00:00:00+01:00'] + $month)
        );
    }

    #[Group('integration')]
    public function testTimeSeriesOfOneContent(): void
    {
        $page = $this->page('/v1/playlog/stats/period', ['content_id' => 'c-a', 'order' => 'asc']);

        static::assertSame(['2026-10-01T00:00:00Z' => '2/90', '2026-10-01T22:00:00Z' => '1/120'], $this->tally($page, 'period'));
        static::assertSame(2, $page['total']);
    }

    #[Group('integration')]
    public function testAHourBelongsToTheRangeByItsStart(): void
    {
        static::assertSame(
            ['2026-10-01T01:00:00Z', '2026-10-01T05:00:00Z'],
            array_slice(array_keys($this->periods(['from' => '2026-10-01T00:30:00Z', 'to' => '2026-10-01T22:00:00Z', 'order' => 'asc'])), 0, 2)
        );
    }

    #[Group('integration')]
    public function testInvalidParametersGive422WithTheErrorsPerParameter(): void
    {
        $response = $this->get('/v1/playlog/stats', ['sort' => 'best', 'content_id' => '', 'limit' => '5000']);

        static::assertSame(422, $response->getStatusCode());
        /** @var array{errors: array<string,string>} $data */
        $data = json_decode((string) $response->getBody(), true, 512, JSON_THROW_ON_ERROR);
        static::assertSame(['limit', 'sort', 'content_id'], array_keys($data['errors']));

        $response = $this->get('/v1/playlog/stats/period', ['resolution' => 'week', 'time_zone' => 'Mars/Base', 'content_id' => str_repeat('x', 129)]);

        static::assertSame(422, $response->getStatusCode());
        /** @var array{errors: array<string,string>} $data */
        $data = json_decode((string) $response->getBody(), true, 512, JSON_THROW_ON_ERROR);
        static::assertSame(['resolution', 'time_zone', 'content_id'], array_keys($data['errors']));
    }

    #[Group('integration')]
    public function testAnIngestKeyMayNotReadAndWithoutKeyIs401(): void
    {
        foreach (['/v1/playlog/stats', '/v1/playlog/stats/period'] as $path)
        {
            static::assertSame(403, $this->get($path, [], self::INGEST_KEY)->getStatusCode());

            $request = new ServerRequestFactory()->createServerRequest('GET', $path)->withQueryParams(['player_id' => 'p1']);
            static::assertSame(401, $this->handle($request)->getStatusCode());
        }
    }
}
