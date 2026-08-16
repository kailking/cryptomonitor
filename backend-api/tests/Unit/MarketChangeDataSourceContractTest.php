<?php

namespace Tests\Unit;

use App\Services\MarketChangeDataSource;
use App\Services\MarketChangePaginator;
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
        $this->assertSame(300, $row['window_seconds']);
        $this->assertSame('5分钟', $row['window_text']);
        $this->assertSame(1, $row['direction']);
        $this->assertSame('1.2346', $row['change']);
        $this->assertSame('0.000000000000000001', $row['price_begin']);
        $this->assertSame('12.3456789012346', $row['price_end']);
        $this->assertIsString($row['created_at']);
        $this->assertIsString($row['updated_at']);
        $this->assertFalse($row['volume_available']);
        $this->assertNull($row['volume_24h_usdt']);
        $this->assertNull($row['volume_updated_at_ms']);
    }

    public function test_30_second_row_keeps_the_same_id_and_legacy_period(): void
    {
        $row = (new MarketChangeResponseFormatter())->format([
            'id' => '1000000001',
            'match_id' => '88',
            'symbol' => 'BTCUSDT',
            'platform' => '2',
            'period' => '5',
            'window_seconds' => '30',
            'direction' => '1',
            'change' => 1.2,
            'price_begin' => '0.000000000250000000',
            'price_end' => '0.000000000260000000',
            'created_at' => '2026-08-13 08:00:00',
            'updated_at' => '2026-08-13 08:00:01',
            'currency_name' => 'BTC',
            'quote_name' => 'USDT',
        ]);

        $this->assertSame(1000000001, $row['id']);
        $this->assertSame(5, $row['period']);
        $this->assertSame(30, $row['window_seconds']);
        $this->assertSame('30秒', $row['window_text']);
        $this->assertSame('0.00000000025', $row['price_begin']);
    }

    public function test_redis_prices_remove_float_noise_without_losing_tiny_values(): void
    {
        $base = [
            'id' => '1', 'match_id' => '88', 'symbol' => 'BTCUSDT',
            'platform' => '2', 'period' => '5', 'direction' => '1',
            'change' => 1, 'created_at' => '2026-08-13 08:00:00',
            'updated_at' => '2026-08-13 08:00:01',
            'currency_name' => 'BTC', 'quote_name' => 'USDT',
        ];

        $cases = [
            ['0.868810000000000001', '0.041749999999999995', '0.86881', '0.04175'],
            ['0.007813500000000001', '0.028005000000000002', '0.0078135', '0.028005'],
            ['0.000000000250000000', '0.000000000000000001', '0.00000000025', '0.000000000000000001'],
            ['0.000000000249999999', '0.123456789012345000', '0.000000000249999999', '0.123456789012345'],
            ['0.999999999999999999', '999999999999999999.000000000000000000', '1', '1000000000000000000'],
            ['100.000000000000000000', '0.000000000000000000', '100', '0'],
        ];

        $formatter = new MarketChangeResponseFormatter();
        foreach ($cases as $case) {
            $row = $formatter->format(array_merge($base, [
                'price_begin' => $case[0],
                'price_end' => $case[1],
            ]));
            $this->assertSame($case[2], $row['price_begin']);
            $this->assertSame($case[3], $row['price_end']);
        }
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

    /**
     * @dataProvider nonRedisSources
     */
    public function test_30_second_window_never_uses_mysql_or_shadow($configuredSource): void
    {
        config()->set('market_change.source', $configuredSource);
        $source = new MarketChangeDataSource(
            $this->createMock(MarketChangeRedisGenerationService::class),
            new MarketChangeResponseFormatter(),
            new MarketVolumeFreshness()
        );
        $request = Request::create('/api/market/change/list', 'GET', [
            'direction' => 1,
            'window_seconds' => 30,
        ]);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('requires MARKET_CHANGE_SOURCE=redis');
        $source->list($request, 123);
    }

    public function nonRedisSources(): array
    {
        return [
            'mysql' => ['mysql'],
            'shadow' => ['shadow'],
        ];
    }

    public function test_invalid_window_is_rejected_before_selecting_a_data_source(): void
    {
        config()->set('market_change.source', 'redis');
        $source = new MarketChangeDataSource(
            $this->createMock(MarketChangeRedisGenerationService::class),
            new MarketChangeResponseFormatter(),
            new MarketVolumeFreshness()
        );
        $request = Request::create('/api/market/change/list', 'GET', [
            'direction' => 1,
            'window_seconds' => 10,
        ]);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('window_seconds must be 30 or 300');
        $source->list($request, 123);
    }

    /**
     * @dataProvider nonScalarWindows
     */
    public function test_non_scalar_window_is_rejected_before_casting($window): void
    {
        config()->set('market_change.source', 'redis');
        $source = new MarketChangeDataSource(
            $this->createMock(MarketChangeRedisGenerationService::class),
            new MarketChangeResponseFormatter(),
            new MarketVolumeFreshness()
        );
        $request = Request::create('/api/market/change/list', 'GET', [
            'direction' => 1,
            'window_seconds' => $window,
        ]);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('window_seconds must be 30 or 300');
        $source->list($request, 123);
    }

    public function nonScalarWindows(): array
    {
        return [
            'array query' => [['30']],
            'nested array query' => [[['30']]],
            'object query' => [(object) ['value' => '30']],
            'null query' => [null],
        ];
    }

    public function test_missing_window_defaults_to_300_and_explicit_30_is_preserved(): void
    {
        $reflection = new ReflectionClass(MarketChangeDataSource::class);
        $source = $reflection->newInstanceWithoutConstructor();
        $windowSeconds = $reflection->getMethod('windowSeconds');
        $windowSeconds->setAccessible(true);

        $this->assertSame(300, $windowSeconds->invoke(
            $source,
            Request::create('/api/market/change/list', 'GET')
        ));
        $this->assertSame(30, $windowSeconds->invoke(
            $source,
            Request::create('/api/market/change/list', 'GET', ['window_seconds' => 30])
        ));
    }

    /**
     * @dataProvider supportedWindows
     */
    public function test_empty_serialized_paginator_always_exposes_its_window(
        $windowSeconds,
        $windowText
    ): void
    {
        $paginator = new MarketChangePaginator(
            collect([]),
            0,
            50,
            1,
            $windowSeconds,
            ['path' => 'http://localhost/api/market/change/list', 'pageName' => 'page']
        );
        $serialized = json_decode($paginator->toJson(), true);

        $this->assertSame($windowSeconds, $serialized['window_seconds']);
        $this->assertSame($windowText, $serialized['window_text']);
        $this->assertSame([], $serialized['data']);
        $this->assertSame(0, $serialized['total']);
        $this->assertSame(50, $serialized['per_page']);
        $this->assertSame(1, $serialized['current_page']);
    }

    public function supportedWindows(): array
    {
        return [
            '30 seconds' => [30, '30秒'],
            '5 minutes' => [300, '5分钟'],
        ];
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
