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

use App\Framework\Database\Migration\SchemaMigrator;
use PHPUnit\Framework\Attributes\Group;

#[Group('integration')]
class MigrationIntegrationTest extends ClickHouseTestCase
{
    private function migrate(): void
    {
        new SchemaMigrator(self::client(), self::projectDir() . '/migrations')->migrate();
    }

    /**
     * @return list<string>
     */
    private function tables(): array
    {
        return $this->column("SELECT name FROM system.tables WHERE database = '" . self::databaseName() . "' ORDER BY name", 'name');
    }

    #[Group('integration')]
    public function testMigrationsCreateAllTables(): void
    {
        static::assertSame(
            ['connect_hourly', 'connect_hourly_mv', 'connect_log', 'event_log', 'play_hourly', 'play_hourly_mv', 'play_log', 'system_log'],
            $this->tables()
        );
    }

    #[Group('integration')]
    public function testMigrationsCanRunAgain(): void
    {
        $before = $this->tables();

        $this->migrate();
        $this->migrate();

        static::assertSame($before, $this->tables());
    }

    #[Group('integration')]
    public function testEveryTableIsSortedByThePlayerFirst(): void
    {
        $keys = $this->column(
            "SELECT concat(name, ': ', sorting_key) AS k FROM system.tables WHERE database = '" . self::databaseName() . "' AND engine != 'MaterializedView' ORDER BY name",
            'k'
        );

        static::assertCount(6, $keys);
        foreach ($keys as $key)
            static::assertMatchesRegularExpression('/^\w+: player_id,/', $key, 'sorting key (table: key)');
    }

    #[Group('integration')]
    public function testEveryTableThatTakesTokensCanDeduplicate(): void
    {
        $windows = $this->column(
            "SELECT name FROM system.tables WHERE database = '" . self::databaseName() . "' AND engine != 'MaterializedView'
             AND create_table_query NOT LIKE '%non_replicated_deduplication_window%' ORDER BY name",
            'name'
        );

        static::assertSame([], $windows, 'tables without non_replicated_deduplication_window can not drop a repeated insert');
    }

    #[Group('integration')]
    public function testEnsureDatabaseCreatesAMissingDatabase(): void
    {
        try
        {
            self::dropDatabase();

            self::client()->ensureDatabase();

            $databases = array_column($this->rows('SHOW DATABASES'), 'name');
            static::assertContains(self::databaseName(), $databases);
        }
        finally
        {
            $this->migrate(); // the next tests of this class need their tables
        }
    }
}
