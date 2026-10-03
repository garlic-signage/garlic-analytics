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

namespace App\Collector;

use InvalidArgumentException;

/**
 * Cuts the records of one file into blocks for the ingest API.
 *
 * The cut only depends on the order and the size, so the same file always gives the same blocks.
 * That is what lets the API drop blocks it already has when a file is sent again after a failure.
 */
readonly class BatchSplitter
{
    /** @var int<1, max> */
    private int $size;

    public function __construct(int $size)
    {
        if ($size < 1)
            throw new InvalidArgumentException('Batch size must be at least 1');

        $this->size = $size;
    }

    /**
     * @template T of RecordInterface
     * @param list<T> $records
     * @return list<list<T>>
     */
    public function split(array $records): array
    {
        return array_chunk($records, $this->size);
    }
}
