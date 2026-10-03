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

use App\Collector\Exceptions\RetryableIngestException;
use App\Collector\Ingest\IngestClientInterface;
use App\Collector\PlayLogRecord;
use Closure;

/**
 * Ingest client for tests: remembers the blocks it got, can be told to fail.
 */
final class FakeIngestClient implements IngestClientInterface
{
    /** @var list<list<PlayLogRecord>> */
    public array $sent = [];
    /** @var (Closure(int): void)|null called with the number of the block (1, 2, ...) before it is accepted, may throw */
    public ?Closure $onSend = null;
    public ?string $notConfigured = null;

    public function ensureConfigured(): void
    {
        if ($this->notConfigured !== null)
            throw new RetryableIngestException($this->notConfigured);
    }

    public function sendPlayLog(array $records): void
    {
        if ($this->onSend !== null)
            ($this->onSend)(count($this->sent) + 1);

        $this->sent[] = $records;
    }
}
