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

namespace Tests\Framework\Database\Migration;

use App\Framework\Database\ClickHouseClientInterface;
use App\Framework\Database\Migration\MigrateCommand;
use App\Framework\Database\Migration\SchemaMigrator;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

class MigrateCommandTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/migrate_command_' . uniqid();
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
    public function testPrintsExecutedFiles(): void
    {
        file_put_contents($this->dir . '/001_init.sql', 'CREATE TABLE IF NOT EXISTS a (x UInt8) ENGINE = Memory;');
        file_put_contents($this->dir . '/002_more.sql', 'CREATE TABLE IF NOT EXISTS b (x UInt8) ENGINE = Memory;');

        $migrator = new SchemaMigrator(static::createStub(ClickHouseClientInterface::class), $this->dir);
        $tester   = new CommandTester(new MigrateCommand($migrator));

        static::assertSame(Command::SUCCESS, $tester->execute([]));
        static::assertStringContainsString('executed: 001_init.sql', $tester->getDisplay());
        static::assertStringContainsString('executed: 002_more.sql', $tester->getDisplay());
    }

    #[Group('units')]
    public function testFailureReturnsFailureAndMessage(): void
    {
        file_put_contents($this->dir . '/001_init.sql', 'CREATE TABLE IF NOT EXISTS a (x UInt8) ENGINE = Memory;');

        $client = static::createStub(ClickHouseClientInterface::class);
        $client->method('execute')->willThrowException(new RuntimeException('connection refused'));

        $tester = new CommandTester(new MigrateCommand(new SchemaMigrator($client, $this->dir)));

        static::assertSame(Command::FAILURE, $tester->execute([]));
        static::assertStringContainsString('Migration failed: connection refused', $tester->getDisplay());
    }
}
