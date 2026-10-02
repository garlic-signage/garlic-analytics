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
use App\Framework\Core\Config\IniConfigLoader;
use DI\ContainerBuilder;
use Psr\Container\ContainerInterface;
use Slim\App;

/* @var App $app */
$systemDir = realpath(__DIR__. '/../');

try
{
    if (!is_string($systemDir) || !file_exists($systemDir))
        throw new RuntimeException('Invalid or non-existent system directory provided: ' . var_export($systemDir, true));

    require $systemDir.'/vendor/autoload.php';
    $dotenv = Dotenv\Dotenv::createImmutable($systemDir);
    /** @var array<string, string> $env */
    $env = $dotenv->load();
}
catch (Throwable $e)
{
    die('Error during initialization: ' . $e->getMessage());
}

$paths = [
    'systemDir' => $systemDir,
    'varDir' => $systemDir . '/var',
    'cacheDir' => $systemDir . '/var/cache',
    'logDir' => $systemDir . '/var/logs',
    'keysDir' => $systemDir . '/var/keys',
    'configDir' => $systemDir . '/config',
    'migrationDir' => $systemDir . '/migrations',
    'commandDir' => $systemDir . '/src/Commands'
];

$containerBuilder = new ContainerBuilder();
// The Config class has to load first
$containerBuilder->addDefinitions([
    Config::class => new Config(
        new IniConfigLoader($paths['configDir'].'/settings'),
        $paths,
        $env
    ),
]);

$containerBuilder->addDefinitions($systemDir . '/config/services/_default.php'); // must be the first file
$directoryIterator = new RecursiveIteratorIterator(
    new RecursiveDirectoryIterator($systemDir . '/config/services', FilesystemIterator::SKIP_DOTS)
);
/** @var SplFileInfo $file */
foreach ($directoryIterator as $file)
{
    if (fnmatch('*.php', $file->getFilename()))
    {
        $containerBuilder->addDefinitions($file->getPathname());
    }
}
try
{
    $container = $containerBuilder->build();
}
catch (Exception $e)
{
    http_response_code(500);
    echo 'Error building the container: ' . $e->getMessage() . PHP_EOL;
    exit(1);
}

$middlewareLoader = require $systemDir.'/config/middleware.php';
/** @var callable(ContainerInterface): App<ContainerInterface> $middlewareLoader */
$app = $middlewareLoader($container);

return $app;
