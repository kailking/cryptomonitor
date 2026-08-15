<?php

namespace App\Service\MarketVolume;

use App\Service\MarketVolume\Contracts\MarketVolumeStoreInterface;

class RedisMarketVolumeStore implements MarketVolumeStoreInterface
{
    /** @var object|callable */
    private $redisOrFactory;

    /** @var object|null */
    private $redis;

    /** @var array<string, mixed> */
    private $config;

    /** @var callable */
    private $clock;

    /** @var bool */
    private $namespaceReady = false;

    public function __construct($redisOrFactory, array $config = [], callable $clock = null)
    {
        if (!is_object($redisOrFactory) && !is_callable($redisOrFactory)) {
            throw new \InvalidArgumentException('Redis instance or factory is required.');
        }

        $this->redisOrFactory = $redisOrFactory;
        $this->config = array_merge([
            'redis_db' => 10,
            'prefix' => 'market_volume:v1',
            'namespace_value' => 'cryptomonitor-market-volume-v1',
            'forbidden_redis_dbs' => [0, 1, 2, 3, 4, 5, 6, 9, 11],
            'max_age_seconds' => 1800,
            'ttl_seconds' => 3600,
            'temp_ttl_seconds' => 600,
            'min_snapshot_ratio' => 0.5,
        ], $config);
        $redisDatabase = $this->config['redis_db'];
        if ((!is_int($redisDatabase) && (!is_string($redisDatabase) || !ctype_digit($redisDatabase)))
            || (is_int($redisDatabase) && $redisDatabase < 0)
        ) {
            throw new \InvalidArgumentException('Market-volume Redis DB must be a non-negative integer.');
        }
        $this->config['redis_db'] = (int) $redisDatabase;
        $prefix = (string) $this->config['prefix'];
        if ($prefix === ''
            || trim($prefix) !== $prefix
            || strlen($prefix) > 96
            || !preg_match('/^[A-Za-z0-9][A-Za-z0-9:_-]*$/D', $prefix)
            || strpos($prefix, '::') !== false
            || substr($prefix, -1) === ':'
        ) {
            throw new \InvalidArgumentException('Market-volume Redis prefix is invalid.');
        }
        if ((int) $this->config['max_age_seconds'] <= 0) {
            throw new \InvalidArgumentException('Market-volume max age must be positive.');
        }
        if ((int) $this->config['ttl_seconds'] <= (int) $this->config['max_age_seconds']) {
            throw new \InvalidArgumentException('Market-volume TTL must be greater than the business max age.');
        }
        if ((int) $this->config['temp_ttl_seconds'] <= 0) {
            throw new \InvalidArgumentException('Market-volume temporary TTL must be positive.');
        }
        $minimumRatio = (float) $this->config['min_snapshot_ratio'];
        if ($minimumRatio <= 0 || $minimumRatio > 1) {
            throw new \InvalidArgumentException('Market-volume minimum snapshot ratio must be greater than 0 and at most 1.');
        }
        $this->clock = $clock ?: function () {
            return (int) floor(microtime(true) * 1000);
        };
    }

    public function ensureNamespace()
    {
        if ($this->namespaceReady) {
            return;
        }

        $database = (int) $this->config['redis_db'];
        $forbidden = array_map('intval', (array) $this->config['forbidden_redis_dbs']);
        if ($database < 10 || $database === 11 || in_array($database, $forbidden, true)) {
            throw new \RuntimeException('Redis DB '.$database.' is reserved and cannot store market-volume data.');
        }

        $redis = $this->redis();
        $markerKey = $this->prefix().':namespace';
        $expected = (string) $this->config['namespace_value'];
        $existing = $redis->get($markerKey);

        if ($existing !== false && $existing !== null) {
            if (!hash_equals($expected, (string) $existing)) {
                throw new \RuntimeException('Market-volume Redis namespace marker does not match.');
            }
            $this->namespaceReady = true;

            return;
        }

        if ((int) $redis->dbSize() !== 0) {
            // Another staggered platform process may have established the
            // marker after our first GET but before DBSIZE. Re-read it before
            // treating the non-empty database as unrelated.
            $existing = $redis->get($markerKey);
            if ($existing !== false && $existing !== null && hash_equals($expected, (string) $existing)) {
                $this->namespaceReady = true;

                return;
            }

            throw new \RuntimeException(
                'Market-volume Redis DB is not empty and has no matching namespace marker; refusing to write.'
            );
        }

        $created = $redis->set($markerKey, $expected, ['nx']);
        if (!$created) {
            $existing = $redis->get($markerKey);
            if ($existing === false || !hash_equals($expected, (string) $existing)) {
                throw new \RuntimeException('Unable to establish market-volume Redis namespace marker.');
            }
        }

        $this->namespaceReady = true;
    }

