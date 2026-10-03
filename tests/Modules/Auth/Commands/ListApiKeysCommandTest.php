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

use App\Modules\Auth\ApiClient;
use App\Modules\Auth\ApiKeyStoreInterface;
use App\Modules\Auth\Commands\ListApiKeysCommand;
use App\Modules\Auth\Scope;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

class ListApiKeysCommandTest extends TestCase
{
    #[Group('units')]
    public function testListsNamesAndScopes(): void
    {
        $store = static::createStub(ApiKeyStoreInterface::class);
        $store->method('all')->willReturn([
            new ApiClient('garlic-hub', [Scope::Ingest, Scope::Read]),
            new ApiClient('collector', [Scope::Ingest]),
        ]);

        $tester = new CommandTester(new ListApiKeysCommand($store));

        static::assertSame(Command::SUCCESS, $tester->execute([]));
        static::assertMatchesRegularExpression('/garlic-hub\s*\|\s*ingest, read/', $tester->getDisplay());
        static::assertMatchesRegularExpression('/collector\s*\|\s*ingest\s*\|/', $tester->getDisplay());
    }

    #[Group('units')]
    public function testNoKeys(): void
    {
        $store = static::createStub(ApiKeyStoreInterface::class);
        $store->method('all')->willReturn([]);

        $tester = new CommandTester(new ListApiKeysCommand($store));

        static::assertSame(Command::SUCCESS, $tester->execute([]));
        static::assertStringContainsString('No API keys.', $tester->getDisplay());
    }
}
