<?php

namespace App\Services;

use App\Exceptions\MarketChangeRedisUnavailableException;
use App\Service\RedisService;

class MarketChangeRedisGenerationService
{
    private $redis;

    public function __construct($redis = null)
    {
        $this->redis = $redis;
    }

    /**
     * Read exactly one immutable generation. If that generation expires while
     * it is being read, the request fails and never follows a newer pointer.
     */
    public function readPage($direction, array $filters, $page, $pageSize)
    {
        try {
            return $this->readPageFromGeneration($direction, $filters, $page, $pageSize);
        } catch (MarketChangeRedisUnavailableException $e) {
            throw $e;
        } catch (\Throwable $e) {
            throw $this->unavailable('Redis generation read failed', $e);
        }
    }

    private function readPageFromGeneration($direction, array $filters, $page, $pageSize)
    {
        $directionKey = (int) $direction === 2 ? 'down' : 'up';
        $prefix = rtrim((string) config('market_change.redis_prefix', 'v2:market_change'), ':');
        $redis = $this->redis();

        $generation = $redis->get($prefix.':current_generation');
        if (!is_string($generation) || !preg_match('/^[A-Za-z0-9._:-]{1,128}$/', $generation)) {
            throw $this->unavailable('generation pointer is missing or invalid');
        }

        $base = $prefix.':generation:'.$generation;
        $metaRaw = $redis->get($base.':meta');
        $indexRaw = $redis->get($base.':index:'.$directionKey);

        $meta = $this->decodeObject($metaRaw, 'generation meta');
        $index = $this->decodeObject($indexRaw, 'direction index');

        $this->validateEnvelope($meta, $generation, 'generation meta', true);
        $this->validateEnvelope($index, $generation, 'direction index');
        if ((int) $meta['generated_at_ms'] !== (int) $index['generated_at_ms']) {
            throw $this->unavailable('generation meta and direction index timestamps do not match');
        }
        if (isset($index['api_max_age_seconds'])
            && (int) $index['api_max_age_seconds'] !== (int) $meta['api_max_age_seconds']) {
            throw $this->unavailable('generation meta and direction index max ages do not match');
        }

        if (!isset($index['data']) || !is_array($index['data'])) {
            throw $this->unavailable('direction index data is invalid');
        }
        $dataKey = $base.':data';
        if (!empty($index['data']) && (int) $redis->exists($dataKey) < 1) {
            throw $this->unavailable('generation detail hash is missing');
        }

        $qualified = [];
        $seen = [];
        $lastChange = null;
        $lastId = null;
        foreach ($index['data'] as $indexItem) {
            $item = $this->normalizeIndexItem($indexItem);
            $id = $item['id'];
            if ($id <= 0 || isset($seen[$id])) {
                throw $this->unavailable('direction index contains a duplicate or non-positive ID');
            }
            $seen[$id] = true;
            if ($lastChange !== null
                && ($item['change'] > $lastChange
                    || ($item['change'] === $lastChange && $id < $lastId))) {
                throw $this->unavailable('direction index sort order is invalid');
            }
            $lastChange = $item['change'];
            $lastId = $id;

            if (!empty($filters['blocked_ids'][$id]) || !empty($filters['temporary_blocked_ids'][$id])) {
                continue;
            }
            if (!empty($filters['excluded_platforms'][$item['platform']])) {
                continue;
            }
            if (!empty($filters['symbol'])
                && !MarketChangeSymbolNormalizer::contains(
                    $item['currency_name'].$item['quote_name'],
                    $filters['symbol']
                )) {
                continue;
            }
            if (isset($filters['change_gt']) && $filters['change_gt'] > 0 && $item['change'] <= $filters['change_gt']) {
                continue;
            }
            if ($item['change'] < 0 || $item['change'] > 2000) {
                continue;
            }

            $qualified[] = $item;
        }

        $total = count($qualified);
        $page = max(1, (int) $page);
        $pageSize = max(1, (int) $pageSize);
        $pagedIndex = array_slice($qualified, ($page - 1) * $pageSize, $pageSize);
        $ids = array_column($pagedIndex, 'id');
        $items = $this->readDetails($redis, $dataKey, $pagedIndex, (int) $direction);

        return [
            'generation' => $generation,
            'generated_at_ms' => (int) $meta['generated_at_ms'],
            'items' => $items,
            'total' => $total,
        ];
    }

    private function redis()
    {
        if ($this->redis === null) {
            $this->redis = RedisService::getInstance(
                (int) config('market_change.redis_db', 9)
            );
        }

        return $this->redis;
    }

