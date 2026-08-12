<?php

namespace Tests\Unit;

use App\Services\MarketChangeDataSource;
use App\Services\MarketChangeResponseFormatter;
use App\Services\MarketChangeSymbolNormalizer;
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
