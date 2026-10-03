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

use App\Collector\Exceptions\ArchiveException;
use App\Collector\FileArchive;
use DateTimeImmutable;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

class FileArchiveTest extends TestCase
{
    use CollectorTestHelper;

    private string $dir;

    protected function setUp(): void
    {
        $this->dir = $this->createTempDir();
        mkdir($this->dir . '/upload');
        file_put_contents($this->dir . '/upload/playlog-a.xml', '<report/>');
    }

    protected function tearDown(): void
    {
        $this->removeTempDirs();
    }

    #[Group('units')]
    public function testProcessedFileIsMovedAndTheDirectoryCreated(): void
    {
        new FileArchive()->markProcessed($this->dir . '/upload/playlog-a.xml', $this->dir . '/processed');

        static::assertFileDoesNotExist($this->dir . '/upload/playlog-a.xml');
        static::assertSame('<report/>', file_get_contents($this->dir . '/processed/playlog-a.xml'));
    }

    #[Group('units')]
    public function testRejectedFileGetsAnErrorFileWithTimeAndReason(): void
    {
        new FileArchive()->markRejected(
            $this->dir . '/upload/playlog-a.xml',
            $this->dir . '/error',
            'HTTP 422: Validation failed',
            new DateTimeImmutable('2026-10-03T12:00:00+00:00')
        );

        static::assertFileExists($this->dir . '/error/playlog-a.xml');
        static::assertSame("2026-10-03T12:00:00+00:00\nHTTP 422: Validation failed\n", file_get_contents($this->dir . '/error/playlog-a.xml.error'));
    }

    #[Group('units')]
    public function testTakenNameIsNotOverwritten(): void
    {
        mkdir($this->dir . '/processed');
        file_put_contents($this->dir . '/processed/playlog-a.xml', 'old');

        new FileArchive()->markProcessed($this->dir . '/upload/playlog-a.xml', $this->dir . '/processed');

        static::assertSame('old', file_get_contents($this->dir . '/processed/playlog-a.xml'));
        $moved = glob($this->dir . '/processed/playlog-a-*.xml');
        static::assertIsArray($moved);
        static::assertCount(1, $moved);
        static::assertSame('<report/>', file_get_contents($moved[0]));
    }

    #[Group('units')]
    public function testTwoCollisionsInTheSameSecondGetDifferentNames(): void
    {
        mkdir($this->dir . '/processed');
        file_put_contents($this->dir . '/processed/playlog-a.xml', 'old');
        $archive = new FileArchive();

        $archive->markProcessed($this->dir . '/upload/playlog-a.xml', $this->dir . '/processed');
        file_put_contents($this->dir . '/upload/playlog-a.xml', 'second');
        $archive->markProcessed($this->dir . '/upload/playlog-a.xml', $this->dir . '/processed');

        $files = glob($this->dir . '/processed/*.xml');
        static::assertIsArray($files);
        static::assertCount(3, $files);
    }

    #[Group('units')]
    public function testMissingFileIsAnError(): void
    {
        $this->expectException(ArchiveException::class);

        new FileArchive()->markProcessed($this->dir . '/upload/nope.xml', $this->dir . '/processed');
    }

    #[Group('units')]
    public function testDirectoryThatCanNotBeCreatedIsAnError(): void
    {
        file_put_contents($this->dir . '/processed', 'a file');

        $this->expectException(ArchiveException::class);
        $this->expectExceptionMessage('Can not create directory');

        new FileArchive()->markProcessed($this->dir . '/upload/playlog-a.xml', $this->dir . '/processed');
    }
}
