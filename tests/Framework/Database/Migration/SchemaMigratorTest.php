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


namespace App\Framework\Database\Migration;

use App\Framework\Database\ClickHouseClientInterface;
use PHPUnit\Framework\Assert;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

class SchemaMigratorTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/migrations_' . uniqid();
        mkdir($this->dir);
    }

    protected function tearDown(): void
    {
        $files = glob($this->dir . '/*');
        if ($files !== false)
        {
            foreach ($files as $file)
                unlink($file);
        }

        rmdir($this->dir);
    }

    #[Group('units')]
    public function testExecutesFilesInOrderAndSplitsStatements(): void
    {
        file_put_contents($this->dir . '/02_second.sql', "CREATE TABLE IF NOT EXISTS b (x UInt8) ENGINE = Memory;");
        file_put_contents($this->dir . '/01_first.sql', "-- Kommentar\nCREATE TABLE IF NOT EXISTS a (x UInt8) ENGINE = Memory;\nCREATE TABLE IF NOT EXISTS c (x UInt8) ENGINE = Memory;");

        $client = new class implements ClickHouseClientInterface
        {
            /** @var list<string> */
            public array $statements = [];
            public function execute(string $sql): void { $this->statements[] = $sql; }
        };

        $executed = new SchemaMigrator($client, $this->dir)->migrate();

        Assert::assertSame(['01_first.sql', '02_second.sql'], $executed);
        Assert::assertCount(3, $client->statements);
        Assert::assertStringContainsString('TABLE IF NOT EXISTS a', $client->statements[0]);
        Assert::assertStringContainsString('TABLE IF NOT EXISTS c', $client->statements[1]);
        Assert::assertStringContainsString('TABLE IF NOT EXISTS b', $client->statements[2]);
    }

    #[Group('units')]
    public function testEmptyDirectoryDoesNothing(): void
    {
        $client = $this->createMock(ClickHouseClientInterface::class);
        $client->expects($this->never())->method('execute');

        Assert::assertSame([], new SchemaMigrator($client, $this->dir)->migrate());
    }
}
