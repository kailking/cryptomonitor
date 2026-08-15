<?php

namespace Tests\Unit\MarketVolume;

use Illuminate\Console\Scheduling\Schedule;
use Tests\TestCase;

class MarketVolumeScheduleTest extends TestCase
{
    public function testMarketVolumeScheduleIsDisabledByConfiguration()
    {
        config(['market_volume.schedule_enabled' => false]);

        $schedule = $this->buildSchedule();

        $this->assertCount(0, $schedule->events());
    }

    public function testMarketVolumeScheduleRunsStaggeredShellScriptWithOverlapProtection()
    {
        config(['market_volume.schedule_enabled' => true]);

        $schedule = $this->buildSchedule();
        $this->assertCount(1, $schedule->events());

        $event = $schedule->events()[0];
        $expectedScript = base_path('scripts/update_market_volume.sh');

        $this->assertFileExists($expectedScript);
        $this->assertTrue(is_executable($expectedScript));
        $this->assertSame('/usr/bin/env bash '.escapeshellarg($expectedScript), $event->command);
        $this->assertSame('3,18,33,48 * * * *', $event->expression);
        $this->assertTrue($event->withoutOverlapping);
        $this->assertSame(30, $event->expiresAt);
        $this->assertSame(storage_path('logs/market-volume.log'), $event->output);
        $this->assertTrue($event->shouldAppendOutput);
    }

    private function buildSchedule()
    {
        $schedule = new Schedule();
        $kernel = $this->app->make(\Illuminate\Contracts\Console\Kernel::class);
        $method = new \ReflectionMethod($kernel, 'schedule');
        $method->setAccessible(true);
        $method->invoke($kernel, $schedule);

        return $schedule;
    }
}
