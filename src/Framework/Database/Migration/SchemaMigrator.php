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


namespace App\Framework\Database\Migration;

use App\Framework\Database\ClickHouseClientInterface;
use App\Framework\Exceptions\DatabaseException;
use RuntimeException;

/**
 * The SchemaMigrator class is a lightweight database schema migration runner designed to
 * execute SQL migration scripts against a ClickHouse database using an injected
 * ClickHouseClientInterface instance.
 *
 * Sequential Execution: Sorts the list of file paths alphabetically via sort($files, SORT_STRING)
 * to ensure migration scripts execute in a predictable order (e.g., 001_init.sql, 002_create_tables.sql).
 */
readonly class SchemaMigrator
{
    public function __construct(
        private ClickHouseClientInterface $client,
        private string                    $directory
    ) {}

    /**
     * Makes sure first that ClickHouse is reachable and the database exists (it is created if not),
     * so a wrong setup is reported before any file is read.
     *
     * @return list<string> ausgeführte Dateien
     * @throws DatabaseException
     */
    public function migrate(): array
    {
        $this->client->ensureDatabase();

        $files = glob($this->directory . '/*.sql');
        if ($files === false)
            throw new RuntimeException('Cannot read migrations directory: ' . $this->directory);

        sort($files, SORT_STRING);

        foreach ($files as $file)
        {
            foreach ($this->splitStatements((string) file_get_contents($file)) as $statement)
                $this->client->execute($statement);
        }

        return array_map('basename', $files);
    }

    /** @return list<string> */
    private function splitStatements(string $sql): array
    {
        $sql   = preg_replace('/^\s*--.*$/m', '', $sql) ?? '';
        $parts = array_map('trim', explode(';', $sql));

        return array_values(array_filter($parts, fn(string $p) => $p !== ''));
    }
}