    private function validateEnvelope(array $payload, $generation, $label, $requireDeclaredMaxAge = false)
    {
        $expectedSchema = (int) config('market_change.redis_schema_version', 2);
        if (!isset($payload['schema_version']) || (int) $payload['schema_version'] !== $expectedSchema) {
            throw $this->unavailable($label.' schema version is incompatible');
        }
        if (!array_key_exists('warmup_complete', $payload) || $payload['warmup_complete'] !== true) {
            throw $this->unavailable($label.' is not warmed up');
        }
        if (!isset($payload['generation']) || (string) $payload['generation'] !== (string) $generation) {
            throw $this->unavailable($label.' generation does not match its key');
        }
        if (!isset($payload['generated_at_ms']) || !$this->isIntegerLike($payload['generated_at_ms'])) {
            throw $this->unavailable($label.' generated_at_ms is invalid');
        }
        if ($requireDeclaredMaxAge && !isset($payload['api_max_age_seconds'])) {
            throw $this->unavailable($label.' api_max_age_seconds is invalid');
        }
        if (isset($payload['api_max_age_seconds'])
            && (!$this->isIntegerLike($payload['api_max_age_seconds'])
                || (int) $payload['api_max_age_seconds'] < 1)) {
            throw $this->unavailable($label.' api_max_age_seconds is invalid');
        }

        $generatedAtMs = (int) $payload['generated_at_ms'];
        $nowMs = (int) floor(microtime(true) * 1000);
        $configuredMaxAge = max(1, (int) config('market_change.redis_max_age_seconds', 5));
        $declaredMaxAge = isset($payload['api_max_age_seconds'])
            ? (int) $payload['api_max_age_seconds']
            : $configuredMaxAge;
        $maxAgeMs = min($configuredMaxAge, $declaredMaxAge) * 1000;

        if ($generatedAtMs > $nowMs + 2000) {
            throw $this->unavailable($label.' timestamp is in the future');
        }
        if ($generatedAtMs < $nowMs - $maxAgeMs) {
            throw $this->unavailable($label.' is stale');
        }
    }

    private function readDetails($redis, $hashKey, array $indexItems, $direction)
    {
        $ids = array_column($indexItems, 'id');
        if (empty($ids)) {
            return [];
        }

        $rawById = [];
        foreach (array_chunk($ids, 1000) as $chunk) {
            $fields = array_map('strval', $chunk);
            $part = $redis->hMGet($hashKey, $fields);
            if (!is_array($part)) {
                throw $this->unavailable('generation detail hash disappeared');
            }
            foreach ($fields as $position => $field) {
                if (array_key_exists($field, $part)) {
                    $rawById[(int) $field] = $part[$field];
                } elseif (array_key_exists($position, $part)) {
                    $rawById[(int) $field] = $part[$position];
                }
            }
        }

        $items = [];
        $indexById = [];
        foreach ($indexItems as $indexItem) {
            $indexById[$indexItem['id']] = $indexItem;
        }
        foreach ($ids as $id) {
            if (!isset($rawById[$id]) || !is_string($rawById[$id]) || $rawById[$id] === '') {
                throw $this->unavailable('generation detail is incomplete for ID '.$id);
            }
            $detail = $this->decodeObject($rawById[$id], 'generation detail');
            $normalized = $this->normalizeDetail($detail, $id);
            $indexItem = $indexById[$id];
            if ($normalized['direction'] !== $direction
                || $normalized['match_id'] !== $indexItem['match_id']
                || $normalized['platform'] !== $indexItem['platform']
                || MarketChangeSymbolNormalizer::upper($normalized['currency_name']) !== $indexItem['currency_name']
                || MarketChangeSymbolNormalizer::upper($normalized['quote_name']) !== $indexItem['quote_name']
                || abs((float) $normalized['change'] - (float) $indexItem['change']) > 0.000001) {
                throw $this->unavailable('generation index and detail metadata do not match for ID '.$id);
            }
            $items[] = $normalized;
        }

        return $items;
    }

    private function normalizeIndexItem($item)
    {
        if (!is_array($item)) {
            throw $this->unavailable('direction index item is invalid');
        }

        $id = $this->value($item, ['i', 'id']);
        $matchId = $this->value($item, ['m', 'match_id']);
        $platform = $this->value($item, ['p', 'platform']);
        $currencyName = $this->value($item, ['cn', 'currency_name']);
        $quoteName = $this->value($item, ['qn', 'quote_name']);
        $change = $this->value($item, ['c', 'change']);

        foreach ([$id, $matchId, $platform] as $number) {
            if (!$this->isIntegerLike($number) || (int) $number <= 0) {
                throw $this->unavailable('direction index item contains an invalid integer');
            }
        }
        if (!is_numeric($change) || !is_finite((float) $change)) {
            throw $this->unavailable('direction index item contains an invalid change');
        }
        if (!is_string($currencyName) || $currencyName === '' || !is_string($quoteName) || $quoteName === '') {
            throw $this->unavailable('direction index item market metadata is invalid');
        }

        return [
            'id' => (int) $id,
            'match_id' => (int) $matchId,
            'platform' => (int) $platform,
            'currency_name' => MarketChangeSymbolNormalizer::upper($currencyName),
            'quote_name' => MarketChangeSymbolNormalizer::upper($quoteName),
            'change' => (float) $change,
        ];
    }

