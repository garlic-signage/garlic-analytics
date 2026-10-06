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

use App\Modules\PlayLog\PlayLogController;
use App\Modules\PlayLog\PlayLogQueryController;
use App\Modules\PlayLog\PlayLogQueryRepository;
use App\Modules\PlayLog\PlayLogRepository;
use App\Modules\PlayLog\PlayLogStatsController;
use App\Modules\PlayLog\PlayLogStatsFilterValidator;
use App\Modules\PlayLog\PlayLogStatsPeriodController;
use App\Modules\PlayLog\PlayLogStatsPeriodFilterValidator;
use App\Modules\PlayLog\PlayLogStatsPeriodRepository;
use App\Modules\PlayLog\PlayLogStatsRepository;
use App\Modules\PlayLog\PlayLogService;
use App\Modules\PlayLog\PlayLogValidator;

/** @var callable(string, string, string, string, string): array<string,mixed> $defineIngest */
$defineIngest = require __DIR__ . '/../ingest_module.php';
/** @var callable(string, string, string, string|null=, string|null=, bool=): array<string,mixed> $defineQuery */
$defineQuery = require __DIR__ . '/../query_module.php';

return $defineIngest('playlog', PlayLogController::class, PlayLogService::class, PlayLogValidator::class, PlayLogRepository::class)
    + $defineQuery('playlog', PlayLogQueryController::class, PlayLogQueryRepository::class)
    + $defineQuery('playlog', PlayLogStatsController::class, PlayLogStatsRepository::class, PlayLogStatsFilterValidator::class, null, false)
    + $defineQuery('playlog', PlayLogStatsPeriodController::class, PlayLogStatsPeriodRepository::class, PlayLogStatsPeriodFilterValidator::class);
