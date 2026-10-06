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

use App\Modules\ConnectLog\ConnectLogController;
use App\Modules\ConnectLog\ConnectLogFilterValidator;
use App\Modules\ConnectLog\ConnectLogQueryController;
use App\Modules\ConnectLog\ConnectLogQueryRepository;
use App\Modules\ConnectLog\ConnectLogRawQueryController;
use App\Modules\ConnectLog\ConnectLogRawQueryRepository;
use App\Modules\ConnectLog\ConnectLogRepository;
use App\Modules\ConnectLog\ConnectLogService;
use App\Modules\ConnectLog\ConnectLogValidator;

/** @var Closure(string, string, string, string, string): array<string,mixed> $defineIngest */
$defineIngest = require __DIR__ . '/../ingest_module.php';
/** @var Closure(string, string, string, string|null=, string|null=): array<string,mixed> $defineQuery */
$defineQuery  = require __DIR__ . '/../query_module.php';

return $defineIngest('connectlog', ConnectLogController::class, ConnectLogService::class, ConnectLogValidator::class, ConnectLogRepository::class)
    + $defineQuery('connectlog', ConnectLogQueryController::class, ConnectLogQueryRepository::class, ConnectLogFilterValidator::class)
    + $defineQuery('connectlog', ConnectLogRawQueryController::class, ConnectLogRawQueryRepository::class, null, 'max_raw_range_hours');
