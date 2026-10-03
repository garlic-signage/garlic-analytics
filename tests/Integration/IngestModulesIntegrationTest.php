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

use App\Framework\Exceptions\ValidationException;
use App\Modules\ConnectLog\ConnectLogService;
use App\Modules\EventLog\EventLogService;
use App\Modules\PlayLog\PlayLogService;
use App\Modules\SystemLog\SystemLogService;
use DateTimeImmutable;
use PHPUnit\Framework\Attributes\Group;

/**
 * The ingesting of the four modules with the services from the real DI definitions, validation included.
 */
#[Group('integration')]
class IngestModulesIntegrationTest extends ClickHouseTestCase
{
    private function time(string $modify = '-1 hour'): string
    {
        return new DateTimeImmutable($modify)->format('Y-m-d\TH:i:sP');
    }

    /**
     * @return array{events: list<array<string,string>>}
     */
    private function playLogBody(): array
    {
        return ['events' => [
            ['player_id' => 'p1', 'content_id' => "c'1", 'start_time' => $this->time('-1 hour'), 'end_time' => $this->time('-1 hour +10 seconds')],
            ['player_id' => 'p1', 'content_id' => 'c2', 'start_time' => $this->time('-1 hour +10 seconds'), 'end_time' => $this->time('-1 hour +40 seconds')],
        ]];
    }

    #[Group('integration')]
    public function testPlayLogIsStoredAndAggregated(): void
    {
        $service = $this->container()->get(PlayLogService::class);
        static::assertInstanceOf(PlayLogService::class, $service);

        static::assertSame(2, $service->ingest($this->playLogBody()));

        static::assertSame(2, $this->rows('SELECT count() AS n FROM play_log')[0]['n']);
        $hourly = $this->rows('SELECT sum(plays) AS plays, sum(duration_s) AS duration FROM play_hourly')[0];
        static::assertSame(2, $hourly['plays']);
        static::assertSame(40, $hourly['duration']);
        static::assertSame(1, $this->rows("SELECT count() AS n FROM play_log WHERE content_id = 'c\\'1'")[0]['n']);
    }

    #[Group('integration')]
    public function testTheSameRequestSentAgainDoesNotCountTwice(): void
    {
        $service = $this->container()->get(PlayLogService::class);
        static::assertInstanceOf(PlayLogService::class, $service);

        $service->ingest($this->playLogBody());
        $service->ingest($this->playLogBody());

        static::assertSame(2, $this->rows('SELECT count() AS n FROM play_log')[0]['n']);
        static::assertSame(2, $this->rows('SELECT sum(plays) AS n FROM play_hourly')[0]['n']);
    }

    #[Group('integration')]
    public function testEventLogStoresTheMetadataAsMap(): void
    {
        $service = $this->container()->get(EventLogService::class);
        static::assertInstanceOf(EventLogService::class, $service);

        $service->ingest(['events' => [
            ['player_id' => 'p1', 'event_time' => $this->time(), 'event_type' => 'warning', 'event_source' => 'ContentManager', 'event_name' => 'FETCH_FAILED',
             'metadata' => ['resourceURI' => 'https://example.com/a.jpg?x=1&y="2"']],
            ['player_id' => 'p1', 'event_time' => $this->time(), 'event_type' => 'informational', 'event_source' => 'System', 'event_name' => 'STARTED'],
        ]]);

        $rows = $this->rows('SELECT event_type, metadata FROM event_log ORDER BY event_type');
        static::assertSame('informational', $rows[0]['event_type']);
        static::assertSame([], $rows[0]['metadata']);
        static::assertSame(['resourceURI' => 'https://example.com/a.jpg?x=1&y="2"'], $rows[1]['metadata']);
    }

    #[Group('integration')]
    public function testSystemLogStoresMissingValuesAsNull(): void
    {
        $service = $this->container()->get(SystemLogService::class);
        static::assertInstanceOf(SystemLogService::class, $service);

        $service->ingest(['events' => [
            ['player_id' => 'p1', 'reported_at' => $this->time(), 'system_start' => $this->time('-3 days'), 'time_zone' => 'MEZ', 'disk_total' => 12245270528, 'disk_free' => 11792592896],
            ['player_id' => 'p2', 'reported_at' => $this->time(), 'system_start' => $this->time('-3 days'), 'time_zone' => 'Europe/Berlin', 'disk_total' => 100, 'disk_free' => 40,
             'cpu_usage' => 31, 'memory_total' => 1048576, 'memory_used' => 969224, 'hdmi_output' => '1920x1080p-60'],
        ]]);

        $rows = $this->rows('SELECT player_id, disk_total, cpu_usage, memory_used, hdmi_output FROM system_log ORDER BY player_id');
        static::assertSame(12245270528, $rows[0]['disk_total']);
        static::assertNull($rows[0]['cpu_usage']);
        static::assertNull($rows[0]['memory_used']);
        static::assertSame('', $rows[0]['hdmi_output']);
        static::assertSame(31, $rows[1]['cpu_usage']);
        static::assertSame('1920x1080p-60', $rows[1]['hdmi_output']);
    }

    #[Group('integration')]
    public function testConnectLogIsAggregatedPerHour(): void
    {
        $service = $this->container()->get(ConnectLogService::class);
        static::assertInstanceOf(ConnectLogService::class, $service);

        $service->ingest(['events' => [
            ['player_id' => 'p1', 'connected_at' => $this->time('-2 hours'), 'refresh' => 300],
            ['player_id' => 'p1', 'connected_at' => $this->time('-2 hours +5 minutes'), 'refresh' => 300],
        ]]);

        static::assertSame(2, $this->rows('SELECT count() AS n FROM connect_log')[0]['n']);
        $hourly = $this->rows('SELECT sum(connects) AS connects, sum(covered_s) AS covered FROM connect_hourly')[0];
        static::assertSame(2, $hourly['connects']);
        static::assertSame(600, $hourly['covered']);
    }

    #[Group('integration')]
    public function testOldConnectsAreAcceptedUpToFourYears(): void
    {
        $service = $this->container()->get(ConnectLogService::class);
        static::assertInstanceOf(ConnectLogService::class, $service);

        $service->ingest(['events' => [['player_id' => 'p1', 'connected_at' => $this->time('-3 years'), 'refresh' => 300]]]);

        static::assertSame(1, $this->rows('SELECT count() AS n FROM connect_log')[0]['n']);
    }

    #[Group('integration')]
    public function testInvalidRequestStoresNothing(): void
    {
        $service = $this->container()->get(PlayLogService::class);
        static::assertInstanceOf(PlayLogService::class, $service);

        $body = $this->playLogBody();
        $body['events'][1]['end_time'] = 'broken';

        try
        {
            $service->ingest($body);
            static::fail('ValidationException expected');
        }
        catch (ValidationException)
        {
            static::assertSame(0, $this->rows('SELECT count() AS n FROM play_log')[0]['n']);
        }
    }
}
