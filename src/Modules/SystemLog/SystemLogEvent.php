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

namespace App\Modules\SystemLog;

use DateTimeImmutable;

/**
 * One validated system report. Times are in UTC. Values the player does not report are null
 * (hdmiOutput: empty).
 */
readonly class SystemLogEvent
{
    public function __construct(
        public string            $playerId,
        public DateTimeImmutable $reportedAt,
        public DateTimeImmutable $systemStart,
        public string            $timeZone,
        public int               $diskTotal,
        public int               $diskFree,
        public ?int              $cpuUsage,
        public ?int              $memoryTotal,
        public ?int              $memoryUsed,
        public string            $hdmiOutput
    ) {}
}
