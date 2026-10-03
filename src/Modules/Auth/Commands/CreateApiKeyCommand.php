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

use App\Framework\Core\Crypt;
use App\Modules\Auth\ApiKeyStoreInterface;
use App\Modules\Auth\Scope;
use InvalidArgumentException;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Formatter\OutputFormatter;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Creates an API key for a client and prints it once.
 * Only the SHA-256 hash is stored, the key cannot be shown again.
 *
 *   bin/console apikey:create garlic-hub --scope=ingest --scope=read
 */
#[AsCommand(name: 'apikey:create', description: 'Creates an API key for a client and prints it once')]
class CreateApiKeyCommand extends Command
{
    public function __construct(
        private readonly ApiKeyStoreInterface $store,
        private readonly Crypt                $crypt
    )
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addArgument('name', InputArgument::REQUIRED, 'Unique name of the client, e.g. garlic-hub')
             ->addOption('scope', 's', InputOption::VALUE_REQUIRED | InputOption::VALUE_IS_ARRAY, 'Scope to grant (ingest, read), can be repeated');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $name = $input->getArgument('name');
        if (!is_string($name) || preg_match('/^[A-Za-z0-9._-]{1,64}$/', $name) !== 1)
        {
            $output->writeln('<error>The name may only contain letters, digits, ".", "_" and "-" (max. 64 characters).</error>');
            return Command::FAILURE;
        }

        $values = $input->getOption('scope');
        $scopes = [];
        foreach (is_array($values) ? $values : [] as $value)
        {
            $scope = is_string($value) ? Scope::tryFrom($value) : null;
            if ($scope === null)
            {
                $output->writeln('<error>Unknown scope: ' . OutputFormatter::escape(is_string($value) ? $value : '') . '</error>');
                return Command::FAILURE;
            }

            if (!in_array($scope, $scopes, true))
                $scopes[] = $scope;
        }

        if ($scopes === [])
        {
            $output->writeln('<error>At least one --scope is required (ingest, read).</error>');
            return Command::FAILURE;
        }

        $key = $this->crypt->generateRandomString(32);

        try
        {
            $this->store->add($name, $this->crypt->createSha256Hash($key), $scopes);
        }
        catch (InvalidArgumentException $e)
        {
            $output->writeln('<error>' . OutputFormatter::escape($e->getMessage()) . '</error>');
            return Command::FAILURE;
        }

        $output->writeln('API key for ' . $name . ' (' . implode(', ', array_map(fn(Scope $scope) => $scope->value, $scopes)) . '):');
        $output->writeln($key);
        $output->writeln('Store it now, it cannot be shown again.');

        return Command::SUCCESS;
    }
}
