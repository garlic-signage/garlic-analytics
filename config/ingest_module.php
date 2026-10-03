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
use App\Framework\Ingest\IngestController;
use App\Framework\Ingest\IngestRepositoryInterface;
use App\Framework\Ingest\IngestService;
use App\Framework\Ingest\IngestValidatorInterface;
use App\Framework\Validation\BatchValidator;
use App\Framework\Validation\FieldValidator;
use Psr\Container\ContainerInterface;

/**
 * The DI definitions that all ingest modules share. A file in config/services/ calls the returned function
 * with the classes of its module and returns the result:
 *
 *   $define = require __DIR__ . '/../ingest_module.php';
 *   return $define('playlog', PlayLogController::class, PlayLogService::class, PlayLogValidator::class, PlayLogRepository::class);
 *
 * $module is the name of the settings file config/settings/config_<module>.ini with max_events, max_age_days,
 * and max_future_seconds. The validator gets a BatchValidator with these limits, the repository the database client.
 *
 * @return Closure(string, class-string<IngestController>, class-string<IngestService<covariant object>>, class-string<IngestValidatorInterface<covariant object>>, class-string<IngestRepositoryInterface<covariant object>>): array<string,mixed>
 */
return function (string $module, string $controller, string $service, string $validator, string $repository): array
{
    $dependencies = [];

    $dependencies[$validator] = DI\factory(function (ContainerInterface $container) use ($module, $validator)
    {
        /** @var Config $config */
        $config = $container->get(Config::class);

        return new $validator(new BatchValidator(
            new FieldValidator(),
            (int) $config->getConfigValue('max_events', $module),
            (int) $config->getConfigValue('max_age_days', $module),
            (int) $config->getConfigValue('max_future_seconds', $module)
        ));
    });

    $dependencies[$repository] = DI\factory(function (ContainerInterface $container) use ($repository)
    {
        /** @var ClickHouseClientInterface $client */
        $client = $container->get(ClickHouseClientInterface::class);

        return new $repository($client);
    });

    $dependencies[$service] = DI\factory(function (ContainerInterface $container) use ($service, $validator, $repository)
    {
        /** @var IngestValidatorInterface<object> $validatorInstance */
        $validatorInstance = $container->get($validator);
        /** @var IngestRepositoryInterface<object> $repositoryInstance */
        $repositoryInstance = $container->get($repository);

        return new $service($validatorInstance, $repositoryInstance);
    });

    $dependencies[$controller] = DI\factory(function (ContainerInterface $container) use ($controller, $service)
    {
        /** @var IngestService<object> $serviceInstance */
        $serviceInstance = $container->get($service);

        return new $controller($serviceInstance);
    });

    return $dependencies;
};