    public function publish($platformId, $providerName, array $volumes, array $context = [])
    {
        $this->ensureNamespace();
        $platformId = (int) $platformId;
        if ($platformId <= 0) {
            throw new \InvalidArgumentException('Platform ID must be positive.');
        }
        if (!is_string($providerName) || trim($providerName) === '') {
            throw new \InvalidArgumentException('Provider name is required.');
        }
        if (empty($volumes)) {
            throw new \UnexpectedValueException('Refusing to publish an empty market-volume snapshot.');
        }

        $fields = [];
        foreach ($volumes as $symbol => $volume) {
            if (!is_string($symbol) || !preg_match('/^[A-Z0-9]+USDT$/', $symbol)) {
                throw new \UnexpectedValueException('Invalid normalized USDT symbol in market-volume snapshot.');
            }
            if (!is_string($volume) || !preg_match('/^\d+(?:\.\d+)?$/', $volume)) {
                throw new \UnexpectedValueException('Invalid quote turnover for '.$symbol.'.');
            }
            $fields[$symbol] = $volume;
        }
        ksort($fields, SORT_STRING);

        $publishedAtMs = $this->nowMs();
        $fetchedAtMs = isset($context['fetched_at_ms'])
            ? (int) $context['fetched_at_ms']
            : $publishedAtMs;
        if ($fetchedAtMs <= 0 || $fetchedAtMs > $publishedAtMs + 300000) {
            throw new \UnexpectedValueException('Invalid fetched_at_ms for market-volume snapshot.');
        }

        $generation = $publishedAtMs.'-'.$this->randomSuffix();
        $meta = [
            'schema_version' => 1,
            'generation' => $generation,
            'platform_id' => $platformId,
            'provider' => trim($providerName),
            'quote' => 'USDT',
            'fetched_at_ms' => $fetchedAtMs,
            'published_at_ms' => $publishedAtMs,
            'expires_at_ms' => $fetchedAtMs + ((int) $this->config['max_age_seconds'] * 1000),
            'stale_after_seconds' => (int) $this->config['max_age_seconds'],
            'symbol_count' => count($fields),
        ];
        if (isset($context['duration_ms'])) {
            $meta['duration_ms'] = max(0, (int) $context['duration_ms']);
        }
        if (isset($context['source_time_ms']) && (int) $context['source_time_ms'] > 0) {
            $meta['source_time_ms'] = (int) $context['source_time_ms'];
        }

        $metaJson = json_encode($meta, JSON_UNESCAPED_SLASHES);
        if ($metaJson === false) {
            throw new \UnexpectedValueException('Unable to encode market-volume metadata.');
        }
        $fields['__meta__'] = $metaJson;

        $redis = $this->redis();
        $stableKey = $this->platformKey($platformId);
        $stagingKey = $this->prefix().':platform:'.$platformId.':tmp:'.$generation;

        $this->guardSnapshotSize($redis, $stableKey, $platformId, count($fields) - 1);

        $redis->multi();
        $redis->hMSet($stagingKey, $fields);
        $redis->expire($stagingKey, max(1, (int) $this->config['temp_ttl_seconds']));
        if (!$this->transactionSucceeded($redis->exec(), 2)) {
            throw new \RuntimeException('Failed to write market-volume staging snapshot.');
        }

        if ((int) $redis->hLen($stagingKey) !== count($fields)) {
            $redis->del($stagingKey);
            throw new \RuntimeException('Market-volume staging snapshot size validation failed.');
        }

        $redis->multi();
        $redis->rename($stagingKey, $stableKey);
        $redis->expire($stableKey, max(1, (int) $this->config['ttl_seconds']));
        if (!$this->transactionSucceeded($redis->exec(), 2)) {
            throw new \RuntimeException('Failed to publish market-volume snapshot.');
        }

        return $meta;
    }

    private function redis()
    {
        if ($this->redis === null) {
            $this->redis = is_callable($this->redisOrFactory)
                ? call_user_func($this->redisOrFactory)
                : $this->redisOrFactory;
        }
        if (!is_object($this->redis)) {
            throw new \RuntimeException('Market-volume Redis factory did not return an object.');
        }

        return $this->redis;
    }

    private function prefix()
    {
        return rtrim((string) $this->config['prefix'], ':');
    }

    private function platformKey($platformId)
    {
        return $this->prefix().':platform:'.(int) $platformId.':usdt';
    }

    private function nowMs()
    {
        return (int) call_user_func($this->clock);
    }

    private function transactionSucceeded($result, $expectedResults)
    {
        // Native phpredis returns one result per queued command. Accept true as
        // well so minimal Redis-compatible clients and test doubles remain
        // usable, but reject any failed command in a returned result list.
        if ($result === true) {
            return true;
        }
        if (!is_array($result) || count($result) < $expectedResults) {
            return false;
        }
        foreach ($result as $item) {
            if ($item === false || $item === null) {
                return false;
            }
        }

        return true;
    }

    private function guardSnapshotSize($redis, $stableKey, $platformId, $newSymbolCount)
    {
        $hashLength = (int) $redis->hLen($stableKey);
        if ($hashLength === 0) {
            return;
        }

        $metaJson = $redis->hGet($stableKey, '__meta__');
        $meta = is_string($metaJson) ? json_decode($metaJson, true) : null;
        if (!is_array($meta)
            || (int) ($meta['platform_id'] ?? 0) !== (int) $platformId
            || (int) ($meta['symbol_count'] ?? 0) <= 0
        ) {
            throw new \RuntimeException('Existing market-volume snapshot metadata is invalid; refusing to replace it.');
        }

        $previousSymbolCount = (int) $meta['symbol_count'];
        if ($hashLength !== $previousSymbolCount + 1) {
            throw new \RuntimeException('Existing market-volume snapshot size does not match its metadata.');
        }

        $minimum = $previousSymbolCount * (float) $this->config['min_snapshot_ratio'];
        if ($newSymbolCount < $minimum) {
            throw new \UnexpectedValueException(
                'New market-volume snapshot is too small: '.$newSymbolCount
                .' symbols versus '.$previousSymbolCount.' previously.'
            );
        }
    }

    private function randomSuffix()
    {
        try {
            return bin2hex(random_bytes(4));
        } catch (\Exception $exception) {
            return str_replace('.', '', uniqid('', true));
        }
    }
}
