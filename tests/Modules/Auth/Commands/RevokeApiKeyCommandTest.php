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

namespace Tests\Modules\Auth\Commands;

use App\Modules\Auth\ApiKeyStoreInterface;
use App\Modules\Auth\Commands\RevokeApiKeyCommand;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

class RevokeApiKeyCommandTest extends TestCase
{
    #[Group('units')]
    public function testRemovesNamedKey(): void
    {
        $store = $this->createMock(ApiKeyStoreInterface::class);
        $store->expects($this->once())->method('remove')->with('garlic-hub')->willReturn(true);

        $tester = new CommandTester(new RevokeApiKeyCommand($store));

        static::assertSame(Command::SUCCESS, $tester->execute(['name' => 'garlic-hub']));
        static::assertStringContainsString('revoked', $tester->getDisplay());
    }

    #[Group('units')]
    public function testUnknownNameFails(): void
    {
        $store = static::createStub(ApiKeyStoreInterface::class);
        $store->method('remove')->willReturn(false);

        $tester = new CommandTester(new RevokeApiKeyCommand($store));

        static::assertSame(Command::FAILURE, $tester->execute(['name' => 'nobody']));
        static::assertStringContainsString('No API key named "nobody"', $tester->getDisplay());
    }
}
