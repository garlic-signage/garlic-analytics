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
use App\Framework\Http\RequestBodyMiddleware;
use Psr\Container\ContainerInterface;
use Slim\Psr7\Factory\StreamFactory;

$dependencies = [];

// MAX_BODY_BYTES (env) limits the size of a request body, packed and unpacked
$dependencies[RequestBodyMiddleware::class] = DI\factory(function (ContainerInterface $container)
{
    /** @var Config $config */
    $config = $container->get(Config::class);
    $limit  = (int) $config->getEnv('MAX_BODY_BYTES', (string) RequestBodyMiddleware::DEFAULT_MAX_BODY_BYTES);

    return new RequestBodyMiddleware(new StreamFactory(), $limit > 0 ? $limit : RequestBodyMiddleware::DEFAULT_MAX_BODY_BYTES);
});

return $dependencies;
