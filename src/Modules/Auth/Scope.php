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

namespace App\Modules\Auth;

/**
 * What an API key is allowed to do.
 *
 * Ingest writes events, Read queries them. The collector only gets Ingest.
 */
enum Scope: string
{
    case Ingest = 'ingest';
    case Read   = 'read';

    /**
     * Reading requests (GET, HEAD) need Read, everything else needs Ingest.
     */
    public static function forMethod(string $method): self
    {
        return match (strtoupper($method))
        {
            'GET', 'HEAD' => self::Read,
            default       => self::Ingest,
        };
    }
}
