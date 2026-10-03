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

use FilesystemIterator;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;

/**
 * Temporary directories and XML files for the collector tests.
 */
trait CollectorTestHelper
{
    /** @var list<string> */
    private array $tempDirs = [];

    private function createTempDir(): string
    {
        $dir = sys_get_temp_dir() . '/collector_test_' . uniqid('', true);
        mkdir($dir);
        $this->tempDirs[] = $dir;

        return $dir;
    }

    private function removeTempDirs(): void
    {
        foreach ($this->tempDirs as $dir)
        {
            $items = new RecursiveIteratorIterator(
                new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS),
                RecursiveIteratorIterator::CHILD_FIRST
            );
            /** @var SplFileInfo $item */
            foreach ($items as $item)
                $item->isDir() && !$item->isLink() ? rmdir($item->getPathname()) : unlink($item->getPathname());
            rmdir($dir);
        }
        $this->tempDirs = [];
    }

    /**
     * @param list<array{string,string,string}> $played contentId, startTime, endTime
     */
    private static function playLogXml(string $playerId, array $played): string
    {
        $items = '';
        foreach ($played as [$contentId, $start, $end])
            $items .= "<contentPlayed><contentId>$contentId</contentId><startTime>$start</startTime><endTime>$end</endTime></contentPlayed>\n";

        return '<?xml version="1.0" encoding="UTF-8"?>' . "\n"
            . '<report xmlns="http://schemas.garlic-player.com/gapi-1.0"><date>2026-10-03T14:35:25Z</date><version>1.0</version>'
            . '<player id="' . $playerId . '"><contentPlayLog>' . "\n" . $items . '</contentPlayLog></player></report>';
    }

    /**
     * @param list<array{string,string,string,string,array<string,string>}> $events type, time, source, name, metadata (no metadata element if empty)
     */
    private static function eventLogXml(string $playerId, array $events): string
    {
        $items = '';
        foreach ($events as [$type, $time, $source, $name, $metadata])
        {
            $meta = '';
            foreach ($metadata as $key => $content)
                $meta .= '<meta name="' . $key . '" content="' . htmlspecialchars($content, ENT_XML1 | ENT_QUOTES) . '"/>';

            $items .= "<event><eventType>$type</eventType><eventTime>$time</eventTime><eventSource>$source</eventSource><eventName>$name</eventName>"
                . ($meta === '' ? '' : "<metadata>$meta</metadata>") . "</event>\n";
        }

        return '<?xml version="1.0" encoding="UTF-8"?>' . "\n"
            . '<report xmlns="http://schemas.garlic-player.com/gapi-1.0"><date>2026-10-03T14:35:25Z</date><version>1.0</version>'
            . '<player id="' . $playerId . '"><playerEventLog>' . "\n" . $items . '</playerEventLog></player></report>';
    }

    /**
     * Like a report of a player: network and configuration are in it and must not be taken.
     *
     * @param array<string,string> $info elements of systemInfo by name, e.g. totalCapacity
     */
    private static function systemLogXml(string $playerId, string $date, array $info): string
    {
        $elements = '';
        foreach ($info as $name => $value)
            $elements .= "<$name>$value</$name>";

        return '<?xml version="1.0" encoding="UTF-8"?>'
            . '<report xmlns="http://schemas.adfotain.org/adapi-1.0"> <date>' . $date . '</date> <version>1.0.385</version> '
            . '<player id="' . $playerId . '"><systemInfo>' . $elements
            . '<network><interface id="eth0"><mac>80:e4:da:85:17:2e</mac><ip>192.168.1.113</ip></interface></network>'
            . '<configuration><modelInfo><prop name="appVersion" value="1.0.385" /><prop name="PCB" value="app_password: secret," /></modelInfo></configuration>'
            . '</systemInfo><hardwareInfo><hardware id="display:0"><prop name="modelName" value="Screen" /></hardware></hardwareInfo></player></report>';
    }
}
