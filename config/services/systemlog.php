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

use App\Modules\SystemLog\SystemLogController;
use App\Modules\SystemLog\SystemLogQueryController;
use App\Modules\SystemLog\SystemLogQueryRepository;
use App\Modules\SystemLog\SystemLogRepository;
use App\Modules\SystemLog\SystemLogService;
use App\Modules\SystemLog\SystemLogValidator;

/** @var Closure(string, string, string, string, string): array<string,mixed> $defineIngest */
$defineIngest = require __DIR__ . '/../ingest_module.php';
/** @var Closure(string, string, string): array<string,mixed> $defineQuery */
$defineQuery  = require __DIR__ . '/../query_module.php';

return $defineIngest('systemlog', SystemLogController::class, SystemLogService::class, SystemLogValidator::class, SystemLogRepository::class)
    + $defineQuery('systemlog', SystemLogQueryController::class, SystemLogQueryRepository::class);
