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

namespace Tests\Collector\Commands;

use App\Collector\BatchSplitter;
use App\Collector\Commands\CollectorRunCommand;
use App\Collector\CollectorRunner;
use App\Collector\Device\DeviceSource;
use App\Collector\Device\Smil\SmilAdapter;
use App\Collector\Device\Smil\SmilPlayLogParser;
use App\Collector\DirectoryScanner;
use App\Collector\Exceptions\RejectedIngestException;
use App\Collector\Exceptions\RetryableIngestException;
use App\Collector\FileArchive;
use App\Collector\RunLock;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;
use Tests\Collector\CollectorTestHelper;
use Tests\Collector\FakeIngestClient;

class CollectorRunCommandTest extends TestCase
{
    use CollectorTestHelper;

    private string $base;
    private FakeIngestClient $ingest;

    protected function setUp(): void
    {
        $this->base   = $this->createTempDir();
        $this->ingest = new FakeIngestClient();
        mkdir($this->base . '/upload');

        $this->put('playlog-a.xml', self::playLogXml('player-1', [
            ['1', '2026-10-03T10:00:00+02:00', '2026-10-03T10:00:10+02:00'],
            ['2', '2026-10-03T10:00:10+02:00', '2026-10-03T10:00:20+02:00'],
        ]));
        $this->put('playlog-broken.xml', '<report>');
        $this->put('event-a.xml', '<report/>');
    }

    protected function tearDown(): void
    {
        $this->removeTempDirs();
    }

    private function put(string $name, string $content): void
    {
        file_put_contents($this->base . '/upload/' . $name, $content);
        touch($this->base . '/upload/' . $name, time() - 3600);
    }

    private function tester(): CommandTester
    {
        $runner = new CollectorRunner(
            ['smil' => new DeviceSource(new SmilAdapter(new SmilPlayLogParser()), $this->base . '/upload')],
            new DirectoryScanner(),
            new BatchSplitter(5000),
            $this->ingest,
            new FileArchive(),
            60
        );

        return new CommandTester(new CollectorRunCommand($runner, new RunLock($this->base . '/collector.lock')));
    }

    #[Group('units')]
    public function testDryRunPrintsFilesAndSummary(): void
    {
        $tester = $this->tester();

        $status = $tester->execute(['--dry-run' => true]);

        $display = $tester->getDisplay();
        static::assertSame(Command::SUCCESS, $status);
        static::assertStringContainsString('parsed   smil/playlog-a.xml  player player-1  2 events in 1 batch(es)', $display);
        static::assertStringContainsString('skipped  smil/event-a.xml  (no ingest for event yet)', $display);
        static::assertStringContainsString('failed   smil/playlog-broken.xml', $display);
        static::assertStringContainsString('Files: 1 parsed, 1 skipped, 1 failed. Events: 2 in 1 batch(es). Dry run', $display);
        static::assertFileExists($this->base . '/upload/playlog-a.xml');
    }

    #[Group('units')]
    public function testRunSendsAndMovesFiles(): void
    {
        $tester = $this->tester();

        $status = $tester->execute([]);

        $display = $tester->getDisplay();
        static::assertSame(Command::SUCCESS, $status); // a rejected file is a data problem, not a failed run
        static::assertStringContainsString('sent     smil/playlog-a.xml  player player-1  2 events in 1 batch(es)', $display);
        static::assertStringContainsString('rejected smil/playlog-broken.xml  moved to error/: Invalid XML', $display);
        static::assertStringContainsString('Files: 1 sent, 1 rejected, 1 skipped, 0 kept for retry. Events sent: 2 in 1 batch(es).', $display);
        static::assertFileExists($this->base . '/processed/playlog-a.xml');
        static::assertFileExists($this->base . '/error/playlog-broken.xml.error');
        static::assertFileExists($this->base . '/upload/event-a.xml');
    }

    #[Group('units')]
    public function testRefusedDataDoesNotFailTheRun(): void
    {
        $this->ingest->onSend = static function (): void
        {
            throw new RejectedIngestException('HTTP 422: Validation failed');
        };
        $tester = $this->tester();

        $status = $tester->execute(['--file' => 'playlog-a.xml']);

        static::assertSame(Command::SUCCESS, $status);
        static::assertStringContainsString('rejected smil/playlog-a.xml  moved to error/: HTTP 422', $tester->getDisplay());
    }

    #[Group('units')]
    public function testStoppedRunFailsAndKeepsFiles(): void
    {
        $this->ingest->onSend = static function (): void
        {
            throw new RetryableIngestException('HTTP 401: Invalid API key.');
        };
        $tester = $this->tester();

        $status = $tester->execute([]);

        static::assertSame(Command::FAILURE, $status);
        static::assertStringContainsString('retry    smil/playlog-a.xml  stays in upload/, run stopped: HTTP 401', $tester->getDisplay());
        static::assertFileExists($this->base . '/upload/playlog-a.xml');
    }

    #[Group('units')]
    public function testMissingConfigurationFailsBeforeAnyFileIsTouched(): void
    {
        $this->ingest->notConfigured = 'COLLECTOR_API_KEY is not set';
        $tester                      = $this->tester();

        $status = $tester->execute([]);

        static::assertSame(Command::FAILURE, $status);
        static::assertStringContainsString('COLLECTOR_API_KEY is not set', $tester->getDisplay());
        static::assertFileExists($this->base . '/upload/playlog-a.xml');
    }

    #[Group('units')]
    public function testActiveRunBlocksAnotherOneWithoutError(): void
    {
        $other = new RunLock($this->base . '/collector.lock');
        static::assertTrue($other->acquire());
        $tester = $this->tester();

        $status = $tester->execute([]);
        $other->release();

        static::assertSame(Command::SUCCESS, $status);
        static::assertStringContainsString('Another run is active', $tester->getDisplay());
        static::assertSame([], $this->ingest->sent);
        static::assertFileExists($this->base . '/upload/playlog-a.xml');
    }

    #[Group('units')]
    public function testLockIsReleasedAfterTheRun(): void
    {
        $this->tester()->execute([]);

        $other = new RunLock($this->base . '/collector.lock');
        static::assertTrue($other->acquire());
        $other->release();
    }

    #[Group('units')]
    public function testUnknownTypeIsRejected(): void
    {
        $tester = $this->tester();

        $status = $tester->execute(['--dry-run' => true, '--type' => 'nope']);

        static::assertSame(Command::FAILURE, $status);
        static::assertStringContainsString('Unknown type "nope"', $tester->getDisplay());
    }

    #[Group('units')]
    public function testUnknownDeviceIsRejected(): void
    {
        $tester = $this->tester();

        $status = $tester->execute(['--dry-run' => true, '--device' => 'nope']);

        static::assertSame(Command::FAILURE, $status);
        static::assertStringContainsString('Unknown device "nope"', $tester->getDisplay());
    }

    #[Group('units')]
    public function testFiltersNarrowTheRun(): void
    {
        $tester = $this->tester();

        $tester->execute(['--dry-run' => true, '--device' => 'smil', '--type' => 'playlog', '--file' => 'playlog-a.xml']);

        $display = $tester->getDisplay();
        static::assertStringContainsString('playlog-a.xml', $display);
        static::assertStringNotContainsString('event-a.xml', $display);
        static::assertStringNotContainsString('broken', $display);
    }
}
