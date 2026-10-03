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

namespace Tests\Framework\Database;

use App\Framework\Database\ClickHouseClient;
use App\Framework\Exceptions\DatabaseException;
use ClickHouseDB\Client;
use ClickHouseDB\Exception\QueryException;
use ClickHouseDB\Settings;
use ClickHouseDB\Statement;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

class ClickHouseClientTest extends TestCase
{
    #[Group('units')]
    public function testEnsureDatabaseDoesNothingIfTheDatabaseExists(): void
    {
        $driver = $this->createMock(Client::class);
        $driver->expects($this->once())->method('write')->with('SELECT 1');

        new ClickHouseClient($driver)->ensureDatabase();
    }

    #[Group('units')]
    public function testEnsureDatabaseCreatesAMissingDatabaseInTheContextOfDefault(): void
    {
        $settings = new Settings();
        $settings->database('my_analytics');

        $driver = static::createStub(Client::class);
        $driver->method('settings')->willReturn($settings);
        $statements = [];
        $driver->method('write')->willReturnCallback(
            function (string $sql) use (&$statements)
            {
                $statements[] = $sql;
                if ($sql === 'SELECT 1')
                    throw new QueryException("Database my_analytics does not exist. (UNKNOWN_DATABASE)\nIN:SELECT 1");

                return static::createStub(Statement::class);
            }
        );
        $databases = [];
        $driver->method('database')->willReturnCallback(
            static function (string $db) use (&$databases, $driver): Client
            {
                $databases[] = $db;
                return $driver;
            }
        );

        new ClickHouseClient($driver)->ensureDatabase();

        static::assertSame(['SELECT 1', 'CREATE DATABASE IF NOT EXISTS `my_analytics`'], $statements);
        static::assertSame(['default', 'my_analytics'], $databases); // and back to the own database
    }

    #[Group('units')]
    public function testEnsureDatabaseReportsWhyTheDatabaseCanNotBeCreated(): void
    {
        $settings = new Settings();
        $settings->database('my_analytics');

        $driver = static::createStub(Client::class);
        $driver->method('settings')->willReturn($settings);
        $driver->method('write')->willReturnCallback(
            static function (string $sql): Statement
            {
                throw new QueryException($sql === 'SELECT 1' ? 'Database my_analytics does not exist. (UNKNOWN_DATABASE)' : "Not enough privileges. (ACCESS_DENIED)\nIN:CREATE DATABASE");
            }
        );

        try
        {
            new ClickHouseClient($driver)->ensureDatabase();
            static::fail('DatabaseException expected');
        }
        catch (DatabaseException $e)
        {
            static::assertStringContainsString('Database "my_analytics" does not exist and can not be created: Not enough privileges. (ACCESS_DENIED)', $e->getMessage());
            static::assertStringNotContainsString('IN:CREATE', $e->getMessage());
        }
    }

    #[Group('units')]
    public function testEnsureDatabaseRefusesAnInvalidName(): void
    {
        $settings = new Settings();
        $settings->database('bad`name');

        $driver = $this->createMock(Client::class);
        $driver->method('settings')->willReturn($settings);
        $driver->expects($this->once())->method('write')->willThrowException(new QueryException('Database bad does not exist. (UNKNOWN_DATABASE)'));

        $this->expectException(DatabaseException::class);
        $this->expectExceptionMessageIsOrContains('The database name "bad`name" is not valid');

        new ClickHouseClient($driver)->ensureDatabase();
    }

    #[Group('units')]
    public function testEnsureDatabaseReportsOtherErrors(): void
    {
        $driver = static::createStub(Client::class);
        $driver->method('write')->willThrowException(new QueryException("Connection refused\nmore"));

        $this->expectException(DatabaseException::class);
        $this->expectExceptionMessageIsOrContains('ClickHouse is not usable: Connection refused');

        new ClickHouseClient($driver)->ensureDatabase();
    }

    #[Group('units')]
    public function testInsertSendsOneJsonEachRowStatementWithDeduplicationSettings(): void
    {
        $driver = $this->createMock(Client::class);
        $driver->expects($this->once())
            ->method('write')
            ->with(
                "INSERT INTO `t` FORMAT JSONEachRow\n{\"a\":\"x'y\\\\\",\"b\":1}\n{\"a\":\"z\",\"b\":2}",
                [],
                true,
                ['insert_deduplication_token' => 'token-1', 'deduplicate_blocks_in_dependent_materialized_views' => 1]
            );

        new ClickHouseClient($driver)->insert('t', [["x'y\\", 1], ['z', 2]], ['a', 'b'], 'token-1');
    }

    #[Group('units')]
    public function testInsertWritesMapsAsJsonObjects(): void
    {
        $driver = $this->createMock(Client::class);
        $driver->expects($this->once())
            ->method('write')
            ->with("INSERT INTO `t` FORMAT JSONEachRow\n{\"a\":\"x\",\"m\":{\"k\":\"it's\",\"0\":\"v\"}}\n{\"a\":\"y\",\"m\":{}}", [], true, []);

        // a map is an object also if it is empty or its keys look like a list (PHP turns the key "0" into an integer)
        /** @var array<string,string> $map */
        $map = ['k' => "it's", '0' => 'v'];
        new ClickHouseClient($driver)->insert('t', [['x', $map], ['y', []]], ['a', 'm']);
    }

    #[Group('units')]
    public function testInsertWritesNullForNullableColumns(): void
    {
        $driver = $this->createMock(Client::class);
        $driver->expects($this->once())->method('write')->with("INSERT INTO `t` FORMAT JSONEachRow\n{\"a\":\"x\",\"b\":null}\n{\"a\":\"y\",\"b\":5}", [], true, []);

        new ClickHouseClient($driver)->insert('t', [['x', null], ['y', 5]], ['a', 'b']);
    }

    #[Group('units')]
    public function testInsertKeepsUnicodeAndLineBreaksIntact(): void
    {
        $driver = $this->createMock(Client::class);
        $driver->expects($this->once())->method('write')->with("INSERT INTO `t` FORMAT JSONEachRow\n{\"a\":\"line\\nbreak \\u00e4\"}", [], true, []);

        new ClickHouseClient($driver)->insert('t', [["line\nbreak ä"]], ['a']);
    }

    #[Group('units')]
    public function testInsertWithoutTokenSendsNoSettings(): void
    {
        $driver = $this->createMock(Client::class);
        $driver->expects($this->once())->method('write')->with(static::anything(), [], true, []);

        new ClickHouseClient($driver)->insert('t', [['x']], ['a']);
    }

    #[Group('units')]
    public function testInsertWithoutRowsSendsNothing(): void
    {
        $driver = $this->createMock(Client::class);
        $driver->expects($this->never())->method('write');

        new ClickHouseClient($driver)->insert('t', [], ['a'], 'token-1');
    }

    #[Group('units')]
    public function testDriverErrorBecomesDatabaseException(): void
    {
        $driver = static::createStub(Client::class);
        $driver->method('write')->willThrowException(new QueryException('boom'));

        $this->expectException(DatabaseException::class);
        $this->expectExceptionMessageIsOrContains('Insert into t failed: boom');

        new ClickHouseClient($driver)->insert('t', [['x']], ['a']);
    }
}
