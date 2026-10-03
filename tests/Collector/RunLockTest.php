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

use App\Collector\RunLock;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

class RunLockTest extends TestCase
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

    #[Group('units')]
    public function testSecondRunCanNotGetTheLock(): void
    {
        $first  = new RunLock($this->dir . '/collector.lock');
        $second = new RunLock($this->dir . '/collector.lock');

        static::assertTrue($first->acquire());
        static::assertFalse($second->acquire());

        $first->release();
    }

    #[Group('units')]
    public function testLockCanBeTakenAgainAfterRelease(): void
    {
        $first  = new RunLock($this->dir . '/collector.lock');
        $second = new RunLock($this->dir . '/collector.lock');

        static::assertTrue($first->acquire());
        $first->release();

        static::assertTrue($second->acquire());
        $second->release();
    }

    #[Group('units')]
    public function testDirectoryOfTheLockFileIsCreated(): void
    {
        $lock = new RunLock($this->dir . '/new/collector.lock');

        static::assertTrue($lock->acquire());
        $lock->release();
        static::assertFileExists($this->dir . '/new/collector.lock');
    }

    #[Group('units')]
    public function testReleaseWithoutLockIsHarmless(): void
    {
        new RunLock($this->dir . '/collector.lock')->release();

        $this->expectNotToPerformAssertions();
    }
}
