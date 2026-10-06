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

use App\Modules\EventLog\EventLogController;
use App\Modules\EventLog\EventLogFilterValidator;
use App\Modules\EventLog\EventLogQueryController;
use App\Modules\EventLog\EventLogQueryRepository;
use App\Modules\EventLog\EventLogRepository;
use App\Modules\EventLog\EventLogService;
use App\Modules\EventLog\EventLogValidator;

/** @var Closure(string, string, string, string, string): array<string,mixed> $defineIngest */
$defineIngest = require __DIR__ . '/../ingest_module.php';
/** @var Closure(string, string, string, string): array<string,mixed> $defineQuery */
$defineQuery  = require __DIR__ . '/../query_module.php';

return $defineIngest('eventlog', EventLogController::class, EventLogService::class, EventLogValidator::class, EventLogRepository::class)
    + $defineQuery('eventlog', EventLogQueryController::class, EventLogQueryRepository::class, EventLogFilterValidator::class);
