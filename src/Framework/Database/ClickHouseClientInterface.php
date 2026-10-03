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

/**
 * Interface representing a client for interacting with a ClickHouse database.
 */
interface ClickHouseClientInterface
{
    public function execute(string $sql): void;

    /**
     * Inserts rows with one INSERT statement.
     *
     * With a deduplication token a repeated insert with the same token is dropped, in the table
     * and in the tables of its materialized views. The same token with other rows is dropped too.
     *
     * @param list<list<int|string>> $rows    values in the order of $columns
     * @param list<string>           $columns
     * @throws DatabaseException
     */
    public function insert(string $table, array $rows, array $columns, ?string $deduplicationToken = null): void;
}