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

namespace App\Framework\Ingest;

use App\Framework\Exceptions\DatabaseException;
use App\Framework\Exceptions\ValidationException;

/**
 * Ingest of events: validate, then store. The modules extend it for their event type.
 *
 * @template T of object
 */
abstract readonly class IngestService
{
    /**
     * @param IngestValidatorInterface<T>  $validator
     * @param IngestRepositoryInterface<T> $repository
     */
    public function __construct(
        private IngestValidatorInterface  $validator,
        private IngestRepositoryInterface $repository
    ) {}

    /**
     * @return int number of events in the request (a repeated batch is dropped silently, the answer stays the same)
     * @throws ValidationException
     * @throws DatabaseException
     */
    public function ingest(mixed $body): int
    {
        $events = $this->validator->validate($body);
        $this->repository->insertBatch($events);

        return count($events);
    }
}
