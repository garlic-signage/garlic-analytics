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

use App\Framework\Exceptions\CoreException;
use InvalidArgumentException;

/**
 * Storage of API keys. Only SHA-256 hashes are stored, never the key itself.
 * Every client has a unique name.
 */
interface ApiKeyStoreInterface
{
    /**
     * @return list<ApiClient>
     * @throws CoreException
     */
    public function all(): array;

    /**
     * @throws CoreException
     */
    public function findByKeyHash(string $keyHash): ?ApiClient;

    /**
     * @param list<Scope> $scopes
     * @throws InvalidArgumentException if a key with this name already exists
     * @throws CoreException
     */
    public function add(string $name, string $keyHash, array $scopes): void;

    /**
     * @return bool false if there is no key with this name
     * @throws CoreException
     */
    public function remove(string $name): bool;
}
