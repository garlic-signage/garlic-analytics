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

namespace App\Framework\Query;

use App\Framework\Exceptions\DatabaseException;

/**
 * Reads the raw events of one module.
 */
interface QueryRepositoryInterface
{
    /**
     * Number of all records of the player in the time range of the query (limit and offset do not matter).
     *
     * @throws DatabaseException
     */
    public function count(PageQuery $query): int;

    /**
     * The records of the page. Times are ISO 8601 strings in UTC ("2026-10-03T13:30:00Z").
     *
     * @return list<array<string,int|string>>
     * @throws DatabaseException
     */
    public function find(PageQuery $query): array;
}
