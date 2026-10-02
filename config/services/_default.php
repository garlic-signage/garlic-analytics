<?php
/*
 garlic-hub: Digital Signage Management Platform

 Copyright (C) 2026 Nikolaos Sagiadinos <garlic@saghiadinos.de>
 This file is part of the garlic-hub source code

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
use Monolog\Handler\StreamHandler;
use Monolog\Logger;
use Psr\Container\ContainerInterface;
use Slim\App;
use Slim\Factory\AppFactory;

$dependencies = [];
$dependencies['ModuleLogger'] = DI\factory(function (ContainerInterface $container)
{
	$logger = new Logger('modules');
    /** @var Config $config */
	$config = $container->get(Config::class);
	$logger->pushHandler(new StreamHandler($config->getPaths('logDir') . '/module.log', $config->getLogLevel()));

	return $logger;
});
$dependencies['FrameworkLogger'] = DI\factory(function (ContainerInterface $container)
{
	$logger = new Logger('modules');
    /** @var Config $config */
	$config = $container->get(Config::class);
	$logger->pushHandler(new StreamHandler($config->getPaths('logDir') . '/framework.log', $config->getLogLevel()));

	return $logger;
});
$dependencies['AppLogger'] = DI\factory(function (ContainerInterface $container)
{
	$logger = new Logger('app');
    /** @var Config $config */
	$config = $container->get(Config::class);
	$logger->pushHandler(new StreamHandler($config->getPaths('logDir') . '/app.log', $config->getLogLevel()));

	return $logger;
});
$dependencies[App::class] = DI\factory([AppFactory::class, 'createFromContainer']); // Slim App
$dependencies[Crypt::class] = DI\factory(function (){return new Crypt();});


return $dependencies;