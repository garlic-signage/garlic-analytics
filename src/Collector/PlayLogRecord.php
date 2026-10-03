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
 * One playback as the device reported it, in the format of POST /v1/playlog.
 * The times stay as sent (ISO 8601 with offset), the API converts them to UTC.
 */
readonly class PlayLogRecord
{
    public function __construct(
        public string $playerId,
        public string $contentId,
        public string $startTime,
        public string $endTime
    ) {}

    /**
     * @return array{player_id: string, content_id: string, start_time: string, end_time: string}
     */
    public function toArray(): array
    {
        return [
            'player_id'  => $this->playerId,
            'content_id' => $this->contentId,
            'start_time' => $this->startTime,
            'end_time'   => $this->endTime,
        ];
    }
}
