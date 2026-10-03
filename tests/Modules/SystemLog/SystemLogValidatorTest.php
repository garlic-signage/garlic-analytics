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

namespace Tests\Modules\SystemLog;

use App\Framework\Exceptions\ValidationException;
use App\Framework\Validation\BatchValidator;
use App\Framework\Validation\FieldValidator;
use App\Modules\SystemLog\SystemLogValidator;
use DateTimeImmutable;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

class SystemLogValidatorTest extends TestCase
{
    private SystemLogValidator $validator;
    private DateTimeImmutable $now;

    protected function setUp(): void
    {
        $this->validator = new SystemLogValidator(new BatchValidator(new FieldValidator(), 3, 180, 86400));
        $this->now       = new DateTimeImmutable('2026-10-03T12:00:00Z');
    }

    /** @return array<string,mixed> */
    private function report(): array
    {
        return [
            'player_id'    => 'player-1',
            'reported_at'  => '2026-10-03T13:00:00+02:00',
            'system_start' => '2026-10-03T03:00:00+02:00',
            'time_zone'    => 'Europe/Berlin',
            'disk_total'   => 12245270528,
            'disk_free'    => 11792592896,
            'cpu_usage'    => 31,
            'memory_total' => 1048576,
            'memory_used'  => 969224,
            'hdmi_output'  => '1920x1080p-60',
        ];
    }

    /**
     * @param array<string,mixed> $report
     * @return array<string,string>
     */
    private function errorsOf(array $report): array
    {
        try
        {
            $this->validator->validate(['events' => [$report]], $this->now);
        }
        catch (ValidationException $e)
        {
            return $e->getErrors();
        }
        static::fail('ValidationException expected');
    }

    #[Group('units')]
    public function testValidReportBecomesDto(): void
    {
        $events = $this->validator->validate(['events' => [$this->report()]], $this->now);

        static::assertCount(1, $events);
        static::assertSame('player-1', $events[0]->playerId);
        static::assertSame('2026-10-03 11:00:00', $events[0]->reportedAt->format('Y-m-d H:i:s'));
        static::assertSame('2026-10-03 01:00:00', $events[0]->systemStart->format('Y-m-d H:i:s'));
        static::assertSame(11792592896, $events[0]->diskFree);
        static::assertSame(31, $events[0]->cpuUsage);
        static::assertSame(969224, $events[0]->memoryUsed);
        static::assertSame('1920x1080p-60', $events[0]->hdmiOutput);
    }

    #[Group('units')]
    public function testOptionalValuesMayBeMissingOrNull(): void
    {
        $report = $this->report();
        unset($report['cpu_usage'], $report['memory_total'], $report['hdmi_output']);
        $report['memory_used'] = null;

        $event = $this->validator->validate(['events' => [$report]], $this->now)[0];

        static::assertNull($event->cpuUsage);
        static::assertNull($event->memoryTotal);
        static::assertNull($event->memoryUsed);
        static::assertSame('', $event->hdmiOutput);
    }

    #[Group('units')]
    public function testRequiredValuesAreChecked(): void
    {
        $report = $this->report();
        unset($report['disk_total'], $report['time_zone'], $report['reported_at']);
        $report['disk_free'] = '5';

        static::assertSame([
            'events.0.time_zone'   => 'is required',
            'events.0.disk_total'  => 'is required',
            'events.0.disk_free'   => 'must be an integer',
            'events.0.reported_at' => 'is required',
        ], $this->errorsOf($report));
    }

    #[Group('units')]
    public function testNegativeValuesAndCpuAbove100AreRejected(): void
    {
        $report                = $this->report();
        $report['disk_free']   = -1;
        $report['cpu_usage']   = 101;
        $report['memory_used'] = -5;

        static::assertSame([
            'events.0.disk_free'   => 'must not be less than 0',
            'events.0.memory_used' => 'must not be less than 0',
            'events.0.cpu_usage'   => 'must not be greater than 100',
        ], $this->errorsOf($report));
    }

    #[Group('units')]
    public function testValuesAreNotComparedWithEachOther(): void
    {
        $report                 = $this->report();
        $report['disk_free']    = 20000000000; // more than disk_total
        $report['memory_used']  = 2000000;     // more than memory_total
        $report['system_start'] = '2026-10-03T14:00:00+02:00'; // after reported_at

        static::assertCount(1, $this->validator->validate(['events' => [$report]], $this->now));
    }

    #[Group('units')]
    public function testReportedAtIsCheckedButSystemStartMayBeOld(): void
    {
        $report                 = $this->report();
        $report['system_start'] = '2024-01-01T00:00:00Z'; // long uptime
        static::assertCount(1, $this->validator->validate(['events' => [$report]], $this->now));

        $report['reported_at'] = '2026-01-01T00:00:00Z';
        static::assertSame(['events.0.reported_at' => 'must not be older than 180 days'], $this->errorsOf($report));

        $report['reported_at'] = '2026-10-05T00:00:00Z';
        static::assertSame(['events.0.reported_at' => 'must not be in the future'], $this->errorsOf($report));
    }

    #[Group('units')]
    public function testOffsetWithoutColonIsRejected(): void
    {
        $report                = $this->report();
        $report['reported_at'] = '2026-10-03T13:00:00+0200';

        static::assertSame(
            ['events.0.reported_at' => 'must be ISO 8601 with offset, e.g. 2026-10-03T15:30:27+02:00'],
            $this->errorsOf($report)
        );
    }

    #[Group('units')]
    public function testInvalidHdmiOutputIsRejected(): void
    {
        $report                = $this->report();
        $report['hdmi_output'] = 5;

        static::assertSame(['events.0.hdmi_output' => 'must be a string of up to 64 characters'], $this->errorsOf($report));
    }
}
