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
use App\Collector\PlayLogRecord;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

class BatchSplitterTest extends TestCase
{
    /** @return list<PlayLogRecord> */
    private function records(int $count): array
    {
        $records = [];
        for ($i = 1; $i <= $count; $i++)
            $records[] = new PlayLogRecord('p', (string) $i, 'a', 'b');

        return $records;
    }

    #[Group('units')]
    public function testSplitsIntoBlocksInOrder(): void
    {
        $blocks = new BatchSplitter(2)->split($this->records(5));

        static::assertCount(3, $blocks);
        static::assertCount(2, $blocks[0]);
        static::assertCount(1, $blocks[2]);
        static::assertSame('3', $blocks[1][0]->contentId);
    }

    #[Group('units')]
    public function testSameInputGivesSameBlocks(): void
    {
        $splitter = new BatchSplitter(3);

        static::assertEquals($splitter->split($this->records(7)), $splitter->split($this->records(7)));
    }

    #[Group('units')]
    public function testNoRecordsGiveNoBlocks(): void
    {
        static::assertSame([], new BatchSplitter(5)->split([]));
    }

    #[Group('units')]
    public function testSizeBelowOneIsRejected(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new BatchSplitter(0);
    }
}
