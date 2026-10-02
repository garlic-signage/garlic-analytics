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

use App\Framework\Core\Config\Config;
use App\Framework\Database\ClickHouseClient;
use App\Framework\Database\ClickHouseClientInterface;
use App\Framework\Database\Migration\SchemaMigrator;
use ClickHouseDB\Client;
use Psr\Container\ContainerInterface;

$dependencies = [];

$dependencies[ClickHouseClientInterface::class] = DI\factory(function (ContainerInterface $container)
{
    /** @var Config $config */
    $config = $container->get(Config::class);
    $client = new Client([
        'host'     => $config->getEnv('CLICKHOUSE_HOST', '127.0.0.1'),
        'port'     => $config->getEnv('CLICKHOUSE_PORT', '8123'),
        'username' => $config->getEnv('CLICKHOUSE_USER', 'default'),
        'password' => $config->getEnv('CLICKHOUSE_PASSWORD')
    ]);
    $client->database($config->getEnv('CLICKHOUSE_DATABASE', 'default'));
    return new ClickHouseClient($client);
});

$dependencies[SchemaMigrator::class] = DI\factory(function (ContainerInterface $container)
{
    /** @var Config $config */
    $config = $container->get(Config::class);
    /** @var ClickHouseClientInterface $client */
    $client = $container->get(ClickHouseClientInterface::class);

    return new SchemaMigrator($client, $config->getPaths('migrationDir'));
});

return $dependencies;