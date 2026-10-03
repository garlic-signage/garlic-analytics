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

namespace App\Modules\Auth\Commands;

use App\Modules\Auth\ApiKeyStoreInterface;
use App\Modules\Auth\Scope;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Helper\Table;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Lists the clients that have an API key and their scopes.
 * The keys themselves are not stored and cannot be shown.
 *
 *   bin/console apikey:list
 */
#[AsCommand(name: 'apikey:list', description: 'Lists the clients with an API key and their scopes')]
class ListApiKeysCommand extends Command
{
    public function __construct(private readonly ApiKeyStoreInterface $store)
    {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $clients = $this->store->all();
        if ($clients === [])
        {
            $output->writeln('No API keys.');
            return Command::SUCCESS;
        }

        $rows = [];
        foreach ($clients as $client)
            $rows[] = [$client->name, implode(', ', array_map(fn(Scope $scope) => $scope->value, $client->scopes))];

        new Table($output)
            ->setHeaders(['Name', 'Scopes'])
            ->setRows($rows)
            ->render();

        return Command::SUCCESS;
    }
}
