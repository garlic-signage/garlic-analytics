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
 * GET /v1/systemlog through the whole application against a real ClickHouse.
 */
#[Group('integration')]
class SystemLogQueryIntegrationTest extends AppIntegrationTestCase
{
    private const array COLUMNS = [
        'player_id', 'reported_at', 'system_start', 'time_zone', 'disk_total', 'disk_free',
        'cpu_usage', 'memory_total', 'memory_used', 'hdmi_output',
    ];

    protected function setUp(): void
    {
        parent::setUp();

        $start = '2026-09-20 05:12:03';
        self::client()->insert('system_log', [
            ['p1', '2026-10-01 00:00:00', $start, 'Europe/Berlin', 100, 10, 10, 1000, 100, 'HDMI-1'],              // exactly "from"
            ['p1', '2026-10-01 06:00:00', $start, 'Europe/Berlin', 100, 20, 20, 1000, 200, 'HDMI-1'],
            ['p1', '2026-10-01 12:00:00', $start, 'MEZ', 100, 30, null, null, null, ''],                           // no cpu, memory, hdmi
            ['p1', '2026-10-01 18:00:00', $start, 'Europe/Berlin', PHP_INT_MAX, 40, 0, 0, 0, ''],    // big bytes, zero is a value
            ['p1', '2026-10-02 00:00:00', $start, 'Europe/Berlin', 100, 50, 50, 1000, 500, ''],                    // exactly "to", not included
            ['p1', '2026-09-30 23:59:59', $start, 'Europe/Berlin', 100, 5, 5, 1000, 50, ''],                      // before "from"
            ['p2', '2026-10-01 12:00:00', $start, 'Europe/Berlin', 100, 60, 60, 1000, 600, ''],                    // another player
            ["p'1", '2026-10-01 12:00:00', $start, 'Europe/Berlin', 100, 70, 70, 1000, 700, ''],                   // special characters in the id
        ], self::COLUMNS);
    }

    /**
     * @param array<string,string> $params
     */
    private function get(array $params, string $key = self::READ_KEY): ResponseInterface
    {
        $defaults = ['player_id' => 'p1', 'from' => '2026-10-01T00:00:00Z', 'to' => '2026-10-02T00:00:00Z'];
        $request  = new ServerRequestFactory()->createServerRequest('GET', '/v1/systemlog')
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
     * The free disk space identifies a report in these tests.
     *
     * @param array{total: int, limit: int, offset: int, items: list<array<string,mixed>>} $page
     * @return list<mixed>
     */
    private function reports(array $page): array
    {
        return array_column($page['items'], 'disk_free');
    }

    #[Group('integration')]
    public function testNewestFirstWithTotalAndTheTimeRangeHalfOpen(): void
    {
        $page = $this->page();

        static::assertSame(4, $page['total']); // 10..40, not 50 (= to) and not 5 (before from)
        static::assertSame(100, $page['limit']);
        static::assertSame(0, $page['offset']);
        static::assertSame([40, 30, 20, 10], $this->reports($page));
        static::assertSame(
            [
                'reported_at' => '2026-10-01T06:00:00Z', 'system_start' => '2026-09-20T05:12:03Z', 'time_zone' => 'Europe/Berlin',
                'disk_total' => 100, 'disk_free' => 20, 'cpu_usage' => 20, 'memory_total' => 1000, 'memory_used' => 200, 'hdmi_output' => 'HDMI-1',
            ],
            $page['items'][2]
        );
    }

    #[Group('integration')]
    public function testMissingMeasurementsAreNullAndZeroStaysZero(): void
    {
        $items = $this->page()['items'];

        static::assertSame(0, $items[0]['cpu_usage']);        // report 40: measured 0
        static::assertSame(0, $items[0]['memory_used']);
        static::assertNull($items[1]['cpu_usage']);           // report 30: not reported
        static::assertNull($items[1]['memory_total']);
        static::assertNull($items[1]['memory_used']);
        static::assertSame('', $items[1]['hdmi_output']);
        static::assertSame('MEZ', $items[1]['time_zone']);
    }

    #[Group('integration')]
    public function testBytesAreJsonNumbersAlsoForBigValues(): void
    {
        $response = $this->get(['order' => 'desc', 'limit' => '1']);

        static::assertStringContainsString('"disk_total":9223372036854775807', (string) $response->getBody());
    }

    #[Group('integration')]
    public function testAscendingOrder(): void
    {
        static::assertSame([10, 20, 30, 40], $this->reports($this->page(['order' => 'asc'])));
    }

    #[Group('integration')]
    public function testPagesFollowEachOtherWithoutGapsAndKeepTheTotal(): void
    {
        $first  = $this->page(['limit' => '3', 'order' => 'asc']);
        $second = $this->page(['limit' => '3', 'offset' => '3', 'order' => 'asc']);
        $beyond = $this->page(['limit' => '3', 'offset' => '30', 'order' => 'asc']);

        static::assertSame([10, 20, 30], $this->reports($first));
        static::assertSame([40], $this->reports($second));
        static::assertSame(4, $first['total']);
        static::assertSame(4, $second['total']);
        static::assertSame(3, $second['offset']);
        static::assertSame([], $beyond['items']);
        static::assertSame(4, $beyond['total']);
    }

    #[Group('integration')]
    public function testTimesWithAnOffsetAreConvertedToUtc(): void
    {
        // 14:00+02:00 = 12:00Z up to 20:00+02:00 = 18:00Z: report 30 only (report 40 is at 18:00Z, which is excluded)
        $page = $this->page(['from' => '2026-10-01T14:00:00+02:00', 'to' => '2026-10-01T20:00:00+02:00']);

        static::assertSame([30], $this->reports($page));
    }

    #[Group('integration')]
    public function testOnlyTheAskedPlayerIsReturnedAlsoWithSpecialCharacters(): void
    {
        static::assertSame([70], $this->reports($this->page(['player_id' => "p'1"])));
        static::assertSame([60], $this->reports($this->page(['player_id' => 'p2'])));
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
        $request = new ServerRequestFactory()->createServerRequest('GET', '/v1/systemlog')->withQueryParams(['player_id' => 'p1']);

        static::assertSame(401, $this->handle($request)->getStatusCode());
    }
}
