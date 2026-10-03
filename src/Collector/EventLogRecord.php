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
 * One player event as the device reported it, in the format of POST /v1/eventlog.
 * The time stays as sent (ISO 8601 with offset), the API converts it to UTC.
 */
readonly class EventLogRecord implements RecordInterface
{
    /**
     * @param array<string,string> $metadata
     */
    public function __construct(
        public string $playerId,
        public string $eventTime,
        public string $eventType,
        public string $eventSource,
        public string $eventName,
        public array  $metadata = []
    ) {}

    /**
     * @return array<string,mixed> "metadata" is left out if empty
     */
    public function toArray(): array
    {
        $data = [
            'player_id'    => $this->playerId,
            'event_time'   => $this->eventTime,
            'event_type'   => $this->eventType,
            'event_source' => $this->eventSource,
            'event_name'   => $this->eventName,
        ];
        if ($this->metadata !== [])
            $data['metadata'] = $this->metadata;

        return $data;
    }
}
