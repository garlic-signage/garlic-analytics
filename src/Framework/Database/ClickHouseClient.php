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


namespace App\Framework\Database;

use App\Framework\Exceptions\DatabaseException;
use ClickHouseDB\Client;
use ClickHouseDB\Exception\ClickHouseException;
use ClickHouseDB\Query\Expression\Raw;
use ClickHouseDB\Quote\FormatLine;
use Throwable;

readonly class ClickHouseClient implements ClickHouseClientInterface
{
    private Client $client;

    public function __construct(Client $client)
    {
        $this->client = $client;
    }

    public function execute(string $sql): void
    {
        $this->client->write($sql);
    }

    public function ensureDatabase(): void
    {
        try
        {
            $this->client->write('SELECT 1');
            return;
        }
        catch (ClickHouseException $e)
        {
            $message = $this->firstLine($e);
            if (!str_contains($message, 'UNKNOWN_DATABASE'))
                throw new DatabaseException('ClickHouse is not usable: ' . $message, 0, $e);
        }

        $this->createDatabase();
    }

    /**
     * Every request names the database, so the statement is sent in the context of "default".
     *
     * @throws DatabaseException
     */
    private function createDatabase(): void
    {
        $configured = $this->client->settings()->getDatabase();
        $database   = is_string($configured) ? $configured : '';
        if (preg_match('/^[A-Za-z0-9_]+$/', $database) !== 1)
            throw new DatabaseException('The database name "' . $database . '" is not valid, use letters, digits and underscore.');

        try
        {
            $this->client->database('default');
            $this->client->write('CREATE DATABASE IF NOT EXISTS `' . $database . '`');
        }
        catch (ClickHouseException $e)
        {
            throw new DatabaseException('Database "' . $database . '" does not exist and can not be created: ' . $this->firstLine($e), 0, $e);
        }
        finally
        {
            $this->client->database($database);
        }
    }

    private function firstLine(Throwable $e): string
    {
        $line = strtok($e->getMessage(), "\n");

        return $line === false ? 'unknown error' : $line;
    }

    public function insert(string $table, array $rows, array $columns, ?string $deduplicationToken = null): void
    {
        if ($rows === [])
            return;

        $values = [];
        foreach ($rows as $row)
            $values[] = '(' . FormatLine::Insert(array_map($this->mapToLiteral(...), $row)) . ')';

        $sql = 'INSERT INTO `' . $table . '` (`' . implode('`,`', $columns) . '`) VALUES ' . implode(',', $values);

        // the second setting makes the token count for materialized views too (needs a window on their target table)
        $settings = $deduplicationToken === null ? [] : [
            'insert_deduplication_token'                         => $deduplicationToken,
            'deduplicate_blocks_in_dependent_materialized_views' => 1,
        ];

        try
        {
            $this->client->write($sql, [], true, $settings);
        }
        catch (ClickHouseException $e)
        {
            throw new DatabaseException('Insert into ' . $table . ' failed: ' . $e->getMessage(), 0, $e);
        }
    }

    /**
     * The driver quotes a PHP array as a list "[...]", a Map needs the literal {'key':'value'}.
     */
    private function mapToLiteral(mixed $value): mixed
    {
        if (!is_array($value))
            return $value;

        /** @var array<array-key,string> $value */
        $pairs = [];
        foreach ($value as $key => $item)
            $pairs[] = $this->quote((string) $key) . ':' . $this->quote($item);

        return new Raw('{' . implode(',', $pairs) . '}');
    }

    private function quote(string $value): string
    {
        return "'" . addcslashes($value, "\\'") . "'";
    }
}
