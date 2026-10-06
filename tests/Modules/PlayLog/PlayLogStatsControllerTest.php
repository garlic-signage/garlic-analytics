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

namespace Tests\Modules\PlayLog;

use App\Framework\Database\ClickHouseClientInterface;
use App\Framework\Exceptions\ValidationException;
use App\Framework\Query\PageQueryValidator;
use App\Framework\Query\QueryController;
use App\Framework\Query\QueryService;
use App\Framework\Validation\FieldValidator;
use App\Modules\PlayLog\PlayLogStatsController;
use App\Modules\PlayLog\PlayLogStatsFilterValidator;
use App\Modules\PlayLog\PlayLogStatsPeriodController;
use App\Modules\PlayLog\PlayLogStatsPeriodFilterValidator;
use App\Modules\PlayLog\PlayLogStatsPeriodRepository;
use App\Modules\PlayLog\PlayLogStatsRepository;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ServerRequestInterface;
use Slim\Psr7\Factory\ResponseFactory;
use Slim\Psr7\Factory\ServerRequestFactory;

class PlayLogStatsControllerTest extends TestCase
{
    private function content(ClickHouseClientInterface $client): QueryController
    {
        $fields = new FieldValidator();

        return new PlayLogStatsController(new QueryService(
            new PageQueryValidator($fields, 100, 1000, new PlayLogStatsFilterValidator($fields), null, false),
            new PlayLogStatsRepository($client)
        ));
    }

    private function period(ClickHouseClientInterface $client): QueryController
    {
        $fields = new FieldValidator();

        return new PlayLogStatsPeriodController(new QueryService(
            new PageQueryValidator($fields, 100, 1000, new PlayLogStatsPeriodFilterValidator($fields)),
            new PlayLogStatsPeriodRepository($client)
        ));
    }

    /**
     * @param array<string,string> $params
     */
    private function request(string $path, array $params = []): ServerRequestInterface
    {
        return new ServerRequestFactory()->createServerRequest('GET', $path)
            ->withQueryParams([...['player_id' => 'p1', 'from' => '2026-10-01T00:00:00Z', 'to' => '2026-10-02T00:00:00Z'], ...$params]);
    }

    #[Group('units')]
    public function testContentStatisticsAnswerWith200AndSortAscendingByDefault(): void
    {
        $client = static::createStub(ClickHouseClientInterface::class);
        $client->method('select')->willReturnCallback(
            static function (string $sql): array
            {
                if (str_contains($sql, 'count()'))
                    return [['total' => 2]];

                static::assertStringContainsString('ORDER BY content_id ASC', $sql);

                return [
                    ['content_id' => 'spot-1', 'total_plays' => '12', 'total_duration_s' => '360'],
                    ['content_id' => 'spot-2', 'total_plays' => '3', 'total_duration_s' => '90'],
                ];
            }
        );

        $response = $this->content($client)->list($this->request('/v1/playlog/stats'), new ResponseFactory()->createResponse());

        static::assertSame(200, $response->getStatusCode());
        static::assertSame(
            '{"total":2,"limit":100,"offset":0,"items":[{"content_id":"spot-1","plays":12,"duration_s":360},{"content_id":"spot-2","plays":3,"duration_s":90}]}',
            (string) $response->getBody()
        );
    }

    #[Group('units')]
    public function testContentStatisticsSortByPlaysDescendingIfAsked(): void
    {
        $client = static::createStub(ClickHouseClientInterface::class);
        $client->method('select')->willReturnCallback(
            static function (string $sql): array
            {
                if (str_contains($sql, 'count()'))
                    return [['total' => 1]];

                static::assertStringContainsString('ORDER BY total_plays DESC, content_id ASC', $sql);

                return [];
            }
        );

        $this->content($client)->list($this->request('/v1/playlog/stats', ['sort' => 'plays', 'order' => 'desc']), new ResponseFactory()->createResponse());
    }

    #[Group('units')]
    public function testPeriodStatisticsAnswerWith200AndDescendingByDefault(): void
    {
        $client = static::createStub(ClickHouseClientInterface::class);
        $client->method('select')->willReturnCallback(
            static function (string $sql): array
            {
                if (str_contains($sql, 'count()'))
                    return [['total' => 31]];

                static::assertStringContainsString('ORDER BY period DESC', $sql);

                return [['period' => '2026-10-31', 'total_plays' => 40, 'total_duration_s' => 800]];
            }
        );

        $response = $this->period($client)->list($this->request('/v1/playlog/stats/period', ['resolution' => 'day', 'limit' => '1']), new ResponseFactory()->createResponse());

        static::assertSame(
            '{"total":31,"limit":1,"offset":0,"items":[{"period":"2026-10-31","plays":40,"duration_s":800}]}',
            (string) $response->getBody()
        );
    }

    #[Group('units')]
    public function testInvalidParametersAskNoDatabase(): void
    {
        foreach ([$this->content(...), $this->period(...)] as $controller)
        {
            $client = $this->createMock(ClickHouseClientInterface::class);
            $client->expects($this->never())->method('select');

            try
            {
                $controller($client)->list($this->request('/v1/playlog/stats', ['content_id' => '', 'resolution' => 'week', 'sort' => 'best']), new ResponseFactory()->createResponse());
                static::fail('ValidationException expected');
            }
            catch (ValidationException $e)
            {
                static::assertNotSame([], $e->getErrors());
            }
        }
    }
}
