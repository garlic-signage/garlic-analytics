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
use App\Modules\EventLog\EventLogController;
use App\Modules\EventLog\EventLogRepository;
use App\Modules\EventLog\EventLogService;
use App\Modules\EventLog\EventLogValidator;
use Psr\Container\ContainerInterface;

$dependencies = [];

$dependencies[EventLogValidator::class] = DI\factory(function (ContainerInterface $container)
{
    /** @var Config $config */
    $config = $container->get(Config::class);

    return new EventLogValidator(new BatchValidator(
        new FieldValidator(),
        (int) $config->getConfigValue('max_events', 'eventlog'),
        (int) $config->getConfigValue('max_age_days', 'eventlog'),
        (int) $config->getConfigValue('max_future_seconds', 'eventlog')
    ));
});

$dependencies[EventLogRepository::class] = DI\factory(function (ContainerInterface $container)
{
    /** @var ClickHouseClientInterface $client */
    $client = $container->get(ClickHouseClientInterface::class);

    return new EventLogRepository($client);
});

$dependencies[EventLogService::class] = DI\factory(function (ContainerInterface $container)
{
    /** @var EventLogValidator $validator */
    $validator = $container->get(EventLogValidator::class);
    /** @var EventLogRepository $repository */
    $repository = $container->get(EventLogRepository::class);

    return new EventLogService($validator, $repository);
});

$dependencies[EventLogController::class] = DI\factory(function (ContainerInterface $container)
{
    /** @var EventLogService $service */
    $service = $container->get(EventLogService::class);

    return new EventLogController($service);
});

return $dependencies;
