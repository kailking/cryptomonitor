<?php

namespace Tests\Unit;

use App\Services\MarketVolumeFreshness;
use Tests\TestCase;

class MarketVolumeFreshnessTest extends TestCase
{
    private $nowMs = 1786700000000;

    protected function setUp(): void
    {
        parent::setUp();
        config()->set('market_volume.max_age_seconds', 1800);
    }

    public function test_extreme_requires_volume_and_a_fresh_update_time(): void
    {
        $service = new MarketVolumeFreshness();
        $fresh = $service->extreme([
            'v' => '987654.321',
            'vu' => $this->nowMs - 1000,
        ], $this->nowMs);

        $this->assertTrue($fresh['volume_available']);
        $this->assertSame('987654.321', $fresh['volume_24h_usdt']);
        $this->assertTrue($service->passesExtreme($fresh, 900000));

        $justInsideBoundary = $service->extreme([
            'v' => '987654.321',
            'vu' => $this->nowMs - 1799999,
        ], $this->nowMs);
        $this->assertTrue($justInsideBoundary['volume_available']);

        $stale = $service->extreme([
            'v' => '987654.321',
            'vu' => $this->nowMs - 1800000,
        ], $this->nowMs);
        $this->assertFalse($stale['volume_available']);
        $this->assertNull($stale['volume_24h_usdt']);
        $this->assertFalse($service->passesExtreme($stale, 1));

        $missing = $service->extreme(['vu' => $this->nowMs], $this->nowMs);
        $this->assertFalse($service->passesExtreme($missing, 1));
    }

    public function test_only_positive_numeric_request_values_enable_filtering(): void
    {
        $service = new MarketVolumeFreshness();

        $this->assertNull($service->threshold(null));
        $this->assertNull($service->threshold(''));
        $this->assertNull($service->threshold('0'));
        $this->assertNull($service->threshold('-1'));
        $this->assertNull($service->threshold('abc'));
        $this->assertNull($service->threshold('+1'));
        $this->assertNull($service->threshold('1e6'));
        $this->assertNull($service->threshold(' 1'));
        $this->assertSame('1000000.5', $service->threshold('1000000.5'));
        $this->assertSame('1.5', $service->threshold('0001.5000'));
    }

    public function test_threshold_comparison_is_exact_beyond_two_to_the_fifty_third(): void
    {
        $service = new MarketVolumeFreshness();
        $result = $service->extreme([
            'v' => '9007199254740992',
            'vu' => $this->nowMs,
        ], $this->nowMs);

        $this->assertTrue($result['volume_available']);
        $this->assertTrue($service->passesExtreme($result, $service->threshold('9007199254740992')));
        $this->assertFalse($service->passesExtreme($result, $service->threshold('9007199254740993')));
    }
}
