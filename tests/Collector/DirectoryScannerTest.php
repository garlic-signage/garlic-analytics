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

namespace Tests\Collector;

use App\Collector\DirectoryScanner;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

class DirectoryScannerTest extends TestCase
{
    use CollectorTestHelper;

    private string $dir;

    protected function setUp(): void
    {
        $this->dir = $this->createTempDir();
    }

    protected function tearDown(): void
    {
        $this->removeTempDirs();
    }

    private function touchFile(string $name, int $mtime): void
    {
        file_put_contents($this->dir . '/' . $name, 'x');
        touch($this->dir . '/' . $name, $mtime);
    }

    #[Group('units')]
    public function testListsFilesOldestFirstThenByName(): void
    {
        $this->touchFile('b.xml', 1000);
        $this->touchFile('c.xml', 500);
        $this->touchFile('a.xml', 1000);

        $files = new DirectoryScanner()->scan($this->dir, 0, 5000);

        static::assertSame([$this->dir . '/c.xml', $this->dir . '/a.xml', $this->dir . '/b.xml'], $files);
    }

    #[Group('units')]
    public function testSkipsFilesThatAreTooNew(): void
    {
        $this->touchFile('old.xml', 1000);
        $this->touchFile('new.xml', 4990);

        $files = new DirectoryScanner()->scan($this->dir, 60, 5000);

        static::assertSame([$this->dir . '/old.xml'], $files);
    }

    #[Group('units')]
    public function testIgnoresDirectoriesAndHiddenFiles(): void
    {
        $this->touchFile('.hidden', 1000);
        $this->touchFile('a.xml', 1000);
        mkdir($this->dir . '/sub');

        $files = new DirectoryScanner()->scan($this->dir, 0, 5000);
        rmdir($this->dir . '/sub');

        static::assertSame([$this->dir . '/a.xml'], $files);
    }

    #[Group('units')]
    public function testMissingDirectoryGivesNoFiles(): void
    {
        static::assertSame([], new DirectoryScanner()->scan($this->dir . '/missing', 0));
    }
}
