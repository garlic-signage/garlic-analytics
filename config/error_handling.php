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

use App\Framework\Exceptions\ValidationException;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Log\LoggerInterface;
use Slim\App;
use Slim\Exception\HttpException;
use Slim\Exception\HttpUnauthorizedException;

/**
 * Central error handling.
 *
 * PHP has three kinds of failures:
 *
 * 1. Exceptions and Errors (thrown exceptions, TypeError, method call on null)
 *    are caught directly by the Slim error middleware.
 *
 * 2. Warnings and notices (undefined array key, failed fopen)
 *    do not stop the script by default. set_error_handler converts them
 *    into an ErrorException, so they end up in the error middleware too.
 *    Not converted: errors suppressed with @ and deprecations.
 *    Deprecations are written to the PHP log.
 *
 * 3. Fatal errors (memory limit, max execution time)
 *    cannot be caught here. The script dies and the web server answers.
 *
 * The error handler turns everything from 1 and 2 into a JSON response:
 * HttpException keeps its status code, ValidationException becomes 422,
 * anything else becomes 500. Only 5xx responses are logged.
 */
return function (App $app, LoggerInterface $logger, bool $debug): void
{
    /**
     * Converts PHP warnings and notices into an ErrorException,
     * so they are handled like any other exception.
     * Not converted: errors suppressed with @ and deprecations.
     * Deprecations are written to the PHP log.
     */
    set_error_handler(/** @throws ErrorException */ function (int $errNumber, string $errorString, string $errorFile, int $errorLine): bool
    {
        if ((error_reporting() & $errNumber) === 0) 	// ignore errors when suppressed via @
            return false;

        if ($errNumber === E_DEPRECATED || $errNumber === E_USER_DEPRECATED)
            return false;

        throw new ErrorException($errorString, 0, $errNumber, $errorFile, $errorLine);
    });

    /**
     * Wraps the whole request in a try/catch.
     * Everything thrown inside ends up in $myErrorHandler.
     * Fatal errors (memory limit, max execution time) cannot be caught here.
     */
    $errorMiddleware = $app->addErrorMiddleware($debug, true, true, $logger);

    /**
     * Turns an exception into a JSON response.
     * HttpException keeps its status code, ValidationException becomes 422,
     * anything else becomes 500. Only 5xx responses are logged.
     */
    $myErrorHandler = function (ServerRequestInterface $request, Throwable $exception, bool $displayErrorDetails) use ($app, $logger): ResponseInterface
    {
        [$status, $error] = match (true) {
            $exception instanceof HttpException       => [$exception->getCode(), $exception->getMessage()],
            $exception instanceof ValidationException => [422, $exception->getMessage()],
            default                                   => [500, 'Internal Server Error'],
        };

        if ($status >= 500)
        {
            try
            {
                $logger->error($exception->getMessage(), ['exception' => $exception]);
            }
            catch (Throwable $e)
            {
                error_log($exception->getMessage() . ' (logger failed: ' . $e->getMessage() . ')');
            }
        }

        $payload = ['error' => $error];
        if ($exception instanceof ValidationException)
            $payload['errors'] = $exception->getErrors();
        if ($displayErrorDetails && $status >= 500)
            $payload['message'] = $exception->getMessage();

        $response = $app->getResponseFactory()->createResponse($status)->withHeader('Content-Type', 'application/json');
        if ($exception instanceof HttpUnauthorizedException)
            $response = $response->withHeader('WWW-Authenticate', 'Bearer'); // required for 401 by RFC 9110

        $data = json_encode($payload);
        if ($data !== false)
            $response->getBody()->write($data);

        return $response;
    };

    $errorMiddleware->setDefaultErrorHandler($myErrorHandler);
};