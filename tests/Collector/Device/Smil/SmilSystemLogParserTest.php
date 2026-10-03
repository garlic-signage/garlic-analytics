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

namespace Tests\Collector\Device\Smil;

use App\Collector\Device\Smil\SmilSystemLogParser;
use App\Collector\Exceptions\ParseException;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Tests\Collector\CollectorTestHelper;

class SmilSystemLogParserTest extends TestCase
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

    private function file(string $xml): string
    {
        $path = $this->dir . '/system-' . uniqid() . '.xml';
        file_put_contents($path, $xml);

        return $path;
    }

    /** @return array<string,string> */
    private function info(): array
    {
        return [
            'systemStartTime' => '2026-10-03T03:06:52+0200',
            'timeZone'        => 'MEZ',
            'totalCapacity'   => '12245270528',
            'totalFreeSpace'  => '11792592896',
        ];
    }

    #[Group('units')]
    public function testReadsAnOlderPlayerReportAndNormalizesTheOffset(): void
    {
        $records = new SmilSystemLogParser()->parse($this->file(self::systemLogXml('player-1', '2026-10-03T18:27:36+0200', $this->info())));

        static::assertCount(1, $records);
        static::assertSame(
            [
                'player_id'    => 'player-1',
                'reported_at'  => '2026-10-03T18:27:36+02:00',
                'system_start' => '2026-10-03T03:06:52+02:00',
                'time_zone'    => 'MEZ',
                'disk_total'   => 12245270528,
                'disk_free'    => 11792592896,
            ],
            $records[0]->toArray()
        );
    }

    #[Group('units')]
    public function testReadsCpuMemoryAndHdmiOfANewerReport(): void
    {
        $info = $this->info() + ['cpuUsage' => '31%', 'memoryTotal' => '1048576', 'memoryUsed' => '969224', 'hdmiOutput' => '1920x1080p-60'];
        unset($info['timeZone']);
        $info['systemTimeZone'] = 'Europe/Berlin';

        $record = new SmilSystemLogParser()->parse($this->file(self::systemLogXml('p', '2026-10-03T18:27:36Z', $info)))[0];

        static::assertSame('Europe/Berlin', $record->timeZone);
        static::assertSame('2026-10-03T18:27:36Z', $record->reportedAt);
        static::assertSame(31, $record->cpuUsage);
        static::assertSame(1048576, $record->memoryTotal);
        static::assertSame(969224, $record->memoryUsed);
        static::assertSame('1920x1080p-60', $record->hdmiOutput);
    }

    #[Group('units')]
    public function testNetworkConfigurationAndHardwareAreNotTaken(): void
    {
        $record = new SmilSystemLogParser()->parse($this->file(self::systemLogXml('p', '2026-10-03T18:27:36Z', $this->info())))[0];

        $json = json_encode($record->toArray(), JSON_THROW_ON_ERROR);
        static::assertStringNotContainsString('secret', $json);
        static::assertStringNotContainsString('192.168', $json);
        static::assertStringNotContainsString('80:e4', $json);
        static::assertStringNotContainsString('Screen', $json);
    }

    #[Group('units')]
    public function testEmptyOptionalElementsAreLeftOut(): void
    {
        $info = $this->info() + ['cpuUsage' => '', 'hdmiOutput' => ''];

        $data = new SmilSystemLogParser()->parse($this->file(self::systemLogXml('p', '2026-10-03T18:27:36Z', $info)))[0]->toArray();

        static::assertArrayNotHasKey('cpu_usage', $data);
        static::assertArrayNotHasKey('hdmi_output', $data);
    }

    #[Group('units')]
    public function testMissingRequiredElementIsAnError(): void
    {
        $info = $this->info();
        unset($info['totalFreeSpace']);

        $this->expectException(ParseException::class);
        $this->expectExceptionMessageIsOrContains('systemInfo #1 has no totalFreeSpace');

        new SmilSystemLogParser()->parse($this->file(self::systemLogXml('p', '2026-10-03T18:27:36Z', $info)));
    }

    #[Group('units')]
    public function testMissingDateIsAnError(): void
    {
        $xml = str_replace('<date>2026-10-03T18:27:36Z</date>', '', self::systemLogXml('p', '2026-10-03T18:27:36Z', $this->info()));

        $this->expectException(ParseException::class);
        $this->expectExceptionMessageIsOrContains('systemInfo #1 has no date');

        new SmilSystemLogParser()->parse($this->file($xml));
    }

    #[Group('units')]
    public function testValueThatIsNoNumberIsAnError(): void
    {
        $info                  = $this->info();
        $info['totalCapacity'] = '12 GB';

        $this->expectException(ParseException::class);
        $this->expectExceptionMessageIsOrContains('systemInfo #1: totalCapacity is not a number');

        new SmilSystemLogParser()->parse($this->file(self::systemLogXml('p', '2026-10-03T18:27:36Z', $info)));
    }

    #[Group('units')]
    public function testCpuUsageWithoutNumberIsAnError(): void
    {
        $info             = $this->info();
        $info['cpuUsage'] = 'high';

        $this->expectException(ParseException::class);
        $this->expectExceptionMessageIsOrContains('systemInfo #1: cpuUsage is not a number');

        new SmilSystemLogParser()->parse($this->file(self::systemLogXml('p', '2026-10-03T18:27:36Z', $info)));
    }

    #[Group('units')]
    public function testFileWithoutSystemInfoGivesNoRecords(): void
    {
        $xml = '<report><date>2026-10-03T18:27:36Z</date><player id="p"></player></report>';

        static::assertSame([], new SmilSystemLogParser()->parse($this->file($xml)));
    }

    #[Group('units')]
    public function testReportWithoutPlayerIsRejected(): void
    {
        $this->expectException(ParseException::class);
        $this->expectExceptionMessageIsOrContains('Element "player" is missing, this is not a system report');

        new SmilSystemLogParser()->parse($this->file('<report><date>2026-10-03T18:27:36Z</date></report>'));
    }
}
