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

namespace App\Collector;

/**
 * The kinds of files a device uploads. Each one ends up in its own ingest endpoint.
 */
enum LogType: string
{
    case PlayLog = 'playlog';
    case Event   = 'event';
    case System  = 'system';

    /**
     * Path of the ingest endpoint, null if there is none yet.
     */
    public function endpoint(): ?string
    {
        return match ($this)
        {
            self::PlayLog => '/v1/playlog',
            self::Event   => '/v1/eventlog',
            self::System  => null,
        };
    }
}
