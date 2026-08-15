<?php

namespace Tests\Unit\MarketVolume;

use App\Service\MarketVolume\Contracts\MarketVolumeHttpClientInterface;
use App\Service\MarketVolume\Contracts\MarketVolumeProviderInterface;
use App\Service\MarketVolume\Contracts\MarketVolumeStoreInterface;
use App\Service\MarketVolume\MarketVolumeCollector;
use App\Service\MarketVolume\MarketVolumeProviderRegistry;
use App\Service\MarketVolume\Providers\AbstractMarketVolumeProvider;
use App\Service\MarketVolume\RedisMarketVolumeStore;
use PHPUnit\Framework\TestCase;

class MarketVolumeCoreTest extends TestCase
{
    public function testPublishesCompletePlatformHashWithMetadataAndTtl()
    {
        $redis = new CoreFakeRedis();
        $redis->seedString('market_volume:v1:namespace', 'cryptomonitor-market-volume-v1');
        $redis->seedHash('market_volume:v1:platform:2:usdt', [
            'OLDUSDT' => '999',
            '__meta__' => json_encode([
                'platform_id' => 2,
                'symbol_count' => 1,
            ]),
        ], 50);

        $store = new RedisMarketVolumeStore($redis, [
            'redis_db' => 10,
            'max_age_seconds' => 1800,
            'ttl_seconds' => 3600,
            'temp_ttl_seconds' => 600,
        ], function () {
            return 1786701234567;
        });

        $meta = $store->publish(2, 'binance', [
            'ETHUSDT' => '0',
            'BTCUSDT' => '105060432.75',
        ], [
            'fetched_at_ms' => 1786701234000,
            'duration_ms' => 567,
        ]);

        $stableKey = 'market_volume:v1:platform:2:usdt';
        $snapshot = $redis->hashes[$stableKey];
        $this->assertSame('105060432.75', $snapshot['BTCUSDT']);
        $this->assertSame('0', $snapshot['ETHUSDT']);
        $this->assertArrayNotHasKey('OLDUSDT', $snapshot);
        $this->assertSame(3600, $redis->ttls[$stableKey]);
        $this->assertSame(2, $meta['symbol_count']);
        $this->assertSame(1786703034000, $meta['expires_at_ms']);
        $this->assertSame(1786701234000, $meta['fetched_at_ms']);

        $storedMeta = json_decode($snapshot['__meta__'], true);
        $this->assertSame($meta, $storedMeta);
        $this->assertSame([], array_filter(array_keys($redis->hashes), function ($key) {
            return strpos($key, ':tmp:') !== false;
        }));
    }

    public function testRefusesNonEmptyDatabaseWithoutNamespaceMarker()
    {
        $redis = new CoreFakeRedis();
        $redis->seedString('unrelated:key', 'must-not-be-touched');
        $store = new RedisMarketVolumeStore($redis, ['redis_db' => 10]);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('not empty');

        $store->ensureNamespace();
    }

    public function testAcceptsNamespaceCreatedByAnotherStaggeredProcessDuringInitialCheck()
    {
        $redis = new CoreFakeRedis();
        $redis->onDbSize = function (CoreFakeRedis $instance) {
            $instance->seedString('market_volume:v1:namespace', 'cryptomonitor-market-volume-v1');
        };
        $store = new RedisMarketVolumeStore($redis, ['redis_db' => 10]);

        $store->ensureNamespace();

        $this->assertSame(
            'cryptomonitor-market-volume-v1',
            $redis->strings['market_volume:v1:namespace']
        );
    }

    /**
     * @dataProvider reservedRedisDatabaseProvider
     */
    public function testRefusesKnownSharedRedisDatabaseEvenWhenEmpty($database)
    {
        $store = new RedisMarketVolumeStore(new CoreFakeRedis(), ['redis_db' => $database]);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('reserved');

        $store->ensureNamespace();
    }

    public function reservedRedisDatabaseProvider()
    {
        return [
            'depth DB3' => [3],
            'unverified low DB7' => [7],
            'legacy DB11' => [11],
        ];
    }

    public function testAcceptsVerifiedDatabaseTwelve()
    {
        $redis = new CoreFakeRedis();
        $store = new RedisMarketVolumeStore($redis, ['redis_db' => '12']);

        $store->ensureNamespace();

        $this->assertSame(
            'cryptomonitor-market-volume-v1',
            $redis->strings['market_volume:v1:namespace']
        );
    }

