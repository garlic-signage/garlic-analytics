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

/**
 * Checks the optional filter parameters of one module (e.g. a type or a source) next to the common ones.
 */
interface FilterValidatorInterface
{
    /**
     * @param array<array-key,mixed> $params the query parameters of the request
     * @param array<string,string>   $errors collects the messages per parameter
     * @return array<string,string|list<string>> the filters to apply, only those the client asked for
     */
    public function validate(array $params, array &$errors): array;
}
