<?php

namespace Tests\Unit;

use App\Exceptions\MarketChangeRedisUnavailableException;
use App\Services\MarketChangeRedisGenerationService;
use Tests\TestCase;

class MarketChangeRedisGenerationServiceTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        config()->set('market_change.redis_prefix', 'v2:market_change');
        config()->set('market_change.redis_schema_version', 2);
        config()->set('market_change.redis_max_age_seconds', 5);
    }

    public function test_filters_index_before_hmget_and_normalizes_details(): void
    {
        $nowMs = (int) floor(microtime(true) * 1000);
        $envelope = [
            'schema_version' => 2,
            'generation' => 'g1',
            'generated_at_ms' => $nowMs,
            'api_max_age_seconds' => 5,
            'warmup_complete' => true,
        ];
        $redis = new FakeMarketChangeRedis([
            'v2:market_change:current_generation' => 'g1',
            'v2:market_change:generation:g1:meta' => json_encode($envelope),
            'v2:market_change:generation:g1:index:up' => json_encode(array_merge($envelope, [
                'data' => [
                    ['i' => 10, 'm' => 100, 'p' => 2, 'cn' => 'BTC', 'qn' => 'USDT', 'c' => 5.5],
                    ['i' => 11, 'm' => 101, 'p' => 4, 'cn' => 'ETH', 'qn' => 'USDT', 'c' => 4.5],
                    ['i' => 12, 'm' => 102, 'p' => 2, 'cn' => 'SOL', 'qn' => 'USDT', 'c' => 3.5],
                ],
            ])),
        ], [
            'v2:market_change:generation:g1:data' => [
                '10' => json_encode($this->detail(10, 100, 2, 'BTC', 5.5)),
                '12' => json_encode($this->detail(12, 102, 2, 'SOL', 3.5)),
            ],
        ]);

        $service = new MarketChangeRedisGenerationService($redis);
        $result = $service->readPage(1, [
            'blocked_ids' => [11 => true],
            'temporary_blocked_ids' => [12 => true],
            'excluded_platforms' => [],
            'symbol' => '',
            'change_gt' => 1,
        ], 1, 50);

        $this->assertSame(1, $result['total']);
        $this->assertSame([10], array_column($result['items'], 'id'));
        $this->assertSame([['10']], $redis->hmgetCalls);
        $this->assertSame(5, $result['items'][0]['period']);
    }

    public function test_stale_generation_is_not_returned_as_an_empty_list(): void
    {
        $oldMs = ((int) floor(microtime(true) * 1000)) - 6000;
        $envelope = [
            'schema_version' => 2,
            'generation' => 'old',
            'generated_at_ms' => $oldMs,
            'api_max_age_seconds' => 5,
            'warmup_complete' => true,
            'data' => [],
        ];
        $redis = new FakeMarketChangeRedis([
            'v2:market_change:current_generation' => 'old',
            'v2:market_change:generation:old:meta' => json_encode($envelope),
            'v2:market_change:generation:old:index:up' => json_encode($envelope),
        ], []);

        $this->expectException(MarketChangeRedisUnavailableException::class);
        (new MarketChangeRedisGenerationService($redis))->readPage(1, [], 1, 50);
    }

    public function test_redis_items_do_not_require_a_separate_enabled_id_list(): void
    {
        $nowMs = (int) floor(microtime(true) * 1000);
        $envelope = [
            'schema_version' => 2,
            'generation' => 'empty-enabled',
            'generated_at_ms' => $nowMs,
            'api_max_age_seconds' => 5,
            'warmup_complete' => true,
        ];
        $redis = new FakeMarketChangeRedis([
            'v2:market_change:current_generation' => 'empty-enabled',
            'v2:market_change:generation:empty-enabled:meta' => json_encode($envelope),
            'v2:market_change:generation:empty-enabled:index:up' => json_encode(array_merge($envelope, [
                'data' => [['i' => 30, 'm' => 300, 'p' => 2, 'cn' => 'BTC', 'qn' => 'USDT', 'c' => 2]],
            ])),
        ], [
            'v2:market_change:generation:empty-enabled:data' => [
                '30' => json_encode($this->detail(30, 300, 2, 'BTC', 2)),
            ],
        ]);

        $result = (new MarketChangeRedisGenerationService($redis))->readPage(1, [], 1, 50);

        $this->assertSame(1, $result['total']);
        $this->assertSame([30], array_column($result['items'], 'id'));
        $this->assertSame([['30']], $redis->hmgetCalls);
    }

    public function test_meta_and_index_must_belong_to_the_same_snapshot_timestamp(): void
    {
        $nowMs = (int) floor(microtime(true) * 1000);
        $meta = [
            'schema_version' => 2,
            'generation' => 'mixed',
            'generated_at_ms' => $nowMs,
            'api_max_age_seconds' => 5,
            'warmup_complete' => true,
        ];
        $index = $meta;
        $index['generated_at_ms']--;
        $index['data'] = [];
        $redis = new FakeMarketChangeRedis([
            'v2:market_change:current_generation' => 'mixed',
            'v2:market_change:generation:mixed:meta' => json_encode($meta),
            'v2:market_change:generation:mixed:index:up' => json_encode($index),
        ], []);

        $this->expectException(MarketChangeRedisUnavailableException::class);
        (new MarketChangeRedisGenerationService($redis))->readPage(1, [], 1, 50);
    }

    public function test_generation_detail_must_exist_for_every_paged_id(): void
    {
        $nowMs = (int) floor(microtime(true) * 1000);
        $envelope = [
            'schema_version' => 2,
            'generation' => 'g2',
            'generated_at_ms' => $nowMs,
            'api_max_age_seconds' => 5,
            'warmup_complete' => true,
        ];
        $redis = new FakeMarketChangeRedis([
            'v2:market_change:current_generation' => 'g2',
            'v2:market_change:generation:g2:meta' => json_encode($envelope),
            'v2:market_change:generation:g2:index:down' => json_encode(array_merge($envelope, [
                'data' => [['i' => 20, 'm' => 200, 'p' => 4, 'cn' => 'ETH', 'qn' => 'USDT', 'c' => 6]],
            ])),
        ], ['v2:market_change:generation:g2:data' => ['999' => '{}']]);

        $this->expectException(MarketChangeRedisUnavailableException::class);
        (new MarketChangeRedisGenerationService($redis))->readPage(2, [], 1, 50);
    }

    public function test_unicode_market_names_are_not_corrupted(): void
    {
        $nowMs = (int) floor(microtime(true) * 1000);
        $envelope = [
            'schema_version' => 2,
            'generation' => 'unicode',
            'generated_at_ms' => $nowMs,
            'api_max_age_seconds' => 5,
            'warmup_complete' => true,
        ];
        $detail = $this->detail(40, 422, 1, '老子', 2.5);
        $redis = new FakeMarketChangeRedis([
            'v2:market_change:current_generation' => 'unicode',
            'v2:market_change:generation:unicode:meta' => json_encode($envelope),
            'v2:market_change:generation:unicode:index:up' => json_encode(array_merge($envelope, [
                'data' => [['i' => 40, 'm' => 422, 'p' => 1, 'cn' => '老子', 'qn' => 'usdt', 'c' => 2.5]],
            ])),
        ], [
            'v2:market_change:generation:unicode:data' => [
                '40' => json_encode($detail),
            ],
        ]);

        $result = (new MarketChangeRedisGenerationService($redis))->readPage(1, [], 1, 50);

        $this->assertSame('老子', $result['items'][0]['currency_name']);
        $this->assertSame('老子USDT', $result['items'][0]['symbol']);
        $this->assertSame('0.000000000000000001', $result['items'][0]['price_begin']);
    }

    public function test_low_level_redis_failures_are_wrapped_for_the_api_503_path(): void
    {
        $this->expectException(MarketChangeRedisUnavailableException::class);
        $this->expectExceptionMessage('Redis generation read failed');

        (new MarketChangeRedisGenerationService(new ThrowingMarketChangeRedis()))
            ->readPage(1, [], 1, 50);
    }

    public function test_nonempty_index_never_becomes_a_false_empty_list_when_data_hash_is_missing(): void
    {
        $nowMs = (int) floor(microtime(true) * 1000);
        $envelope = [
            'schema_version' => 2,
            'generation' => 'missing-hash',
            'generated_at_ms' => $nowMs,
            'api_max_age_seconds' => 5,
            'warmup_complete' => true,
        ];
        $redis = new FakeMarketChangeRedis([
            'v2:market_change:current_generation' => 'missing-hash',
            'v2:market_change:generation:missing-hash:meta' => json_encode($envelope),
            'v2:market_change:generation:missing-hash:index:up' => json_encode(array_merge($envelope, [
                'data' => [['i' => 45, 'm' => 450, 'p' => 2, 'cn' => 'BTC', 'qn' => 'USDT', 'c' => 2]],
            ])),
        ], []);

        $this->expectException(MarketChangeRedisUnavailableException::class);
        $this->expectExceptionMessage('detail hash is missing');
        (new MarketChangeRedisGenerationService($redis))->readPage(1, [], 1, 50);
    }

    public function test_hmget_failures_are_wrapped_for_the_api_503_path(): void
    {
        $nowMs = (int) floor(microtime(true) * 1000);
        $envelope = [
            'schema_version' => 2,
            'generation' => 'hmget-failure',
            'generated_at_ms' => $nowMs,
            'api_max_age_seconds' => 5,
            'warmup_complete' => true,
        ];
        $redis = new ThrowingHmgetMarketChangeRedis([
            'v2:market_change:current_generation' => 'hmget-failure',
            'v2:market_change:generation:hmget-failure:meta' => json_encode($envelope),
            'v2:market_change:generation:hmget-failure:index:up' => json_encode(array_merge($envelope, [
                'data' => [['i' => 50, 'm' => 500, 'p' => 2, 'cn' => 'BTC', 'qn' => 'USDT', 'c' => 1]],
            ])),
        ]);

        $this->expectException(MarketChangeRedisUnavailableException::class);
        $this->expectExceptionMessage('Redis generation read failed');
        (new MarketChangeRedisGenerationService($redis))->readPage(1, [], 1, 50);
    }

    public function test_non_string_or_non_fixed_18_place_prices_are_rejected(): void
    {
        $nowMs = (int) floor(microtime(true) * 1000);
        $envelope = [
            'schema_version' => 2,
            'generation' => 'bad-price',
            'generated_at_ms' => $nowMs,
            'api_max_age_seconds' => 5,
            'warmup_complete' => true,
        ];
        $detail = $this->detail(60, 600, 2, 'BTC', 1);
        $detail['pb'] = 0.000000000000000001;
        $redis = new FakeMarketChangeRedis([
            'v2:market_change:current_generation' => 'bad-price',
            'v2:market_change:generation:bad-price:meta' => json_encode($envelope),
            'v2:market_change:generation:bad-price:index:up' => json_encode(array_merge($envelope, [
                'data' => [['i' => 60, 'm' => 600, 'p' => 2, 'cn' => 'BTC', 'qn' => 'USDT', 'c' => 1]],
            ])),
        ], [
            'v2:market_change:generation:bad-price:data' => ['60' => json_encode($detail)],
        ]);

        $this->expectException(MarketChangeRedisUnavailableException::class);
        $this->expectExceptionMessage('fixed 18-place decimal strings');
        (new MarketChangeRedisGenerationService($redis))->readPage(1, [], 1, 50);
    }

    private function detail($id, $matchId, $platform, $currency, $change)
    {
        return [
            'i' => $id,
            'm' => $matchId,
            's' => $currency.'USDT',
            'p' => $platform,
            'pd' => 5,
            'dr' => 1,
            'c' => $change,
            'pb' => '0.000000000000000001',
            'pe' => '105.500000000000000000',
            'cn' => $currency,
            'qn' => 'USDT',
            'ca' => '2026-08-13 00:00:00',
            'ua' => '2026-08-13 00:00:01',
        ];
    }
}

class FakeMarketChangeRedis
{
    private $strings;
    private $hashes;
    public $hmgetCalls = [];

    public function __construct(array $strings, array $hashes)
    {
        $this->strings = $strings;
        $this->hashes = $hashes;
    }

    public function get($key)
    {
        return $this->strings[$key] ?? false;
    }

    public function hMGet($key, array $fields)
    {
        $this->hmgetCalls[] = $fields;
        $result = [];
        foreach ($fields as $field) {
            $result[$field] = $this->hashes[$key][$field] ?? false;
        }
        return $result;
    }

    public function exists($key)
    {
        return array_key_exists($key, $this->hashes) ? 1 : 0;
    }
}

class ThrowingMarketChangeRedis
{
    public function get($key)
    {
        throw new \RuntimeException('socket closed');
    }
}

class ThrowingHmgetMarketChangeRedis
{
    private $strings;

    public function __construct(array $strings)
    {
        $this->strings = $strings;
    }

    public function get($key)
    {
        return $this->strings[$key] ?? false;
    }

    public function hMGet($key, array $fields)
    {
        throw new \RuntimeException('hash connection closed');
    }

    public function exists($key)
    {
        return 1;
    }
}
