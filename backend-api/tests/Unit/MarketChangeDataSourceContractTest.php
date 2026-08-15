<?php

namespace Tests\Unit;

use App\Services\MarketChangeDataSource;
use App\Services\MarketChangeResponseFormatter;
use App\Services\MarketChangeRedisGenerationService;
use App\Services\MarketChangeSymbolNormalizer;
use App\Services\MarketVolumeFreshness;
use Illuminate\Http\Request;
use ReflectionClass;
use Tests\TestCase;

class MarketChangeDataSourceContractTest extends TestCase
{
    public function test_redis_rows_keep_the_legacy_json_contract(): void
    {
        $row = (new MarketChangeResponseFormatter())->format([
            'id' => '1000000001',
            'match_id' => '88',
            'symbol' => 'BTCUSDT',
            'platform' => '2',
            'period' => '5',
            'direction' => '1',
            'change' => 1.234567,
            'price_begin' => '0.000000000000000001',
            'price_end' => '12.345678901234567890',
            'created_at' => '2026-08-13 08:00:00',
            'updated_at' => '2026-08-13 08:00:01',
            'currency_name' => 'BTC',
            'quote_name' => 'USDT',
        ]);

        $this->assertSame(1000000001, $row['id']);
        $this->assertSame(88, $row['match_id']);
        $this->assertSame('BTC/USDT', $row['symbol']);
        $this->assertSame(2, $row['platform']);
        $this->assertSame(5, $row['period']);
        $this->assertSame(1, $row['direction']);
        $this->assertSame('1.2346', $row['change']);
        $this->assertSame('0.000000000000000001', $row['price_begin']);
        $this->assertSame('12.345678901234567890', $row['price_end']);
        $this->assertIsString($row['created_at']);
        $this->assertIsString($row['updated_at']);
        $this->assertFalse($row['volume_available']);
        $this->assertNull($row['volume_24h_usdt']);
        $this->assertNull($row['volume_updated_at_ms']);
    }

    public function test_redis_row_exposes_only_fresh_volume(): void
    {
        config()->set('market_volume.max_age_seconds', 1800);
        $nowMs = (int) floor(microtime(true) * 1000);
        $row = (new MarketChangeResponseFormatter())->format([
            'id' => '1', 'match_id' => '88', 'symbol' => 'BTCUSDT',
            'platform' => '2', 'period' => '5', 'direction' => '1',
            'change' => 1, 'price_begin' => '1.000000000000000000',
            'price_end' => '2.000000000000000000',
            'created_at' => '2026-08-13 08:00:00',
            'updated_at' => '2026-08-13 08:00:01',
            'currency_name' => 'BTC', 'quote_name' => 'USDT',
            'v' => '123456.78', 'vu' => $nowMs,
        ]);

        $this->assertTrue($row['volume_available']);
        $this->assertSame('123456.78', $row['volume_24h_usdt']);
        $this->assertSame($nowMs, $row['volume_updated_at_ms']);
    }

    public function test_symbol_normalizer_preserves_unicode_and_uppercases_ascii(): void
    {
        $this->assertSame('老子USDT', MarketChangeSymbolNormalizer::upper('老子usdt'));
        $this->assertSame('BTC-USDT', MarketChangeSymbolNormalizer::upper('btc-usdt'));
        $this->assertSame(
            'e88081e5ad9055534454',
            bin2hex(MarketChangeSymbolNormalizer::upper('老子usdt'))
        );
        $this->assertTrue(MarketChangeSymbolNormalizer::contains('老子USDT', '老子'));
        $this->assertTrue(MarketChangeSymbolNormalizer::contains('老子USDT', 'usdt'));
        $this->assertFalse(MarketChangeSymbolNormalizer::contains('老子USDT', '庄子'));
    }

    public function test_mysql_source_fails_closed_before_querying_when_volume_filter_is_enabled(): void
    {
        $source = new MarketChangeDataSource(
            $this->createMock(MarketChangeRedisGenerationService::class),
            new MarketChangeResponseFormatter(),
            new MarketVolumeFreshness()
        );
        $request = Request::create('/api/market/change/list', 'GET', [
            'direction' => 1,
            'page' => 1,
            'page_size' => 50,
            'min_volume_24h_usdt' => '1000000',
        ]);

        $result = $source->mysqlList($request, 123);

        $this->assertSame(0, $result->total());
        $this->assertSame([], $result->items());
    }

    public function test_one_hundred_percent_shadow_sampling_is_still_limited_once_per_minute(): void
    {
        config()->set('market_change.shadow_sample_percent', 100);
        $reflection = new ReflectionClass(MarketChangeDataSource::class);
        $source = $reflection->newInstanceWithoutConstructor();
        $lastSeen = $reflection->getProperty('shadowLastSeen');
        $lastSeen->setAccessible(true);
        $lastSeen->setValue([]);
        $sample = $reflection->getMethod('shouldSampleShadow');
        $sample->setAccessible(true);
        $request = Request::create('/api/market/change/list', 'GET', [
            'direction' => 1,
            'page' => 1,
            'page_size' => 50,
        ]);
        $userId = random_int(100000000, 999999999);

        $this->assertTrue($sample->invoke($source, $userId, $request));
        $this->assertFalse($sample->invoke($source, $userId, $request));
    }
}
