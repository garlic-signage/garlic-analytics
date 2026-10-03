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

namespace App\Collector\Ingest;

use App\Collector\Exceptions\RejectedIngestException;
use App\Collector\Exceptions\RetryableIngestException;
use App\Collector\PlayLogRecord;

/**
 * The way into the ingest API. The collector only knows this interface, so the API can live elsewhere
 * (or be replaced in tests).
 */
interface IngestClientInterface
{
    /**
     * Checks the settings before any file is touched.
     *
     * @throws RetryableIngestException if something is missing
     */
    public function ensureConfigured(): void;

    /**
     * Sends one block of play log records. Returns normally only if the API accepted it.
     *
     * @param list<PlayLogRecord> $records
     * @throws RejectedIngestException  the data is invalid
     * @throws RetryableIngestException try again later
     */
    public function sendPlayLog(array $records): void;
}
