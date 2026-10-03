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

use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Formatter\OutputFormatter;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\ConsoleOutputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Throwable;

/**
 * Runs all schema files in migrations/ in file name order.
 * Every statement is idempotent, so the command can run as often as needed.
 *
 *   bin/console db:migrate
 */
#[AsCommand(name: 'db:migrate', description: 'Applies all schema files in migrations/ to ClickHouse')]
class MigrateCommand extends Command
{
    public function __construct(private readonly SchemaMigrator $migrator)
    {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        try
        {
            foreach ($this->migrator->migrate() as $file)
                $output->writeln('executed: ' . OutputFormatter::escape($file));
        }
        catch (Throwable $e)
        {
            $errorOutput = $output instanceof ConsoleOutputInterface ? $output->getErrorOutput() : $output;
            $errorOutput->writeln('<error>Migration failed: ' . OutputFormatter::escape($e->getMessage()) . '</error>');

            return Command::FAILURE;
        }

        return Command::SUCCESS;
    }
}