    private function normalizeDetail(array $detail, $indexId)
    {
        $id = $this->value($detail, ['i', 'id']);
        $matchId = $this->value($detail, ['m', 'match_id']);
        $symbol = $this->value($detail, ['s', 'symbol']);
        $platform = $this->value($detail, ['p', 'platform']);
        $period = $this->value($detail, ['pd', 'period']);
        $direction = $this->value($detail, ['dr', 'direction']);
        $change = $this->value($detail, ['c', 'change']);
        $priceBegin = $this->value($detail, ['pb', 'price_begin']);
        $priceEnd = $this->value($detail, ['pe', 'price_end']);
        $currencyName = $this->value($detail, ['cn', 'currency_name']);
        $quoteName = $this->value($detail, ['qn', 'quote_name']);
        $createdAt = $this->value($detail, ['ca', 'created_at']);
        $updatedAt = $this->value($detail, ['ua', 'updated_at', 't']);

        foreach ([$id, $matchId, $platform, $period, $direction] as $number) {
            if (!$this->isIntegerLike($number)) {
                throw $this->unavailable('generation detail contains an invalid integer');
            }
        }
        if ((int) $matchId <= 0 || (int) $platform <= 0
            || !is_numeric($change) || !is_finite((float) $change)) {
            throw $this->unavailable('generation detail contains an invalid number');
        }

        if ((int) $id !== (int) $indexId || (int) $id <= 0) {
            throw $this->unavailable('generation detail ID does not match its index');
        }
        if (!in_array((int) $direction, [1, 2], true) || (int) $period !== 5) {
            throw $this->unavailable('generation detail direction or period is invalid');
        }
        if (!is_string($symbol) || $symbol === '' || !is_string($currencyName) || !is_string($quoteName)) {
            throw $this->unavailable('generation detail market metadata is invalid');
        }
        if (!$this->isLegacyTimestamp($createdAt) || !$this->isLegacyTimestamp($updatedAt)) {
            throw $this->unavailable('generation detail timestamps are invalid');
        }
        if (!$this->isFixedPrice($priceBegin) || !$this->isFixedPrice($priceEnd)) {
            throw $this->unavailable('generation detail prices must be fixed 18-place decimal strings');
        }

        return [
            'id' => (int) $id,
            'match_id' => (int) $matchId,
            'symbol' => MarketChangeSymbolNormalizer::upper($symbol),
            'platform' => (int) $platform,
            // Redis and the legacy market_change API both expose minutes.
            'period' => (int) $period,
            'direction' => (int) $direction,
            'change' => (float) $change,
            'price_begin' => (string) $priceBegin,
            'price_end' => (string) $priceEnd,
            'currency_name' => MarketChangeSymbolNormalizer::upper($currencyName),
            'quote_name' => MarketChangeSymbolNormalizer::upper($quoteName),
            'created_at' => $createdAt,
            'updated_at' => $updatedAt,
        ];
    }

    private function decodeObject($json, $label)
    {
        if (!is_string($json) || $json === '') {
            throw $this->unavailable($label.' is missing');
        }
        $value = json_decode($json, true, 512, JSON_BIGINT_AS_STRING);
        if (!is_array($value) || json_last_error() !== JSON_ERROR_NONE) {
            throw $this->unavailable($label.' is not valid JSON');
        }
        return $value;
    }

    private function value(array $values, array $keys, $default = '__missing__')
    {
        foreach ($keys as $key) {
            if (array_key_exists($key, $values)) {
                return $values[$key];
            }
        }
        if ($default !== '__missing__') {
            return $default;
        }
        throw $this->unavailable('generation detail is missing field '.$keys[0]);
    }

    private function isIntegerLike($value)
    {
        return is_int($value) || (is_string($value) && ctype_digit($value));
    }

    private function isFixedPrice($value)
    {
        return is_string($value)
            && preg_match('/^(?:0|[1-9][0-9]*)\.[0-9]{18}$/D', $value) === 1;
    }

    private function isLegacyTimestamp($value)
    {
        return is_string($value)
            && preg_match('/^[0-9]{4}-[0-9]{2}-[0-9]{2} [0-9]{2}:[0-9]{2}:[0-9]{2}$/D', $value) === 1
            && strtotime($value) !== false;
    }

    private function unavailable($reason, $previous = null)
    {
        return new MarketChangeRedisUnavailableException($reason, 0, $previous);
    }
}
