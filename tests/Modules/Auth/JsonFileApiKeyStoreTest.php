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

namespace Tests\Modules\Auth;

use App\Framework\Exceptions\CoreException;
use App\Modules\Auth\JsonFileApiKeyStore;
use App\Modules\Auth\Scope;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

class JsonFileApiKeyStoreTest extends TestCase
{
    private string $dir;
    private string $file;

    protected function setUp(): void
    {
        $this->dir  = sys_get_temp_dir() . '/apikeys_' . uniqid();
        $this->file = $this->dir . '/keys/api_keys.json';
    }

    protected function tearDown(): void
    {
        foreach ([$this->file, $this->file . '.tmp'] as $file)
        {
            if (is_file($file))
                unlink($file);
        }

        if (is_dir($this->dir . '/keys'))
            rmdir($this->dir . '/keys');
        if (is_dir($this->dir))
            rmdir($this->dir);
    }

    /**
     * @throws CoreException
     */
    #[Group('units')]
    public function testMissingFileMeansNoKeys(): void
    {
        $store = new JsonFileApiKeyStore($this->file);

        static::assertNull($store->findByKeyHash(hash('sha256', 'anything')));
    }

    /**
     * @throws CoreException
     */
    #[Group('units')]
    public function testAddedKeyIsFoundByHash(): void
    {
        $store = new JsonFileApiKeyStore($this->file);
        $store->add('garlic-hub', hash('sha256', 'secret'), [Scope::Ingest, Scope::Read]);

        $client = new JsonFileApiKeyStore($this->file)->findByKeyHash(hash('sha256', 'secret'));

        static::assertNotNull($client);
        static::assertSame('garlic-hub', $client->name);
        static::assertSame([Scope::Ingest, Scope::Read], $client->scopes);
        static::assertNull($store->findByKeyHash(hash('sha256', 'other')));
    }

    /**
     * @throws CoreException
     */
    #[Group('units')]
    public function testFileIsCreatedPrivateAndHoldsNoPlainKey(): void
    {
        new JsonFileApiKeyStore($this->file)->add('garlic-hub', hash('sha256', 'secret'), [Scope::Ingest]);

        static::assertSame(0600, fileperms($this->file) & 0777);
        static::assertSame(0700, fileperms($this->dir . '/keys') & 0777);
        static::assertStringNotContainsString('"secret"', (string) file_get_contents($this->file));
        static::assertFileDoesNotExist($this->file . '.tmp');
    }

    /**
     * @throws CoreException
     */
    #[Group('units')]
    public function testDuplicateNameThrows(): void
    {
        $store = new JsonFileApiKeyStore($this->file);
        $store->add('garlic-hub', hash('sha256', 'one'), [Scope::Ingest]);

        $this->expectException(InvalidArgumentException::class);
        $store->add('garlic-hub', hash('sha256', 'two'), [Scope::Read]);
    }

    /**
     * @throws CoreException
     */
    #[Group('units')]
    public function testRemoveDeletesOnlyTheNamedKey(): void
    {
        $store = new JsonFileApiKeyStore($this->file);
        $store->add('garlic-hub', hash('sha256', 'one'), [Scope::Ingest]);
        $store->add('collector', hash('sha256', 'two'), [Scope::Ingest]);

        static::assertTrue($store->remove('garlic-hub'));
        static::assertFalse($store->remove('garlic-hub'));
        static::assertNull($store->findByKeyHash(hash('sha256', 'one')));
        static::assertNotNull($store->findByKeyHash(hash('sha256', 'two')));
    }

    /**
     * @throws CoreException
     */
    #[Group('units')]
    public function testUnknownScopesInFileAreIgnored(): void
    {
        mkdir($this->dir . '/keys', 0700, true);
        file_put_contents($this->file, '{"cms": {"hash": "' . hash('sha256', 'k') . '", "scopes": ["ingest", "admin"]}}');

        $client = new JsonFileApiKeyStore($this->file)->findByKeyHash(hash('sha256', 'k'));

        static::assertNotNull($client);
        static::assertSame([Scope::Ingest], $client->scopes);
    }

    #[Group('units')]
    public function testBrokenFileThrows(): void
    {
        mkdir($this->dir . '/keys', 0700, true);
        file_put_contents($this->file, '{not json');

        $this->expectException(CoreException::class);
        new JsonFileApiKeyStore($this->file)->findByKeyHash(hash('sha256', 'k'));
    }

    #[Group('units')]
    public function testEntryWithoutHashThrows(): void
    {
        mkdir($this->dir . '/keys', 0700, true);
        file_put_contents($this->file, '{"cms": {"scopes": ["ingest"]}}');

        $this->expectException(CoreException::class);
        new JsonFileApiKeyStore($this->file)->findByKeyHash(hash('sha256', 'k'));
    }
}
