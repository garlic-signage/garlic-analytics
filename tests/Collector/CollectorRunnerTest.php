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

use App\Collector\BatchSplitter;
use App\Collector\CollectorRunner;
use App\Collector\Device\DeviceSource;
use App\Collector\Device\Smil\SmilAdapter;
use App\Collector\Device\Smil\SmilPlayLogParser;
use App\Collector\DirectoryScanner;
use App\Collector\Exceptions\RejectedIngestException;
use App\Collector\Exceptions\RetryableIngestException;
use App\Collector\FileArchive;
use App\Collector\FileResult;
use App\Collector\FileStatus;
use App\Collector\LogType;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

class CollectorRunnerTest extends TestCase
{
    use CollectorTestHelper;

    private string $base;
    private string $upload;
    private FakeIngestClient $ingest;

    protected function setUp(): void
    {
        $this->base   = $this->createTempDir();
        $this->upload = $this->base . '/upload';
        $this->ingest = new FakeIngestClient();
        mkdir($this->upload);

        $this->put('playlog-a.xml', self::playLogXml('player-1', [
            ['1', '2026-10-03T10:00:00+02:00', '2026-10-03T10:00:10+02:00'],
            ['2', '2026-10-03T10:00:10+02:00', '2026-10-03T10:00:20+02:00'],
            ['3', '2026-10-03T10:00:20+02:00', '2026-10-03T10:00:30+02:00'],
        ]), 3000);
        $this->put('playlog-broken.xml', '<report><player id="p">', 3001);
        $this->put('event-a.xml', '<report/>', 3002);
        $this->put('system-a.xml', '<report/>', 3003);
        $this->put('notes.txt', 'hello', 3004);
    }

    protected function tearDown(): void
    {
        $this->removeTempDirs();
    }

    private function put(string $name, string $content, int $mtime = 3000): void
    {
        file_put_contents($this->upload . '/' . $name, $content);
        touch($this->upload . '/' . $name, $mtime);
    }

    private function runner(int $minAge = 60): CollectorRunner
    {
        return new CollectorRunner(
            ['smil' => new DeviceSource(new SmilAdapter(new SmilPlayLogParser()), $this->upload)],
            new DirectoryScanner(),
            new BatchSplitter(2),
            $this->ingest,
            new FileArchive(),
            $minAge
        );
    }

    /**
     * @param list<FileResult> $results
     * @return array<string,string>
     */
    private function statuses(array $results): array
    {
        $statuses = [];
        foreach ($results as $result)
            $statuses[$result->fileName] = $result->status->value;

        return $statuses;
    }

    /** @return list<string> */
    private function names(string $dir): array
    {
        $files = is_dir($dir) ? scandir($dir) : [];

        return array_values(array_diff($files === false ? [] : $files, ['.', '..']));
    }

    #[Group('units')]
    public function testDryRunReadsPlayLogsAndTouchesNothing(): void
    {
        $before = $this->names($this->upload);

        $results = $this->runner()->run(dryRun: true);

        static::assertSame([
            'playlog-a.xml'      => 'parsed',
            'playlog-broken.xml' => 'failed',
            'event-a.xml'        => 'skipped-unsupported',
            'system-a.xml'       => 'skipped-unsupported',
            'notes.txt'          => 'skipped-unknown',
        ], $this->statuses($results));
        static::assertSame($before, $this->names($this->upload));
        static::assertSame([], $this->ingest->sent);
        static::assertSame(3, $results[0]->events);
        static::assertSame(2, $results[0]->batches);
        static::assertSame('player-1', $results[0]->playerId);
    }

    #[Group('units')]
    public function testSentFileGoesToProcessedInBlocks(): void
    {
        $results = $this->runner()->run(fileName: 'playlog-a.xml');

        static::assertSame(['playlog-a.xml' => 'sent'], $this->statuses($results));
        static::assertSame(3, $results[0]->events);
        static::assertSame(2, $results[0]->batches);
        static::assertCount(2, $this->ingest->sent);
        static::assertCount(2, $this->ingest->sent[0]);
        static::assertSame(
            ['player_id' => 'player-1', 'content_id' => '3', 'start_time' => '2026-10-03T10:00:20+02:00', 'end_time' => '2026-10-03T10:00:30+02:00'],
            $this->ingest->sent[1][0]->toArray()
        );
        static::assertSame(['playlog-a.xml'], $this->names($this->base . '/processed'));
        static::assertNotContains('playlog-a.xml', $this->names($this->upload));
    }

    #[Group('units')]
    public function testBrokenFileGoesToErrorWithoutRequest(): void
    {
        $results = $this->runner()->run(fileName: 'playlog-broken.xml');

        static::assertSame(['playlog-broken.xml' => 'rejected'], $this->statuses($results));
        static::assertSame([], $this->ingest->sent);
        static::assertEqualsCanonicalizing(['playlog-broken.xml', 'playlog-broken.xml.error'], $this->names($this->base . '/error'));
        static::assertStringContainsString('Invalid XML', (string) file_get_contents($this->base . '/error/playlog-broken.xml.error'));
    }

