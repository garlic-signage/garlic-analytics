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
use App\Framework\Query\FilterValidatorInterface;
use App\Framework\Query\PageQueryValidator;
use App\Framework\Query\QueryController;
use App\Framework\Query\QueryRepositoryInterface;
use App\Framework\Query\QueryService;
use App\Framework\Validation\FieldValidator;
use Psr\Container\ContainerInterface;

/**
 * The DI definitions that all modules share for the reading of their raw events. A file in config/services/
 * calls the returned function with the classes of its module and adds the result to its definitions:
 *
 *   $define = require __DIR__ . '/../query_module.php';
 *   return $ingest + $define('playlog', PlayLogQueryController::class, PlayLogQueryRepository::class);
 *
 * $module is the name of the settings file config/settings/config_<module>.ini with default_limit and max_limit.
 * A module with optional filter parameters passes the class of its FilterValidatorInterface as fourth argument.
 * The fifth argument is the key in the settings file with the maximum range of from to to in hours (no limit if null).
 * The sixth argument is false if the default order of the module is ascending.
 * The seventh argument is true for the query of a group of players (the controller is a GroupQueryController): the
 * players come in the body, at most max_players of the settings file.
 *
 * @return Closure(string, class-string<QueryController>, class-string<QueryRepositoryInterface>, class-string<FilterValidatorInterface>|null=, string|null=, bool=, bool=): array<string,mixed>
 */
return function (string $module, string $controller, string $repository, ?string $filterValidator = null, ?string $maxRangeKey = null, bool $defaultDescending = true, bool $group = false): array
{
    $dependencies = [];

    $dependencies[$repository] = DI\factory(function (ContainerInterface $container) use ($repository)
    {
        /** @var ClickHouseClientInterface $client */
        $client = $container->get(ClickHouseClientInterface::class);

        return new $repository($client);
    });

    $dependencies[$controller] = DI\factory(function (ContainerInterface $container) use ($module, $controller, $repository, $filterValidator, $maxRangeKey, $defaultDescending, $group)
    {
        /** @var Config $config */
        $config = $container->get(Config::class);
        /** @var QueryRepositoryInterface $repositoryInstance */
        $repositoryInstance = $container->get($repository);

        /** @var FilterValidatorInterface|null $filters */
        $filters = $filterValidator === null ? null : new $filterValidator(new FieldValidator());

        $validator = new PageQueryValidator(
            new FieldValidator(),
            (int) $config->getConfigValue('default_limit', $module),
            (int) $config->getConfigValue('max_limit', $module),
            $filters,
            $maxRangeKey === null ? null : (int) $config->getConfigValue($maxRangeKey, $module) * 3600,
            $defaultDescending,
            $group ? (int) $config->getConfigValue('max_players', $module) : null
        );

        return new $controller(new QueryService($validator, $repositoryInstance));
    });

    return $dependencies;
};
