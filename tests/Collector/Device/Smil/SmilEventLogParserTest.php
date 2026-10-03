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

use App\Collector\Device\Smil\SmilEventLogParser;
use App\Collector\Exceptions\ParseException;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Tests\Collector\CollectorTestHelper;

class SmilEventLogParserTest extends TestCase
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
        $path = $this->dir . '/event-' . uniqid() . '.xml';
        file_put_contents($path, $xml);

        return $path;
    }

    #[Group('units')]
    public function testReadsPlayerEventsAndMetadata(): void
    {
        $path = $this->file(self::eventLogXml('player-1', [
            ['warning', '2026-10-03T15:34:55+02:00', 'ContentManager', 'FETCH_FAILED', ['resourceURI' => 'https://a/b?c=1&d="2"', 'transferLength' => '0']],
            ['informational', '2026-10-03T15:35:27Z', 'System', 'SMIL_LOADED', []],
        ]));

        $records = new SmilEventLogParser()->parse($path);

        static::assertCount(2, $records);
        static::assertSame(
            [
                'player_id'    => 'player-1',
                'event_time'   => '2026-10-03T15:34:55+02:00',
                'event_type'   => 'warning',
                'event_source' => 'ContentManager',
                'event_name'   => 'FETCH_FAILED',
                'metadata'     => ['resourceURI' => 'https://a/b?c=1&d="2"', 'transferLength' => '0'],
            ],
            $records[0]->toArray()
        );
        static::assertSame([], $records[1]->metadata);
        static::assertArrayNotHasKey('metadata', $records[1]->toArray());
    }

    #[Group('units')]
    public function testEventTypeIsLowerCasedAndValuesAreTrimmed(): void
    {
        $xml = str_replace('<eventType>error</eventType>', "<eventType>\n Error \n</eventType>", self::eventLogXml(' p ', [['error', 'a', 's', 'n', []]]));

        $records = new SmilEventLogParser()->parse($this->file($xml));

        static::assertSame('p', $records[0]->playerId);
        static::assertSame('error', $records[0]->eventType);
    }

    #[Group('units')]
    public function testLastContentOfARepeatedMetaNameWins(): void
    {
        $xml = str_replace('</metadata>', '<meta name="a" content="2"/></metadata>', self::eventLogXml('p', [['error', 'a', 's', 'n', ['a' => '1']]]));

        static::assertSame(['a' => '2'], new SmilEventLogParser()->parse($this->file($xml))[0]->metadata);
    }

    #[Group('units')]
    public function testEmptyEventLogGivesNoRecords(): void
    {
        static::assertSame([], new SmilEventLogParser()->parse($this->file(self::eventLogXml('p', []))));
    }

    #[Group('units')]
    public function testMissingFieldIsAnError(): void
    {
        $xml = str_replace('<eventName>n</eventName>', '', self::eventLogXml('p', [['error', 'a', 's', 'n', []]]));

        $this->expectException(ParseException::class);
        $this->expectExceptionMessageIsOrContains('event #1 has no eventName');

        new SmilEventLogParser()->parse($this->file($xml));
    }

    #[Group('units')]
    public function testMetaWithoutNameIsAnError(): void
    {
        $xml = str_replace('</metadata>', '<meta content="x"/></metadata>', self::eventLogXml('p', [['error', 'a', 's', 'n', ['a' => '1']]]));

        $this->expectException(ParseException::class);
        $this->expectExceptionMessageIsOrContains('A meta element has no name');

        new SmilEventLogParser()->parse($this->file($xml));
    }

    #[Group('units')]
    public function testPlayLogIsNotAnEventLog(): void
    {
        $this->expectException(ParseException::class);
        $this->expectExceptionMessageIsOrContains('Element "playerEventLog" is missing, this is not an event log');

        new SmilEventLogParser()->parse($this->file(self::playLogXml('p', [])));
    }

    #[Group('units')]
    public function testMissingPlayerIdAndBrokenXmlAreErrors(): void
    {
        $noPlayer = str_replace('<player id="p">', '<player>', self::eventLogXml('p', [['error', 'a', 's', 'n', []]]));
        try
        {
            new SmilEventLogParser()->parse($this->file($noPlayer));
            static::fail('ParseException expected');
        }
        catch (ParseException $e)
        {
            static::assertStringContainsString('Attribute "id" of element "player" is missing', $e->getMessage());
        }

        $this->expectException(ParseException::class);
        $this->expectExceptionMessageIsOrContains('Invalid XML');

        new SmilEventLogParser()->parse($this->file('<report><player id="p"><playerEventLog><event>'));
    }
}
