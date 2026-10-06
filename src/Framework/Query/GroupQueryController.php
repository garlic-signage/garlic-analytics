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

use App\Framework\Exceptions\ValidationException;
use Psr\Http\Message\ServerRequestInterface;

/**
 * The query of a group of players: the CMS sends the IDs of the players in the JSON body (the list can be too
 * long for a URL), together with the parameters of the other endpoints. It only reads, the route sets the scope
 * "read" (see ApiKeyMiddleware). The query service of such a controller has a PageQueryValidator for groups.
 */
abstract readonly class GroupQueryController extends QueryController
{
    /**
     * @return array<array-key,mixed>
     * @throws ValidationException
     */
    protected function parameters(ServerRequestInterface $request): array
    {
        $body = $request->getParsedBody();
        if (!is_array($body))
            throw new ValidationException(['body' => 'must be a JSON object']);

        return $body;
    }
}