    public function testRejectsMalformedRedisDatabaseValue()
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Redis DB');

        new RedisMarketVolumeStore(new CoreFakeRedis(), ['redis_db' => '12abc']);
    }

    public function testRejectsPhysicalTtlThatDoesNotOutliveBusinessFreshness()
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('greater than');

        new RedisMarketVolumeStore(new CoreFakeRedis(), [
            'max_age_seconds' => 1800,
            'ttl_seconds' => 1800,
        ]);
    }

    /**
     * @dataProvider unsafeRedisPrefixProvider
     */
    public function testRejectsUnsafeRedisPrefix($prefix)
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('prefix');

        new RedisMarketVolumeStore(new CoreFakeRedis(), [
            'prefix' => $prefix,
        ]);
    }

    public function unsafeRedisPrefixProvider()
    {
        return [
            'unsafe characters' => ['market volume:*'],
            'trailing separator' => ['market_volume:v1:'],
            'double separator' => ['market_volume::v1'],
            'too long' => [str_repeat('a', 97)],
        ];
    }

    public function testRejectsSnapshotBelowConfiguredRatioWithoutReplacingOrRenewingOldData()
    {
        $redis = new CoreFakeRedis();
        $redis->seedString('market_volume:v1:namespace', 'cryptomonitor-market-volume-v1');
        $stableKey = 'market_volume:v1:platform:2:usdt';
        $redis->seedHash($stableKey, [
            'AUSDT' => '1',
            'BUSDT' => '2',
            'CUSDT' => '3',
            'DUSDT' => '4',
            '__meta__' => json_encode([
                'platform_id' => 2,
                'symbol_count' => 4,
            ]),
        ], 123);
        $oldSnapshot = $redis->hashes[$stableKey];

        $store = new RedisMarketVolumeStore($redis, [
            'redis_db' => 10,
            'min_snapshot_ratio' => 0.5,
        ], function () {
            return 1786701234567;
        });

        try {
            $store->publish(2, 'binance', ['AUSDT' => '1'], [
                'fetched_at_ms' => 1786701234000,
            ]);
            $this->fail('A half-empty exchange response must not replace the previous snapshot.');
        } catch (\UnexpectedValueException $exception) {
            $this->assertStringContainsString('too small', $exception->getMessage());
        }

        $this->assertSame($oldSnapshot, $redis->hashes[$stableKey]);
        $this->assertSame(123, $redis->ttls[$stableKey]);
        $this->assertSame([], array_filter(array_keys($redis->hashes), function ($key) {
            return strpos($key, ':tmp:') !== false;
        }));
    }

    public function testCollectorIsolatesProviderFailureAndPublishesLaterPlatform()
    {
        $http = new CoreFakeHttpClient();
        $registry = new MarketVolumeProviderRegistry([
            1 => new CoreFakeProvider(1, 'broken', null, new \UnexpectedValueException('bad envelope')),
            2 => new CoreFakeProvider(2, 'healthy', ['BTCUSDT' => '100.25']),
        ], $http);
        $store = new CoreRecordingStore();
        $ticks = [1000, 1010, 1020, 1030];
        $collector = new MarketVolumeCollector($registry, $store, 0, function () use (&$ticks) {
            return array_shift($ticks);
        });

        $results = $collector->collect([1, 2]);

        $this->assertFalse($results[0]['success']);
        $this->assertSame('bad envelope', $results[0]['error']);
        $this->assertTrue($results[1]['success']);
        $this->assertCount(1, $store->published);
        $this->assertSame(2, $store->published[0]['platform_id']);
        $this->assertSame(['BTCUSDT' => '100.25'], $store->published[0]['volumes']);
    }

    public function testDryRunDoesNotTouchStore()
    {
        $registry = new MarketVolumeProviderRegistry([
            2 => new CoreFakeProvider(2, 'healthy', ['BTCUSDT' => '1']),
        ], new CoreFakeHttpClient());
        $store = new CoreRecordingStore();
        $collector = new MarketVolumeCollector($registry, $store, 0, function () {
            return 1000;
        });

        $results = $collector->collect([2], true);

        $this->assertTrue($results[0]['success']);
        $this->assertTrue($results[0]['dry_run']);
        $this->assertSame([], $store->published);
        $this->assertFalse($store->namespaceChecked);
    }

    public function testRegistryRejectsMissingActiveProvider()
    {
        $registry = new MarketVolumeProviderRegistry([
            2 => new CoreFakeProvider(2, 'healthy', ['BTCUSDT' => '1']),
        ], new CoreFakeHttpClient());

        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('platform IDs: 3');

        $registry->validate([2, 3]);
    }

    public function testDecimalNormalizationAvoidsFloatTailsAndExpandsDecodedFloatExponent()
    {
        $provider = new CoreNormalizationProvider(new CoreFakeHttpClient());

        $this->assertSame('12345.67', $provider->normalizeForTest(12345.67));
        $this->assertSame('100000000000000000000', $provider->normalizeForTest(1.0e20));
        $this->assertSame('0', $provider->normalizeForTest(0.0));
        $this->assertNull($provider->normalizeForTest('1e20'));
        $this->assertNull($provider->normalizeForTest(-1.0));
    }
}

