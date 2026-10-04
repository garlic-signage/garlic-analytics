<?php
/*
 garlic-hub: Digital Signage Management Platform

 Copyright (C) 2024 Nikolaos Sagiadinos <garlic@saghiadinos.de>
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
use App\Framework\Http\RequestBodyMiddleware;
use Psr\Container\ContainerExceptionInterface;
use Psr\Container\ContainerInterface;
use Psr\Container\NotFoundExceptionInterface;
use Psr\Log\LoggerInterface;
use Slim\App;

/**
 * Builds the application.
 *
 * Returns a function that is called by index.php with the container and returns the configured Slim app.
 *
 * 1. Get app and logger from the container.
 * 2. Load routes.php, which registers the routes on $app.
 * 3. Body parsing middleware: converts a JSON request body into an array,
 *    available via getParsedBody().
 *    Request body middleware: limits the size of the body and unpacks gzip, so it has to be
 *    added after the body parsing (outside of it).
 * 4. Routing middleware: finds the route for the URL.
 *    Throws 404 or 405 if there is none.
 * 5. Load and run the error handling.
 *
 * The order matters. Middleware wraps the controller like layers of an onion and the one added last is the outermost:
 *
 *   Request
 *     -> ErrorMiddleware          (added last, runs first)
 *       -> RoutingMiddleware
 *         -> RequestBodyMiddleware
 *           -> BodyParsingMiddleware
 *             -> (ApiKeyMiddleware, route group)
 *               -> Controller
 *
 * Error handling must be added last. Only as the outermost layer it can catch
 * everything thrown further inside, including the 404 from routing.
 */
return /** @throws ContainerExceptionInterface|NotFoundExceptionInterface */ function (ContainerInterface $container): App
{
    /** @var App<ContainerInterface> $app */
    $app = $container->get(App::class);
    /** @var LoggerInterface $logger */
    $logger = $container->get('AppLogger');

    /** @var callable(App<ContainerInterface>): void $routes */
    $routes = require __DIR__ . '/routes.php';
    $routes($app);

    $app->addBodyParsingMiddleware();
    $app->add(RequestBodyMiddleware::class); // must wrap the body parsing
    $app->addRoutingMiddleware();

    /** @var Config $config */
    $config = $container->get(Config::class);

    /** @var callable(App<ContainerInterface>, LoggerInterface, bool): void $errorHandling */
    $errorHandling = require __DIR__ . '/error_handling.php';
    $errorHandling($app, $logger, $config->isDebug()); // call error middleware as last

    return $app;
};