    #[Group('units')]
    public function testRefusedBlockSendsFileToErrorAndNamesTheBlock(): void
    {
        $this->ingest->onSend = static function (int $block): void
        {
            if ($block === 2)
                throw new RejectedIngestException('HTTP 422: Validation failed');
        };

        $results = $this->runner()->run(fileName: 'playlog-a.xml');

        static::assertSame('rejected', $results[0]->status->value);
        static::assertSame('block 2/2: HTTP 422: Validation failed', $results[0]->message);
        static::assertCount(1, $this->ingest->sent);
        static::assertContains('playlog-a.xml.error', $this->names($this->base . '/error'));
        static::assertStringContainsString('block 2/2', (string) file_get_contents($this->base . '/error/playlog-a.xml.error'));
        static::assertNotContains('playlog-a.xml', $this->names($this->upload));
    }

    #[Group('units')]
    public function testRetryErrorKeepsTheFileAndStopsTheRun(): void
    {
        $this->put('playlog-b.xml', self::playLogXml('player-2', [['9', '2026-10-03T11:00:00+02:00', '2026-10-03T11:00:10+02:00']]), 3500);
        $this->ingest->onSend = static function (): void
        {
            throw new RetryableIngestException('API not reachable');
        };

        $results = $this->runner()->run(type: LogType::PlayLog);

        static::assertSame(['playlog-a.xml' => 'retry'], $this->statuses($results));
        static::assertSame([], $this->ingest->sent);
        static::assertContains('playlog-a.xml', $this->names($this->upload));
        static::assertContains('playlog-b.xml', $this->names($this->upload));
        static::assertSame([], $this->names($this->base . '/error'));
        static::assertSame([], $this->names($this->base . '/processed'));
    }

    #[Group('units')]
    public function testMissingConfigurationStopsBeforeAnyFileIsTouched(): void
    {
        $this->ingest->notConfigured = 'COLLECTOR_API_KEY is not set';
        $before                      = $this->names($this->upload);

        try
        {
            $this->runner()->run();
            static::fail('RetryableIngestException expected');
        }
        catch (RetryableIngestException $e)
        {
            static::assertSame('COLLECTOR_API_KEY is not set', $e->getMessage());
        }

        static::assertSame($before, $this->names($this->upload));
    }

    #[Group('units')]
    public function testDryRunDoesNotNeedConfiguration(): void
    {
        $this->ingest->notConfigured = 'COLLECTOR_API_KEY is not set';

        static::assertNotSame([], $this->runner()->run(dryRun: true));
    }

    #[Group('units')]
    public function testPlayLogWithoutEventsIsProcessedWithoutRequest(): void
    {
        $this->put('playlog-empty.xml', self::playLogXml('player-3', []));

        $results = $this->runner()->run(fileName: 'playlog-empty.xml');

        static::assertSame(['playlog-empty.xml' => 'sent'], $this->statuses($results));
        static::assertSame(0, $results[0]->events);
        static::assertSame([], $this->ingest->sent);
        static::assertSame(['playlog-empty.xml'], $this->names($this->base . '/processed'));
    }

    #[Group('units')]
    public function testOtherTypesAndUnknownFilesStayInUpload(): void
    {
        $this->runner()->run();

        $left = $this->names($this->upload);
        static::assertContains('event-a.xml', $left);
        static::assertContains('system-a.xml', $left);
        static::assertContains('notes.txt', $left);
    }

    #[Group('units')]
    public function testFileThatCanNotBeMovedKeepsTheRunHonest(): void
    {
        file_put_contents($this->base . '/processed', 'a file, so the directory can not be created');

        $results = $this->runner()->run(fileName: 'playlog-a.xml');

        static::assertSame('retry', $results[0]->status->value);
        static::assertStringContainsString('sent, but Can not create directory', (string) $results[0]->message);
        static::assertContains('playlog-a.xml', $this->names($this->upload));
    }

    #[Group('units')]
    public function testFilterByTypeLeavesOtherFilesAlone(): void
    {
        $results = $this->runner()->run(type: LogType::Event);

        static::assertSame(['event-a.xml' => 'skipped-unsupported'], $this->statuses($results));
        static::assertSame([], $this->ingest->sent);
    }

    #[Group('units')]
    public function testNewFilesAreSkippedUnlessNamedExplicitly(): void
    {
        $this->put('playlog-new.xml', self::playLogXml('p', [['1', '2026-10-03T10:00:00+02:00', '2026-10-03T10:00:10+02:00']]), time());

        $names = array_keys($this->statuses($this->runner()->run(type: LogType::PlayLog, dryRun: true)));
        $named = $this->runner()->run(fileName: 'playlog-new.xml', dryRun: true);

        static::assertNotContains('playlog-new.xml', $names);
        static::assertCount(1, $named);
    }

    #[Group('units')]
    public function testUnknownDeviceIsRejected(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Unknown device "other", known: smil');

        $this->runner()->run('other');
    }
}
