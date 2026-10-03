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

use App\Framework\Core\Config\Config;
use App\Framework\Core\Config\IniConfigLoader;
use App\Framework\Database\ClickHouseClient;
use App\Framework\Database\Migration\SchemaMigrator;
use ClickHouseDB\Client;
use DI\ContainerBuilder;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Throwable;

/**
 * Base of the tests against a real ClickHouse (group "integration", run with phpunit.integration.xml).
 *
 * Every test class gets its own empty database (CLICKHOUSE_TEST_DATABASE, default "analytics_test")
 * with the schema of the real migrations/. The database of the application is never touched.
 * The connection comes from CLICKHOUSE_HOST, _PORT, _USER and _PASSWORD. The user must be allowed to
 * create and drop databases. If ClickHouse is not reachable, the tests fail, they are not skipped.
 */
abstract class ClickHouseTestCase extends TestCase
{
    private static ?Client $driver = null;
    private static string $database = '';

    public static function setUpBeforeClass(): void
    {
        parent::setUpBeforeClass();

        $env = self::connectionSettings();
        if ($env['CLICKHOUSE_DATABASE'] === getenv('CLICKHOUSE_DATABASE'))
            self::fail('CLICKHOUSE_TEST_DATABASE is the database of the application (' . $env['CLICKHOUSE_DATABASE'] . '). The tests drop it, use another name.');
        if (preg_match('/^[A-Za-z0-9_]+$/', $env['CLICKHOUSE_DATABASE']) !== 1)
            self::fail('CLICKHOUSE_TEST_DATABASE may only contain letters, digits and underscore.');

        self::$database = $env['CLICKHOUSE_DATABASE'];
        $driver         = new Client([
            'host'     => $env['CLICKHOUSE_HOST'],
            'port'     => $env['CLICKHOUSE_PORT'],
            'username' => $env['CLICKHOUSE_USER'],
            'password' => $env['CLICKHOUSE_PASSWORD'],
        ]);
        self::$driver = $driver;

        try
        {
            self::dropDatabase();
            $driver->database(self::$database);
            new SchemaMigrator(self::client(), self::projectDir() . '/migrations')->migrate();
        }
        catch (Throwable $e)
        {
            self::fail('The integration tests need a reachable ClickHouse (CLICKHOUSE_HOST, _PORT, _USER, _PASSWORD) whose user may create and drop databases: ' . $e->getMessage());
        }
    }

    public static function tearDownAfterClass(): void
    {
        if (self::$driver !== null)
        {
            self::dropDatabase();
            self::$driver = null;
        }

        parent::tearDownAfterClass();
    }

    protected function setUp(): void
    {
        parent::setUp();

        foreach ($this->column("SELECT name FROM system.tables WHERE database = '" . self::$database . "' AND engine != 'MaterializedView'", 'name') as $table)
            self::driver()->write('TRUNCATE TABLE `' . $table . '` SYNC');
    }

    /**
     * @return array<string,string>
     */
    protected static function connectionSettings(): array
    {
        $env = [];
        foreach (['CLICKHOUSE_HOST' => '127.0.0.1', 'CLICKHOUSE_PORT' => '8123', 'CLICKHOUSE_USER' => 'default', 'CLICKHOUSE_PASSWORD' => ''] as $key => $default)
        {
            $value     = getenv($key);
            $env[$key] = $value === false ? $default : $value;
        }
        $database                = getenv('CLICKHOUSE_TEST_DATABASE');
        $env['CLICKHOUSE_DATABASE'] = $database === false || $database === '' ? 'analytics_test' : $database;

        return $env;
    }

    protected static function projectDir(): string
    {
        return dirname(__DIR__, 2);
    }

    protected static function driver(): Client
    {
        return self::$driver ?? throw new \LogicException('No ClickHouse connection');
    }

    protected static function client(): ClickHouseClient
    {
        return new ClickHouseClient(self::driver());
    }

    protected static function databaseName(): string
    {
        return self::$database;
    }

    /**
     * Drops the test database. The statement is sent in the context of "default", the test database may not exist.
     */
    protected static function dropDatabase(): void
    {
        self::driver()->database('default');
        self::driver()->write('DROP DATABASE IF EXISTS `' . self::$database . '` SYNC');
        self::driver()->database(self::$database);
    }

    /**
     * @return list<array<string,mixed>>
     */
    protected function rows(string $sql): array
    {
        /** @var list<array<string,mixed>> $rows */
        $rows = self::driver()->select($sql)->rows();

        return $rows;
    }

    /**
     * The values of one column of the result as strings.
     *
     * @return list<string>
     */
    protected function column(string $sql, string $column): array
    {
        $values = [];
        foreach ($this->rows($sql) as $row)
        {
            $value = $row[$column] ?? null;
            if (!is_string($value))
                self::fail('Column ' . $column . ' of the result is not a string');
            $values[] = $value;
        }

        return $values;
    }

    /**
     * The services of the application against the test database, built from the real config/services.
     */
    protected function container(): ContainerInterface
    {
        $dir  = self::projectDir();
        $env  = self::connectionSettings();
        $path = ['systemDir' => $dir, 'configDir' => $dir . '/config', 'migrationDir' => $dir . '/migrations'];

        $builder = new ContainerBuilder();
        $builder->addDefinitions([Config::class => new Config(new IniConfigLoader($dir . '/config/settings'), $path, $env)]);
        foreach (['database', 'playlog', 'eventlog', 'systemlog', 'connectlog'] as $file)
            $builder->addDefinitions($dir . '/config/services/' . $file . '.php');

        return $builder->build();
    }
}
