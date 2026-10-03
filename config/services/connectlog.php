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
use App\Framework\Database\ClickHouseClientInterface;
use App\Framework\Validation\BatchValidator;
use App\Framework\Validation\FieldValidator;
use App\Modules\ConnectLog\ConnectLogController;
use App\Modules\ConnectLog\ConnectLogRepository;
use App\Modules\ConnectLog\ConnectLogService;
use App\Modules\ConnectLog\ConnectLogValidator;
use Psr\Container\ContainerInterface;

$dependencies = [];

$dependencies[ConnectLogValidator::class] = DI\factory(function (ContainerInterface $container)
{
    /** @var Config $config */
    $config = $container->get(Config::class);

    return new ConnectLogValidator(new BatchValidator(
        new FieldValidator(),
        (int) $config->getConfigValue('max_events', 'connectlog'),
        (int) $config->getConfigValue('max_age_days', 'connectlog'),
        (int) $config->getConfigValue('max_future_seconds', 'connectlog')
    ));
});

$dependencies[ConnectLogRepository::class] = DI\factory(function (ContainerInterface $container)
{
    /** @var ClickHouseClientInterface $client */
    $client = $container->get(ClickHouseClientInterface::class);

    return new ConnectLogRepository($client);
});

$dependencies[ConnectLogService::class] = DI\factory(function (ContainerInterface $container)
{
    /** @var ConnectLogValidator $validator */
    $validator = $container->get(ConnectLogValidator::class);
    /** @var ConnectLogRepository $repository */
    $repository = $container->get(ConnectLogRepository::class);

    return new ConnectLogService($validator, $repository);
});

$dependencies[ConnectLogController::class] = DI\factory(function (ContainerInterface $container)
{
    /** @var ConnectLogService $service */
    $service = $container->get(ConnectLogService::class);

    return new ConnectLogController($service);
});

return $dependencies;
