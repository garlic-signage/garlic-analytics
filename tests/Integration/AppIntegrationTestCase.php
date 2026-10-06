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

namespace Tests\Integration;

use App\Framework\Core\Config\Config;
use App\Framework\Core\Config\IniConfigLoader;
use App\Framework\Core\Crypt;
use App\Modules\Auth\JsonFileApiKeyStore;
use App\Modules\Auth\Scope;
use DI\ContainerBuilder;
use Psr\Container\ContainerInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Slim\App;

/**
 * Base of the tests that send a request through the whole application: config/middleware.php with the real
 * services against the test database. Two API keys exist: INGEST_KEY (scope ingest) and READ_KEY (scope read).
 */
abstract class AppIntegrationTestCase extends ClickHouseTestCase
{
    protected const string INGEST_KEY = 'ingest-key';
    protected const string READ_KEY   = 'read-key';

    private string $tempDir = '';
    /** @var App<ContainerInterface> */
    private App $app;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tempDir = sys_get_temp_dir() . '/garlic-analytics-' . bin2hex(random_bytes(6));
        mkdir($this->tempDir . '/keys', 0700, true);
        $store = new JsonFileApiKeyStore($this->tempDir . '/keys/api_keys.json');
        $store->add('ingest', new Crypt()->createSha256Hash(self::INGEST_KEY), [Scope::Ingest]);
        $store->add('reader', new Crypt()->createSha256Hash(self::READ_KEY), [Scope::Read]);

        $dir   = self::projectDir();
        $paths = [
            'systemDir'    => $dir,
            'configDir'    => $dir . '/config',
            'migrationDir' => $dir . '/migrations',
            'keysDir'      => $this->tempDir . '/keys',
            'logDir'       => $this->tempDir,
        ];

        $builder = new ContainerBuilder();
        $builder->addDefinitions([Config::class => new Config(new IniConfigLoader($dir . '/config/settings'), $paths, self::connectionSettings())]);
        foreach (['_default', 'database', 'playlog', 'eventlog', 'systemlog', 'connectlog', 'auth', 'http'] as $file)
            $builder->addDefinitions($dir . '/config/services/' . $file . '.php');

        /** @var callable(ContainerInterface): App<ContainerInterface> $middleware */
        $middleware = require $dir . '/config/middleware.php';
        $this->app  = $middleware($builder->build());
    }

    protected function tearDown(): void
    {
        restore_error_handler(); // config/error_handling.php installs one

        foreach ([$this->tempDir . '/keys/*', $this->tempDir . '/*'] as $pattern)
        {
            $files = glob($pattern);
            static::assertIsArray($files);
            foreach ($files as $file)
            {
                if (is_file($file))
                    unlink($file);
            }
        }
        rmdir($this->tempDir . '/keys');
        rmdir($this->tempDir);

        parent::tearDown();
    }

    protected function handle(ServerRequestInterface $request): ResponseInterface
    {
        return $this->app->handle($request);
    }
}
