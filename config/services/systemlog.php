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
use App\Modules\SystemLog\SystemLogController;
use App\Modules\SystemLog\SystemLogRepository;
use App\Modules\SystemLog\SystemLogService;
use App\Modules\SystemLog\SystemLogValidator;
use Psr\Container\ContainerInterface;

$dependencies = [];

$dependencies[SystemLogValidator::class] = DI\factory(function (ContainerInterface $container)
{
    /** @var Config $config */
    $config = $container->get(Config::class);

    return new SystemLogValidator(new BatchValidator(
        new FieldValidator(),
        (int) $config->getConfigValue('max_events', 'systemlog'),
        (int) $config->getConfigValue('max_age_days', 'systemlog'),
        (int) $config->getConfigValue('max_future_seconds', 'systemlog')
    ));
});

$dependencies[SystemLogRepository::class] = DI\factory(function (ContainerInterface $container)
{
    /** @var ClickHouseClientInterface $client */
    $client = $container->get(ClickHouseClientInterface::class);

    return new SystemLogRepository($client);
});

$dependencies[SystemLogService::class] = DI\factory(function (ContainerInterface $container)
{
    /** @var SystemLogValidator $validator */
    $validator = $container->get(SystemLogValidator::class);
    /** @var SystemLogRepository $repository */
    $repository = $container->get(SystemLogRepository::class);

    return new SystemLogService($validator, $repository);
});

$dependencies[SystemLogController::class] = DI\factory(function (ContainerInterface $container)
{
    /** @var SystemLogService $service */
    $service = $container->get(SystemLogService::class);

    return new SystemLogController($service);
});

return $dependencies;
