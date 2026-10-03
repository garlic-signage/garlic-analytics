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
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

class ClickHouseClientTest extends TestCase
{
    #[Group('units')]
    public function testInsertBuildsOneStatementWithDeduplicationSettings(): void
    {
        $driver = $this->createMock(Client::class);
        $driver->expects($this->once())
            ->method('write')
            ->with(
                "INSERT INTO `t` (`a`,`b`) VALUES ('x\\'y',1),('z',2)",
                [],
                true,
                ['insert_deduplication_token' => 'token-1', 'deduplicate_blocks_in_dependent_materialized_views' => 1]
            );

        new ClickHouseClient($driver)->insert('t', [["x'y", 1], ['z', 2]], ['a', 'b'], 'token-1');
    }

    #[Group('units')]
    public function testInsertWritesMapsAsLiterals(): void
    {
        $driver = $this->createMock(Client::class);
        $driver->expects($this->once())
            ->method('write')
            ->with("INSERT INTO `t` (`a`,`m`) VALUES ('x',{'k':'it\\'s','z':'v'}),('y',{})", [], true, []);

        new ClickHouseClient($driver)->insert('t', [['x', ['k' => "it's", 'z' => 'v']], ['y', []]], ['a', 'm']);
    }

    #[Group('units')]
    public function testInsertWritesNullForNullableColumns(): void
    {
        $driver = $this->createMock(Client::class);
        $driver->expects($this->once())->method('write')->with("INSERT INTO `t` (`a`,`b`) VALUES ('x',NULL),('y',5)", [], true, []);

        new ClickHouseClient($driver)->insert('t', [['x', null], ['y', 5]], ['a', 'b']);
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