class CoreFakeHttpClient implements MarketVolumeHttpClientInterface
{
    public function getJson($url, array $options = [])
    {
        return [];
    }
}

class CoreFakeProvider implements MarketVolumeProviderInterface
{
    private $platformId;
    private $name;
    private $snapshot;
    private $exception;

    public function __construct($platformId, $name, $snapshot, \Exception $exception = null)
    {
        $this->platformId = $platformId;
        $this->name = $name;
        $this->snapshot = $snapshot;
        $this->exception = $exception;
    }

    public function platformId()
    {
        return $this->platformId;
    }

    public function providerName()
    {
        return $this->name;
    }

    public function fetch()
    {
        if ($this->exception) {
            throw $this->exception;
        }

        return $this->snapshot;
    }
}

class CoreNormalizationProvider extends AbstractMarketVolumeProvider
{
    public function platformId()
    {
        return 999;
    }

    public function providerName()
    {
        return 'normalization-test';
    }

    public function fetch()
    {
        return [];
    }

    public function normalizeForTest($value)
    {
        return $this->normalizeDecimal($value);
    }
}

class CoreRecordingStore implements MarketVolumeStoreInterface
{
    public $namespaceChecked = false;
    public $published = [];

    public function ensureNamespace()
    {
        $this->namespaceChecked = true;
    }

    public function publish($platformId, $providerName, array $volumes, array $context = [])
    {
        $this->published[] = [
            'platform_id' => $platformId,
            'provider' => $providerName,
            'volumes' => $volumes,
            'context' => $context,
        ];

        return ['published_at_ms' => $context['fetched_at_ms']];
    }
}

class CoreFakeRedis
{
    public $strings = [];
    public $hashes = [];
    public $ttls = [];
    public $onDbSize;

    public function seedString($key, $value)
    {
        $this->strings[$key] = $value;
    }

    public function seedHash($key, array $value, $ttl = null)
    {
        $this->hashes[$key] = $value;
        if ($ttl !== null) {
            $this->ttls[$key] = $ttl;
        }
    }

    public function get($key)
    {
        return array_key_exists($key, $this->strings) ? $this->strings[$key] : false;
    }

    public function set($key, $value, array $options = [])
    {
        if (in_array('nx', $options, true) && array_key_exists($key, $this->strings)) {
            return false;
        }
        $this->strings[$key] = $value;

        return true;
    }

    public function dbSize()
    {
        if (is_callable($this->onDbSize)) {
            $callback = $this->onDbSize;
            $this->onDbSize = null;
            $callback($this);
        }

        return count($this->strings) + count($this->hashes);
    }

    public function multi()
    {
        return $this;
    }

    public function exec()
    {
        return true;
    }

    public function hMSet($key, array $fields)
    {
        $this->hashes[$key] = $fields;

        return true;
    }

    public function hLen($key)
    {
        return isset($this->hashes[$key]) ? count($this->hashes[$key]) : 0;
    }

    public function hGet($key, $field)
    {
        return isset($this->hashes[$key]) && array_key_exists($field, $this->hashes[$key])
            ? $this->hashes[$key][$field]
            : false;
    }

    public function expire($key, $seconds)
    {
        $this->ttls[$key] = $seconds;

        return true;
    }

    public function rename($from, $to)
    {
        $this->hashes[$to] = $this->hashes[$from];
        unset($this->hashes[$from]);
        if (isset($this->ttls[$from])) {
            $this->ttls[$to] = $this->ttls[$from];
            unset($this->ttls[$from]);
        }

        return true;
    }

    public function del($key)
    {
        unset($this->hashes[$key], $this->strings[$key], $this->ttls[$key]);

        return 1;
    }
}
