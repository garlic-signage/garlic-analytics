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
use App\Framework\Validation\FieldValidator;
use App\Modules\PlayLog\PlayLogController;
use App\Modules\PlayLog\PlayLogRepository;
use App\Modules\PlayLog\PlayLogService;
use App\Modules\PlayLog\PlayLogValidator;
use Psr\Container\ContainerInterface;

$dependencies = [];

$dependencies[PlayLogValidator::class] = DI\factory(function (ContainerInterface $container)
{
    /** @var Config $config */
    $config = $container->get(Config::class);

    return new PlayLogValidator(
        new FieldValidator(),
        (int) $config->getConfigValue('max_events', 'playlog'),
        (int) $config->getConfigValue('max_age_days', 'playlog'),
        (int) $config->getConfigValue('max_future_seconds', 'playlog')
    );
});

$dependencies[PlayLogRepository::class] = DI\factory(function (ContainerInterface $container)
{
    /** @var ClickHouseClientInterface $client */
    $client = $container->get(ClickHouseClientInterface::class);

    return new PlayLogRepository($client);
});

$dependencies[PlayLogService::class] = DI\factory(function (ContainerInterface $container)
{
    /** @var PlayLogValidator $validator */
    $validator = $container->get(PlayLogValidator::class);
    /** @var PlayLogRepository $repository */
    $repository = $container->get(PlayLogRepository::class);

    return new PlayLogService($validator, $repository);
});

$dependencies[PlayLogController::class] = DI\factory(function (ContainerInterface $container)
{
    /** @var PlayLogService $service */
    $service = $container->get(PlayLogService::class);

    return new PlayLogController($service);
});

return $dependencies;
