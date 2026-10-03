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

namespace Tests\Framework\Core\Config;

use App\Framework\Core\Config\Config;
use App\Framework\Core\Config\ConfigLoaderInterface;
use App\Framework\Exceptions\CoreException;
use Monolog\Level;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\MockObject\Exception;
use PHPUnit\Framework\MockObject\Stub;
use PHPUnit\Framework\TestCase;

class ConfigTest extends TestCase
{
    private Config $config;
    private ConfigLoaderInterface&Stub $configLoaderStub;

    /**
     * @throws Exception
     */
    protected function setUp(): void
    {
		parent::setUp();
		$this->configLoaderStub = static::createStub(ConfigLoaderInterface::class);
        $this->config           = new Config($this->configLoaderStub, ['key_path' => 'value_path'], ['key_env' => 'value_env']);
    }

    #[Group('units')]
    public function testGetConfigValueReturnsValue(): void
    {
        $module = 'test_module';
        $key    = 'test_key';
        $value  = 'test_value';

        $this->configLoaderStub
            ->method('load')
            ->willReturn(['heidewitzka' => 'Der Kapitän', 'section' => [$key => $value]]);

        $result = $this->config->getConfigValue($key, $module, 'section');

        static::assertEquals($value, $result);
    }

	#[Group('units')]
	public function testGetEnv(): void
	{
		static::assertEquals('value_env', $this->config->getEnv('key_env'));
		static::assertEmpty($this->config->getEnv('manamana'));
	}

	#[Group('units')]
	public function testGetPaths(): void
	{
		static::assertEquals('value_path', $this->config->getPaths('key_path'));
		static::assertEmpty($this->config->getPaths('manamana'));

	}

    #[Group('units')]
    public function testGetConfigValueReturnsNullForNonExistentKey(): void
    {
        $module = 'test_module';
        $key = 'nonexistent_key';

        $this->configLoaderStub
            ->method('load')
            ->willReturn(['section' => ['existing_key' => 'value']]);

        $result = $this->config->getConfigValue($key, $module, 'section');

        static::assertEmpty($result);
    }

	#[Group('units')]
	public function testIsDebugOnlyWhenAppDebugIsTrue(): void
	{
		static::assertTrue(new Config($this->configLoaderStub, [], ['APP_DEBUG' => 'true'])->isDebug());
		static::assertFalse(new Config($this->configLoaderStub, [], ['APP_DEBUG' => 'false'])->isDebug());
		static::assertFalse(new Config($this->configLoaderStub, [], ['APP_DEBUG' => '1'])->isDebug());
		static::assertFalse(new Config($this->configLoaderStub, [], [])->isDebug());
	}

	#[Group('units')]
	public function testLogLevelIsDebugInDevEnvironment(): void
	{
		$config = new Config($this->configLoaderStub, [], ['APP_ENV' => 'dev']);
		static::assertEquals(Level::Debug, $config->getLogLevel());
	}

	#[Group('units')]
	public function testLogLevelIsInfoInTestEnvironment(): void
	{
		$config = new Config($this->configLoaderStub, [], ['APP_ENV' => 'test']);
		static::assertEquals(Level::Info, $config->getLogLevel());
	}

	#[Group('units')]
	public function testLogLevelIsErrorInProdEnvironment(): void
	{
		$config = new Config($this->configLoaderStub, [], ['APP_ENV' => 'prod']);
		static::assertEquals(Level::Error, $config->getLogLevel());
	}

	#[Group('units')]
	public function testLogLevelIsInfoInUnknownEnvironment(): void
	{
		$config = new Config($this->configLoaderStub, [], ['APP_ENV' => 'unknown']);
		static::assertEquals(Level::Info, $config->getLogLevel());
	}

	#[Group('units')]
	public function testLogLevelIsInfoWhenEnvIsNotSet(): void
	{
		$config = new Config($this->configLoaderStub, [], []);
		static::assertEquals(Level::Info, $config->getLogLevel());
	}

    #[Group('units')]
    public function testGetFullConfigDataByModule(): void
    {
        $module = 'test_module';
        $configData = ['key1' => 'value1', 'key2' => 'value2'];

        $this->configLoaderStub
            ->method('load')
            ->willReturn($configData);

        $result = $this->config->getFullConfigDataByModule($module);

        static::assertEquals($configData, $result);
    }

     #[Group('units')]
    public function testPreloadModulesCachesConfigurations(): void
    {
        $modules = ['module1', 'module2'];
        $configData = [
            'module1' => ['key1' => 'value1'],
            'module2' => ['key2' => 'value2'],
        ];

        $this->configLoaderStub
            ->method('load')
            ->willReturnCallback(function (string $module) use ($configData) {
                return $configData[$module] ?? [];
            });

        // Preload Module
        $this->config->preloadModules($modules);

        // check if configuration loaded correctly
        static::assertEquals($configData['module1'], $this->config->getFullConfigDataByModule('module1'));
        static::assertEquals($configData['module2'], $this->config->getFullConfigDataByModule('module2'));
    }

    #[Group('units')]
    public function testGetConfigForModuleCachesResults(): void
    {
        $module = 'test_module';
        $configData = ['key1' => 'value1'];

        $configLoaderMock = $this->createMock(ConfigLoaderInterface::class);
        $configLoaderMock->expects($this->once())
            ->method('load')
            ->with($module)
            ->willReturn($configData);

        $config = new Config($configLoaderMock);

        static::assertSame($configData, $config->getFullConfigDataByModule('test_module'));
        static::assertSame($configData, $config->getFullConfigDataByModule('test_module'));

    }

    #[Group('units')]
    public function testGetConfigForModuleThrowsExceptionIfLoaderFails(): void
    {
        $module = 'test_module';

        $this->configLoaderStub
            ->method('load')
            ->willThrowException(new CoreException('Error loading module'));

        $this->expectException(CoreException::class);
        $this->expectExceptionMessageIs('Error loading module');

        $this->config->getFullConfigDataByModule($module);
    }
}
