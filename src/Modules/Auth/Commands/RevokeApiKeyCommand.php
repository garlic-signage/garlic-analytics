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
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Formatter\OutputFormatter;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Deletes the API key of a client. Requests with this key get a 401 from then on.
 *
 * To rotate a key, create a new one under a different name,
 * switch the client over, then revoke the old one.
 *
 *   bin/console apikey:revoke garlic-hub
 */
#[AsCommand(name: 'apikey:revoke', description: 'Deletes the API key of a client')]
class RevokeApiKeyCommand extends Command
{
    public function __construct(private readonly ApiKeyStoreInterface $store)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addArgument('name', InputArgument::REQUIRED, 'Name of the client');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $name = $input->getArgument('name');
        if (!is_string($name) || !$this->store->remove($name))
        {
            $output->writeln('<error>No API key named "' . OutputFormatter::escape(is_string($name) ? $name : '') . '".</error>');
            return Command::FAILURE;
        }

        $output->writeln('API key of ' . OutputFormatter::escape($name) . ' revoked.');

        return Command::SUCCESS;
    }
}
