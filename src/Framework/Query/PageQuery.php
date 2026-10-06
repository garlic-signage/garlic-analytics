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

namespace App\Framework\Query;

use DateTimeImmutable;

/**
 * A validated query for the events of one player or of a group of players: a time range ($from included,
 * $to excluded, both UTC) and one page of the result. $filters are the optional, module specific conditions
 * (name => value or list of values), empty if the client asked for none.
 *
 * A query of one player has its ID in $playerId and no $playerIds. A query of a group (the CMS sends the
 * IDs of the players) has the IDs in $playerIds, without duplicates, and an empty $playerId. Only the
 * endpoints that aggregate know groups, see PlayerCondition.
 */
readonly class PageQuery
{
    /**
     * @param array<string,string|list<string>> $filters
     * @param list<string>                      $playerIds
     */
    public function __construct(
        public string            $playerId,
        public DateTimeImmutable $from,
        public DateTimeImmutable $to,
        public int               $limit,
        public int               $offset,
        public bool              $descending,
        public array             $filters = [],
        public array             $playerIds = []
    ) {}
}
