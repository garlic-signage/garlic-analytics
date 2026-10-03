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
use JsonException;

/**
 * Stores API keys in a JSON file, keyed by client name:
 *
 *   {"garlic-hub": {"hash": "<sha256>", "scopes": ["ingest", "read"]}}
 *
 * The file lives in var/keys/, outside the docroot and the repository.
 * It is written atomically (temporary file + rename) with mode 0600.
 */
readonly class JsonFileApiKeyStore implements ApiKeyStoreInterface
{
    public function __construct(private string $file) {}

    public function all(): array
    {
        $clients = [];
        foreach ($this->load() as $name => $entry)
            $clients[] = new ApiClient($name, $entry['scopes']);

        return $clients;
    }

    public function findByKeyHash(string $keyHash): ?ApiClient
    {
        foreach ($this->load() as $name => $entry)
        {
            if (hash_equals($entry['hash'], $keyHash))
                return new ApiClient($name, $entry['scopes']);
        }

        return null;
    }

    public function add(string $name, string $keyHash, array $scopes): void
    {
        $entries = $this->load();
        if (array_key_exists($name, $entries))
            throw new InvalidArgumentException('An API key named "' . $name . '" already exists.');

        $entries[$name] = ['hash' => $keyHash, 'scopes' => $scopes];
        $this->save($entries);
    }

    public function remove(string $name): bool
    {
        $entries = $this->load();
        if (!array_key_exists($name, $entries))
            return false;

        unset($entries[$name]);
        $this->save($entries);

        return true;
    }

    /**
     * A missing file means no keys exist yet. A broken file throws,
     * so a damaged key file never looks like an empty one.
     *
     * @return array<string, array{hash: string, scopes: list<Scope>}>
     * @throws CoreException
     */
    private function load(): array
    {
        if (!is_file($this->file))
            return [];

        $content = file_get_contents($this->file);
        if ($content === false)
            throw new CoreException('Cannot read API key file: ' . $this->file);

        try
        {
            $data = json_decode($content, true, 4, JSON_THROW_ON_ERROR);
        }
        catch (JsonException $e)
        {
            throw new CoreException('API key file is not valid JSON: ' . $this->file, 0, $e);
        }

        if (!is_array($data))
            throw new CoreException('Invalid API key file: ' . $this->file);

        $entries = [];
        foreach ($data as $name => $entry)
        {
            if (!is_array($entry) || !is_string($entry['hash'] ?? null) || !is_array($entry['scopes'] ?? null))
                throw new CoreException('Invalid entry "' . $name . '" in API key file: ' . $this->file);

            $scopes = [];
            foreach ($entry['scopes'] as $value)
            {
                $scope = is_string($value) ? Scope::tryFrom($value) : null;
                if ($scope !== null)
                    $scopes[] = $scope;
            }

            $entries[(string) $name] = ['hash' => $entry['hash'], 'scopes' => $scopes];
        }

        return $entries;
    }

    /**
     * @param array<string, array{hash: string, scopes: list<Scope>}> $entries
     * @throws CoreException
     */
    private function save(array $entries): void
    {
        $directory = dirname($this->file);
        if (!is_dir($directory) && !mkdir($directory, 0700, true) && !is_dir($directory))
            throw new CoreException('Cannot create directory: ' . $directory);

        $data = [];
        foreach ($entries as $name => $entry)
            $data[$name] = ['hash' => $entry['hash'], 'scopes' => array_map(fn(Scope $scope) => $scope->value, $entry['scopes'])];

        try
        {
            $json = json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        }
        catch (JsonException $e)
        {
            throw new CoreException('Cannot encode API keys.', 0, $e);
        }

        $temporary = $this->file . '.tmp';
        if (file_put_contents($temporary, $json . "\n", LOCK_EX) === false)
            throw new CoreException('Cannot write API key file: ' . $temporary);

        chmod($temporary, 0600);

        if (!rename($temporary, $this->file))
            throw new CoreException('Cannot replace API key file: ' . $this->file);
    }
}
