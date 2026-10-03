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

use App\Framework\Core\Crypt;
use App\Modules\Auth\ApiKeyStoreInterface;
use App\Modules\Auth\Commands\CreateApiKeyCommand;
use App\Modules\Auth\Scope;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

class CreateApiKeyCommandTest extends TestCase
{
    #[Group('units')]
    public function testStoresHashOfPrintedKey(): void
    {
        $stored = [];
        $store  = $this->createMock(ApiKeyStoreInterface::class);
        $store->expects($this->once())
            ->method('add')
            ->willReturnCallback(function (string $name, string $hash, array $scopes) use (&$stored): void
            {
                $stored = [$name, $hash, $scopes];
            });

        $tester = new CommandTester(new CreateApiKeyCommand($store, new Crypt()));
        $status = $tester->execute(['name' => 'garlic-hub', '--scope' => ['ingest', 'read', 'ingest']]);

        static::assertSame(Command::SUCCESS, $status);
        static::assertSame(1, preg_match('/^([0-9a-f]{64})$/m', $tester->getDisplay(), $matches));
        static::assertSame(['garlic-hub', hash('sha256', $matches[1]), [Scope::Ingest, Scope::Read]], $stored);
    }

    #[Group('units')]
    public function testScopeIsRequired(): void
    {
        $status = $this->runWithUnusedStore(['name' => 'garlic-hub']);

        static::assertSame(Command::FAILURE, $status);
    }

    #[Group('units')]
    public function testUnknownScopeFails(): void
    {
        $status = $this->runWithUnusedStore(['name' => 'garlic-hub', '--scope' => ['admin']]);

        static::assertSame(Command::FAILURE, $status);
    }

    #[Group('units')]
    public function testInvalidNameFails(): void
    {
        $status = $this->runWithUnusedStore(['name' => 'garlic hub', '--scope' => ['read']]);

        static::assertSame(Command::FAILURE, $status);
    }

    #[Group('units')]
    public function testExistingNameFails(): void
    {
        $store = static::createStub(ApiKeyStoreInterface::class);
        $store->method('add')->willThrowException(new InvalidArgumentException('An API key named "garlic-hub" already exists.'));

        $tester = new CommandTester(new CreateApiKeyCommand($store, new Crypt()));
        $status = $tester->execute(['name' => 'garlic-hub', '--scope' => ['read']]);

        static::assertSame(Command::FAILURE, $status);
        static::assertStringContainsString('already exists', $tester->getDisplay());
        static::assertDoesNotMatchRegularExpression('/[0-9a-f]{64}/', $tester->getDisplay());
    }

    /**
     * @param array<string, mixed> $input
     */
    private function runWithUnusedStore(array $input): int
    {
        $store = $this->createMock(ApiKeyStoreInterface::class);
        $store->expects($this->never())->method('add');

        return new CommandTester(new CreateApiKeyCommand($store, new Crypt()))->execute($input);
    }
}
