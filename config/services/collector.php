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

use App\Collector\BatchSplitter;
use App\Collector\Commands\CollectorRunCommand;
use App\Collector\CollectorRunner;
use App\Collector\Device\DeviceSource;
use App\Collector\Device\Smil\SmilAdapter;
use App\Collector\Device\Smil\SmilEventLogParser;
use App\Collector\Device\Smil\SmilPlayLogParser;
use App\Collector\DirectoryScanner;
use App\Collector\FileArchive;
use App\Collector\Ingest\HttpIngestClient;
use App\Collector\Ingest\IngestClientInterface;
use App\Collector\RunLock;
use App\Framework\Core\Config\Config;
use GuzzleHttp\Client;
use Psr\Container\ContainerInterface;

$dependencies = [];

$dependencies[IngestClientInterface::class] = DI\factory(function (ContainerInterface $container)
{
    /** @var Config $config */
    $config = $container->get(Config::class);

    return new HttpIngestClient(
        new Client([
            'connect_timeout' => (float) $config->getConfigValue('connect_timeout_seconds', 'collector'),
            'timeout'         => (float) $config->getConfigValue('timeout_seconds', 'collector'),
        ]),
        $config->getEnv('COLLECTOR_API_URL', 'http://localhost'),
        $config->getEnv('COLLECTOR_API_KEY')
    );
});

$dependencies[CollectorRunner::class] = DI\factory(function (ContainerInterface $container)
{
    /** @var Config $config */
    $config = $container->get(Config::class);
    /** @var IngestClientInterface $ingest */
    $ingest = $container->get(IngestClientInterface::class);

    // One entry per device family. Its files are uploaded to <collectorDir>/<name>/upload.
    $devices = [
        'smil' => new DeviceSource(
            new SmilAdapter(new SmilPlayLogParser(), new SmilEventLogParser()),
            $config->getPaths('collectorDir') . '/smil/upload'
        ),
    ];

    return new CollectorRunner(
        $devices,
        new DirectoryScanner(),
        new BatchSplitter((int) $config->getConfigValue('batch_size', 'collector')),
        $ingest,
        new FileArchive(),
        (int) $config->getConfigValue('min_age_seconds', 'collector')
    );
});

$dependencies[CollectorRunCommand::class] = DI\factory(function (ContainerInterface $container)
{
    /** @var Config $config */
    $config = $container->get(Config::class);
    /** @var CollectorRunner $runner */
    $runner = $container->get(CollectorRunner::class);

    return new CollectorRunCommand($runner, new RunLock($config->getPaths('collectorDir') . '/collector.lock'));
});

return $dependencies;
