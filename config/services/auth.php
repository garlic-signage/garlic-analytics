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
use App\Framework\Core\Crypt;
use App\Modules\Auth\ApiKeyMiddleware;
use App\Modules\Auth\ApiKeyStoreInterface;
use App\Modules\Auth\Commands\CreateApiKeyCommand;
use App\Modules\Auth\Commands\ListApiKeysCommand;
use App\Modules\Auth\Commands\RevokeApiKeyCommand;
use App\Modules\Auth\JsonFileApiKeyStore;
use Psr\Container\ContainerInterface;

$dependencies = [];

$dependencies[ApiKeyStoreInterface::class] = DI\factory(function (ContainerInterface $container)
{
    /** @var Config $config */
    $config = $container->get(Config::class);

    return new JsonFileApiKeyStore($config->getPaths('keysDir') . '/api_keys.json');
});

$dependencies[ApiKeyMiddleware::class] = DI\factory(function (ContainerInterface $container)
{
    /** @var ApiKeyStoreInterface $store */
    $store = $container->get(ApiKeyStoreInterface::class);
    /** @var Crypt $crypt */
    $crypt = $container->get(Crypt::class);

    return new ApiKeyMiddleware($store, $crypt);
});

$dependencies[CreateApiKeyCommand::class] = DI\factory(function (ContainerInterface $container)
{
    /** @var ApiKeyStoreInterface $store */
    $store = $container->get(ApiKeyStoreInterface::class);
    /** @var Crypt $crypt */
    $crypt = $container->get(Crypt::class);

    return new CreateApiKeyCommand($store, $crypt);
});

$dependencies[ListApiKeysCommand::class] = DI\factory(function (ContainerInterface $container)
{
    /** @var ApiKeyStoreInterface $store */
    $store = $container->get(ApiKeyStoreInterface::class);

    return new ListApiKeysCommand($store);
});

$dependencies[RevokeApiKeyCommand::class] = DI\factory(function (ContainerInterface $container)
{
    /** @var ApiKeyStoreInterface $store */
    $store = $container->get(ApiKeyStoreInterface::class);

    return new RevokeApiKeyCommand($store);
});

return $dependencies;
