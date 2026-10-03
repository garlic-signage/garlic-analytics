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

namespace App\Framework\Database;

use App\Framework\Exceptions\DatabaseException;
use JsonException;

/**
 * Base of the repositories which write one request as one INSERT.
 */
abstract readonly class BatchRepository
{
    public function __construct(private ClickHouseClientInterface $client) {}

    /**
     * Writes all rows with one INSERT.
     *
     * The deduplication token is a hash of the rows: the same batch sent again (client retry)
     * is dropped, also in the tables of materialized views. Another batch with the same rows
     * in another order or size counts as new.
     *
     * @param list<list<int|string|array<string,string>|null>> $rows values in the order of $columns
     * @param list<string>                                     $columns
     * @throws DatabaseException
     * @throws JsonException
     */
    protected function insertRows(string $table, array $rows, array $columns): void
    {
        $context = hash_init('sha256');
        foreach ($rows as $row)
            hash_update($context, json_encode($row, JSON_THROW_ON_ERROR) . "\n");

        $this->client->insert($table, $rows, $columns, hash_final($context));
    }
}
