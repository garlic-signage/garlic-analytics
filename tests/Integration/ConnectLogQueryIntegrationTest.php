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
 * GET /v1/connectlog and GET /v1/connectlog/raw through the whole application against a real ClickHouse.
 * The connects are inserted into connect_log, connect_hourly is filled by the real materialized view.
 */
#[Group('integration')]
class ConnectLogQueryIntegrationTest extends AppIntegrationTestCase
{
    private const array COLUMNS = ['player_id', 'connected_at', 'refresh'];

    protected function setUp(): void
    {
        parent::setUp();

        // The hour 2026-10-01 00:00Z comes in two inserts: two parts of the aggregate that are not merged yet
        self::client()->insert('connect_log', [
            ['p1', '2026-10-01 00:10:00', 60],
            ['p1', '2026-09-30 23:59:59', 60],  // the hour before "from"
        ], self::COLUMNS);
        self::client()->insert('connect_log', [
            ['p1', '2026-10-01 00:40:00', 60],
            ['p1', '2026-10-01 01:05:00', 30],
            ['p1', '2026-10-01 22:30:00', 120], // 00:30 of 2026-10-02 in Berlin (UTC+2)
            ['p1', '2026-10-02 10:00:00', 60],
            ['p1', '2026-10-31 23:30:00', 60],  // 00:30 of 2026-11-01 in Berlin (UTC+1 after the clock change)
            ['p2', '2026-10-01 00:20:00', 15],  // another player
            ["p'1", '2026-10-01 00:20:00', 45], // special characters in the id
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
     * @param array<string,string> $params
     * @return array{total: int, limit: int, offset: int, items: list<array<string,mixed>>}
     */
    private function aggregate(array $params = []): array
    {
        return $this->page('/v1/connectlog', $params);
    }

    /**
     * @param array{total: int, limit: int, offset: int, items: list<array<string,mixed>>} $page
     * @return array<string,string> period => "connects/covered_s"
     */
    private function periods(array $page): array
    {
        $periods = [];
        foreach ($page['items'] as $item)
        {
            $period = $item['period'];
            static::assertIsString($period);
            static::assertIsInt($item['connects']);
            static::assertIsInt($item['covered_s']);
            $periods[$period] = $item['connects'] . '/' . $item['covered_s'];
        }

        return $periods;
    }

    #[Group('integration')]
    public function testHoursNewestFirstAndSummedAcrossUnmergedParts(): void
    {
        $page = $this->aggregate();

        static::assertSame(4, $page['total']);
        static::assertSame(100, $page['limit']);
        static::assertSame(
            [
                '2026-10-02T10:00:00Z' => '1/60',
                '2026-10-01T22:00:00Z' => '1/120',
                '2026-10-01T01:00:00Z' => '1/30',
                '2026-10-01T00:00:00Z' => '2/120', // 00:10 and 00:40, from two inserts, but not the 23:59:59 of the day before
            ],
            $this->periods($page)
        );
    }

    #[Group('integration')]
    public function testHoursInAscendingOrder(): void
    {
        $page = $this->aggregate(['order' => 'asc']);

        static::assertSame(
            ['2026-10-01T00:00:00Z', '2026-10-01T01:00:00Z', '2026-10-01T22:00:00Z', '2026-10-02T10:00:00Z'],
            array_keys($this->periods($page))
        );
    }

    #[Group('integration')]
    public function testAHourBelongsToTheRangeByItsStart(): void
    {
        // "from" in the middle of the hour 00:00: that hour starts before "from" and is not included
        $page = $this->aggregate(['from' => '2026-10-01T00:30:00Z', 'order' => 'asc']);
        static::assertSame('2026-10-01T01:00:00Z', $page['items'][0]['period']);

        // "to" exactly at the start of the hour 22:00: that hour is not included
        $page = $this->aggregate(['to' => '2026-10-01T22:00:00Z', 'order' => 'asc']);
        static::assertSame(['2026-10-01T00:00:00Z', '2026-10-01T01:00:00Z'], array_keys($this->periods($page)));
    }

    #[Group('integration')]
    public function testDaysOfUtc(): void
    {
        $page = $this->aggregate(['resolution' => 'day']);

        static::assertSame(['2026-10-02' => '1/60', '2026-10-01' => '4/270'], $this->periods($page));
        static::assertSame(2, $page['total']);
    }

    #[Group('integration')]
    public function testDaysOfTheTimeZoneOfTheCms(): void
    {
        $page = $this->aggregate([
            'resolution' => 'day', 'time_zone' => 'Europe/Berlin',
            'from' => '2026-10-01T00:00:00+02:00', 'to' => '2026-10-04T00:00:00+02:00',
        ]);

        // the connect at 22:30Z is already on 2026-10-02 in Berlin, the one at 23:59:59Z of the day before is on 2026-10-01
        static::assertSame(['2026-10-02' => '2/180', '2026-10-01' => '4/210'], $this->periods($page));
    }

    #[Group('integration')]
    public function testMonthsFollowTheTimeZoneAlsoAcrossTheClockChange(): void
    {
        $berlin = $this->aggregate([
            'resolution' => 'month', 'time_zone' => 'Europe/Berlin',
            'from' => '2026-10-01T00:00:00+02:00', 'to' => '2026-11-02T00:00:00+01:00',
        ]);
        $utc = $this->aggregate(['resolution' => 'month', 'from' => '2026-10-01T00:00:00Z', 'to' => '2026-11-02T00:00:00Z']);

        // in Berlin the connect at 23:30Z of 2026-10-31 is already in November, the one at 23:59:59Z of 2026-09-30 still in October
        static::assertSame(['2026-11' => '1/60', '2026-10' => '6/390'], $this->periods($berlin));
        static::assertSame(['2026-10' => '6/390'], $this->periods($utc));
    }

    #[Group('integration')]
    public function testPagesOfDaysFollowEachOtherAndKeepTheTotal(): void
    {
        $first  = $this->aggregate(['resolution' => 'day', 'limit' => '1', 'order' => 'asc']);
        $second = $this->aggregate(['resolution' => 'day', 'limit' => '1', 'offset' => '1', 'order' => 'asc']);
        $beyond = $this->aggregate(['resolution' => 'day', 'limit' => '1', 'offset' => '5', 'order' => 'asc']);

        static::assertSame(['2026-10-01'], array_keys($this->periods($first)));
        static::assertSame(['2026-10-02'], array_keys($this->periods($second)));
        static::assertSame(2, $first['total']);
        static::assertSame(2, $second['total']);
        static::assertSame([], $beyond['items']);
        static::assertSame(2, $beyond['total']);
    }

    #[Group('integration')]
    public function testOnlyTheAskedPlayerIsReturnedAlsoWithSpecialCharacters(): void
    {
        static::assertSame(['2026-10-01T00:00:00Z' => '1/45'], $this->periods($this->aggregate(['player_id' => "p'1"])));
        static::assertSame(['2026-10-01T00:00:00Z' => '1/15'], $this->periods($this->aggregate(['player_id' => 'p2'])));
    }

    #[Group('integration')]
    public function testUnknownPlayerIsAnEmptyPageNotAnError(): void
    {
        static::assertSame(['total' => 0, 'limit' => 100, 'offset' => 0, 'items' => []], $this->aggregate(['player_id' => 'nobody']));
    }

    #[Group('integration')]
    public function testInvalidResolutionAndTimeZoneGive422(): void
    {
        $response = $this->get('/v1/connectlog', ['resolution' => 'week', 'time_zone' => 'Mars/Base', 'limit' => '5000']);

        static::assertSame(422, $response->getStatusCode());
        /** @var array{errors: array<string,string>} $data */
        $data = json_decode((string) $response->getBody(), true, 512, JSON_THROW_ON_ERROR);
        static::assertSame(['limit', 'resolution', 'time_zone'], array_keys($data['errors']));
    }

    #[Group('integration')]
    public function testTheTimeZoneIsIgnoredForHours(): void
    {
        static::assertSame($this->aggregate(), $this->aggregate(['time_zone' => 'Asia/Tokyo']));
    }

    #[Group('integration')]
    public function testRawConnectsOfOneDayWithTheirRefresh(): void
    {
        $page = $this->page('/v1/connectlog/raw', ['to' => '2026-10-01T02:00:00Z', 'order' => 'asc']);

        static::assertSame(3, $page['total']);
        static::assertSame(
            [
                ['connected_at' => '2026-10-01T00:10:00Z', 'refresh' => 60],
                ['connected_at' => '2026-10-01T00:40:00Z', 'refresh' => 60],
                ['connected_at' => '2026-10-01T01:05:00Z', 'refresh' => 30],
            ],
            $page['items']
        );
    }

    #[Group('integration')]
    public function testRawNewestFirstAndPaged(): void
    {
        $first  = $this->page('/v1/connectlog/raw', ['to' => '2026-10-01T02:00:00Z', 'limit' => '2']);
        $second = $this->page('/v1/connectlog/raw', ['to' => '2026-10-01T02:00:00Z', 'limit' => '2', 'offset' => '2']);

        static::assertSame(['2026-10-01T01:05:00Z', '2026-10-01T00:40:00Z'], array_column($first['items'], 'connected_at'));
        static::assertSame(['2026-10-01T00:10:00Z'], array_column($second['items'], 'connected_at'));
        static::assertSame(3, $second['total']);
    }

    #[Group('integration')]
    public function testRawRangeUpTo25HoursIsAllowedAndMoreIsNot(): void
    {
        static::assertSame(200, $this->get('/v1/connectlog/raw', ['to' => '2026-10-02T01:00:00Z'])->getStatusCode());

        $response = $this->get('/v1/connectlog/raw', ['to' => '2026-10-02T01:00:01Z']);
        static::assertSame(422, $response->getStatusCode());
        /** @var array{errors: array<string,string>} $data */
        $data = json_decode((string) $response->getBody(), true, 512, JSON_THROW_ON_ERROR);
        static::assertSame(['to' => 'must not be more than 25 hours after from'], $data['errors']);
    }

    #[Group('integration')]
    public function testRawOnlyTheAskedPlayerAndNothingForAnUnknownOne(): void
    {
        $day = ['to' => '2026-10-01T12:00:00Z'];

        static::assertSame([['connected_at' => '2026-10-01T00:20:00Z', 'refresh' => 45]], $this->page('/v1/connectlog/raw', ['player_id' => "p'1"] + $day)['items']);
        static::assertSame(0, $this->page('/v1/connectlog/raw', ['player_id' => 'nobody'] + $day)['total']);
    }

    #[Group('integration')]
    public function testAnIngestKeyMayNotReadAndWithoutKeyIs401(): void
    {
        foreach (['/v1/connectlog', '/v1/connectlog/raw'] as $path)
        {
            static::assertSame(403, $this->get($path, [], self::INGEST_KEY)->getStatusCode());

            $request = new ServerRequestFactory()->createServerRequest('GET', $path)->withQueryParams(['player_id' => 'p1']);
            static::assertSame(401, $this->handle($request)->getStatusCode());
        }
    }
}
