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

use App\Collector\Device\Smil\SmilAdapter;
use App\Collector\Device\Smil\SmilEventLogParser;
use App\Collector\Device\Smil\SmilPlayLogParser;
use App\Collector\LogType;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

class SmilAdapterTest extends TestCase
{
    #[Group('units')]
    #[DataProvider('fileNames')]
    public function testClassifyByFileName(string $fileName, ?LogType $expected): void
    {
        static::assertSame($expected, new SmilAdapter(new SmilPlayLogParser(), new SmilEventLogParser())->classify($fileName));
    }

    /** @return array<string,array{string,?LogType}> */
    public static function fileNames(): array
    {
        return [
            'playlog'        => ['playlog-dd871e62-c8b9-4334-a94f-da54c3c6131b.xml', LogType::PlayLog],
            'event'          => ['event-03a0952a-bf44-11f1-a494-2cc548046e60.xml', LogType::Event],
            'system'         => ['system-7d8d8cd004d93324.xml', LogType::System],
            'unknown prefix' => ['report-1.xml', null],
            'no xml'         => ['playlog-1.xml.tmp', null],
            'prefix inside'  => ['my-playlog-1.xml', null],
        ];
    }
}
