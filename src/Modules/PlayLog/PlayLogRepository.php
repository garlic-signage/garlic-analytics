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

namespace App\Modules\PlayLog;

use App\Framework\Database\ClickHouseClientInterface;
use App\Framework\Exceptions\DatabaseException;
use JsonException;

/**
 * Inserts and queries the table play_log.
 *
 * ClickHouse fills duration_s and received_at, play_hourly by its materialized view.
 */
readonly class PlayLogRepository
{
    private const string TABLE = 'play_log';
    private const string TIME_FORMAT = 'Y-m-d H:i:s';

    public function __construct(private ClickHouseClientInterface $client) {}

    /**
     * Writes all events with one INSERT.
     *
     * The deduplication token is a hash of the events: the same batch sent again (client retry)
     * is dropped, also in play_hourly. Another batch with the same events in another
     * order or size counts as new.
     *
     * @param list<PlayLogEvent> $events
     * @throws DatabaseException
     * @throws JsonException
     */
    public function insertBatch(array $events): void
    {
        $rows    = [];
        $context = hash_init('sha256');
        foreach ($events as $event)
        {
            $row = [
                $event->playerId,
                $event->contentId,
                $event->startTime->format(self::TIME_FORMAT),
                $event->endTime->format(self::TIME_FORMAT)
            ];
            hash_update($context, json_encode($row, JSON_THROW_ON_ERROR) . "\n");
            $rows[] = $row;
        }

        $this->client->insert(
            self::TABLE,
            $rows,
            ['player_id', 'content_id', 'start_time', 'end_time'],
            hash_final($context)
        );
    }
}
