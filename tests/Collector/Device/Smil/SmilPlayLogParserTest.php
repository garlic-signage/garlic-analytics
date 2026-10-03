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

use App\Collector\Device\Smil\SmilPlayLogParser;
use App\Collector\Exceptions\ParseException;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Tests\Collector\CollectorTestHelper;

class SmilPlayLogParserTest extends TestCase
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
        $path = $this->dir . '/playlog-' . uniqid() . '.xml';
        file_put_contents($path, $xml);

        return $path;
    }

    #[Group('units')]
    public function testReadsPlayerAndAllPlayedContent(): void
    {
        $path = $this->file(self::playLogXml('player-1', [
            ['353270', '2026-10-03T15:34:55+02:00', '2026-10-03T15:35:26+02:00'],
            ['353052', '2026-10-03T15:35:27+02:00', '2026-10-03T15:35:32+02:00'],
        ]));

        $records = new SmilPlayLogParser()->parse($path);

        static::assertCount(2, $records);
        static::assertSame(
            ['player_id' => 'player-1', 'content_id' => '353270', 'start_time' => '2026-10-03T15:34:55+02:00', 'end_time' => '2026-10-03T15:35:26+02:00'],
            $records[0]->toArray()
        );
        static::assertSame('353052', $records[1]->contentId);
    }

    #[Group('units')]
    public function testNamespaceIsIgnored(): void
    {
        $xml = str_replace('http://schemas.garlic-player.com/gapi-1.0', 'http://schemas.adfotain.org/adapi-1.0', self::playLogXml('p', [['1', 'a', 'b']]));

        static::assertCount(1, new SmilPlayLogParser()->parse($this->file($xml)));
    }

    #[Group('units')]
    public function testWhitespaceAroundValuesIsTrimmed(): void
    {
        $xml = str_replace('<contentId>1</contentId>', "<contentId>\n 1 \n</contentId>", self::playLogXml(' p ', [['1', 'a', 'b']]));

        $records = new SmilPlayLogParser()->parse($this->file($xml));

        static::assertSame('p', $records[0]->playerId);
        static::assertSame('1', $records[0]->contentId);
    }

    #[Group('units')]
    public function testPlayLogWithoutEntriesGivesNoRecords(): void
    {
        static::assertSame([], new SmilPlayLogParser()->parse($this->file(self::playLogXml('p', []))));
    }

    #[Group('units')]
    public function testBrokenXmlIsRejected(): void
    {
        $path = $this->file(substr(self::playLogXml('p', [['1', 'a', 'b'], ['2', 'c', 'd']]), 0, -60));

        $this->expectException(ParseException::class);
        $this->expectExceptionMessage('Invalid XML');

        new SmilPlayLogParser()->parse($path);
    }

    #[Group('units')]
    public function testFileThatIsNoPlayLogIsRejected(): void
    {
        $path = $this->file('<report xmlns="http://schemas.adfotain.org/adapi-1.0"><player id="p"><playerEventLog></playerEventLog></player></report>');

        $this->expectException(ParseException::class);
        $this->expectExceptionMessage('not a play log');

        new SmilPlayLogParser()->parse($path);
    }

    #[Group('units')]
    public function testWrongRootElementIsRejected(): void
    {
        $this->expectException(ParseException::class);
        $this->expectExceptionMessage('Root element must be "report"');

        new SmilPlayLogParser()->parse($this->file('<html><body/></html>'));
    }

    #[Group('units')]
    public function testMissingPlayerIdIsRejected(): void
    {
        $xml = str_replace('<player id="p">', '<player>', self::playLogXml('p', [['1', 'a', 'b']]));

        $this->expectException(ParseException::class);
        $this->expectExceptionMessage('"id" of element "player"');

        new SmilPlayLogParser()->parse($this->file($xml));
    }

    #[Group('units')]
    public function testMissingFieldNamesEntryAndField(): void
    {
        $xml = str_replace('<endTime>d</endTime>', '', self::playLogXml('p', [['1', 'a', 'b'], ['2', 'c', 'd']]));

        $this->expectException(ParseException::class);
        $this->expectExceptionMessage('contentPlayed #2 has no endTime');

        new SmilPlayLogParser()->parse($this->file($xml));
    }

    #[Group('units')]
    public function testMissingFileIsRejected(): void
    {
        $this->expectException(ParseException::class);

        new SmilPlayLogParser()->parse($this->dir . '/nope.xml');
    }
}
