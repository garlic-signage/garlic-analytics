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

use App\Framework\Exceptions\DatabaseException;
use PHPUnit\Framework\Attributes\Group;

#[Group('integration')]
class ClickHouseClientIntegrationTest extends ClickHouseTestCase
{
    private const array EVENT_COLUMNS = ['player_id', 'event_time', 'event_type', 'event_source', 'event_name', 'metadata'];

    #[Group('integration')]
    public function testMapEnumAndSpecialCharactersSurviveTheRoundTrip(): void
    {
        $text = "it's \"quoted\" back\\slash ä€ 日本\nsecond line";

        /** @var array<string,string> $map */
        $map = ['k' => $text, '0' => 'v']; // PHP turns the key "0" into an integer

        self::client()->insert('event_log', [['p\'1', '2026-10-03 10:00:00', 'warning', 'source', 'name', $map]], self::EVENT_COLUMNS);

        $rows = $this->rows('SELECT player_id, event_type, metadata FROM event_log');
        static::assertCount(1, $rows);
        static::assertSame('p\'1', $rows[0]['player_id']);
        static::assertSame('warning', $rows[0]['event_type']);
        static::assertSame(['k' => $text, '0' => 'v'], $rows[0]['metadata']);
    }

    #[Group('integration')]
    public function testEmptyMapAndNullAreStored(): void
    {
        self::client()->insert('event_log', [['p', '2026-10-03 10:00:00', 'debug', 's', 'n', []]], self::EVENT_COLUMNS);
        self::client()->insert(
            'system_log',
            [['p', '2026-10-03 10:00:00', '2026-10-03 01:00:00', 'MEZ', 12245270528, 40, null, null, null, '']],
            ['player_id', 'reported_at', 'system_start', 'time_zone', 'disk_total', 'disk_free', 'cpu_usage', 'memory_total', 'memory_used', 'hdmi_output']
        );

        static::assertSame([], $this->rows('SELECT metadata FROM event_log')[0]['metadata']);
        $system = $this->rows('SELECT cpu_usage, memory_total, memory_used, hdmi_output, disk_total FROM system_log')[0];
        static::assertNull($system['cpu_usage']);
        static::assertNull($system['memory_total']);
        static::assertNull($system['memory_used']);
        static::assertSame('', $system['hdmi_output']);
        static::assertSame(12245270528, $system['disk_total']);
    }

    #[Group('integration')]
    public function testTheSameInsertWithTheSameTokenIsDroppedAlsoInTheHourlyTable(): void
    {
        $rows    = [['p1', 'c1', '2026-10-03 10:00:00', '2026-10-03 10:00:10']];
        $columns = ['player_id', 'content_id', 'start_time', 'end_time'];

        self::client()->insert('play_log', $rows, $columns, 'token-1');
        self::client()->insert('play_log', $rows, $columns, 'token-1');

        static::assertSame(1, $this->rows('SELECT count() AS n FROM play_log')[0]['n']);
        static::assertSame(1, $this->rows('SELECT sum(plays) AS n FROM play_hourly')[0]['n']);
    }

    #[Group('integration')]
    public function testAnotherTokenOrNoTokenWritesAgain(): void
    {
        $rows    = [['p1', 'c1', '2026-10-03 10:00:00', '2026-10-03 10:00:10']];
        $columns = ['player_id', 'content_id', 'start_time', 'end_time'];

        self::client()->insert('play_log', $rows, $columns, 'token-1');
        self::client()->insert('play_log', $rows, $columns, 'token-2');
        self::client()->insert('play_log', $rows, $columns);

        static::assertSame(3, $this->rows('SELECT count() AS n FROM play_log')[0]['n']);
        static::assertSame(3, $this->rows('SELECT sum(plays) AS n FROM play_hourly')[0]['n']);
    }

    #[Group('integration')]
    public function testInvalidValueBecomesADatabaseException(): void
    {
        $this->expectException(DatabaseException::class);
        $this->expectExceptionMessageIsOrContains('Insert into event_log failed');

        self::client()->insert('event_log', [['p', 'not a time', 'debug', 's', 'n', []]], self::EVENT_COLUMNS);
    }

    #[Group('integration')]
    public function testUnknownEnumValueIsRefused(): void
    {
        $this->expectException(DatabaseException::class);

        self::client()->insert('event_log', [['p', '2026-10-03 10:00:00', 'loud', 's', 'n', []]], self::EVENT_COLUMNS);
    }
}
