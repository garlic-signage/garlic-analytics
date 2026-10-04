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
use App\Framework\Exceptions\ValidationException;

/**
 * Query of the raw events of a player: validate the parameters, then count and read one page.
 */
readonly class QueryService
{
    public function __construct(
        private PageQueryValidator       $validator,
        private QueryRepositoryInterface $repository
    ) {}

    /**
     * @param array<array-key,mixed> $params the query parameters of the request
     * @throws ValidationException
     * @throws DatabaseException
     */
    public function query(array $params): Page
    {
        $query = $this->validator->validate($params);

        $total = $this->repository->count($query);
        $items = $total === 0 ? [] : $this->repository->find($query);

        return new Page($total, $query->limit, $query->offset, $items);
    }
}
