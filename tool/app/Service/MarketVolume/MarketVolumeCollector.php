<?php

namespace App\Service\MarketVolume;

use App\Service\MarketVolume\Contracts\MarketVolumeStoreInterface;

class MarketVolumeCollector
{
    /** @var MarketVolumeProviderRegistry */
    private $registry;

    /** @var MarketVolumeStoreInterface */
    private $store;

    /** @var int */
    private $platformDelayMs;

    /** @var callable */
    private $clock;

    public function __construct(
        MarketVolumeProviderRegistry $registry,
        MarketVolumeStoreInterface $store,
        $platformDelayMs = 0,
        callable $clock = null
    ) {
        $this->registry = $registry;
        $this->store = $store;
        $this->platformDelayMs = max(0, (int) $platformDelayMs);
        $this->clock = $clock ?: function () {
            return (int) floor(microtime(true) * 1000);
        };
    }

    /**
     * Each platform is isolated: a failed fetch never blocks later platforms.
     *
     * @param array<int> $platformIds
     * @param bool $dryRun
     * @return array<int, array<string, mixed>>
     */
    public function collect(array $platformIds, $dryRun = false)
    {
        $results = [];
        $count = count($platformIds);

        foreach (array_values($platformIds) as $index => $platformId) {
            $platformId = (int) $platformId;
            $startedAtMs = $this->nowMs();
            $providerName = 'unknown';

            try {
                $provider = $this->registry->get($platformId);
                $providerName = (string) $provider->providerName();
                $volumes = $provider->fetch();
                $fetchedAtMs = $this->nowMs();
                $volumes = $this->validateSnapshot($volumes);
                $durationMs = max(0, $fetchedAtMs - $startedAtMs);

                $meta = null;
                if (!$dryRun) {
                    $meta = $this->store->publish($platformId, $providerName, $volumes, [
                        'fetched_at_ms' => $fetchedAtMs,
                        'duration_ms' => $durationMs,
                    ]);
                }

                $results[] = [
                    'platform_id' => $platformId,
                    'provider' => $providerName,
                    'success' => true,
                    'dry_run' => (bool) $dryRun,
                    'symbol_count' => count($volumes),
                    'duration_ms' => $durationMs,
                    'fetched_at_ms' => $fetchedAtMs,
                    'published_at_ms' => $meta ? $meta['published_at_ms'] : null,
                    'error' => null,
                ];
            } catch (\Throwable $exception) {
                $results[] = [
                    'platform_id' => $platformId,
                    'provider' => $providerName,
                    'success' => false,
                    'dry_run' => (bool) $dryRun,
                    'symbol_count' => 0,
                    'duration_ms' => max(0, $this->nowMs() - $startedAtMs),
                    'fetched_at_ms' => null,
                    'published_at_ms' => null,
                    'error' => $exception->getMessage(),
                ];
            }

            if ($this->platformDelayMs > 0 && $index < $count - 1) {
                usleep($this->platformDelayMs * 1000);
            }
        }

        return $results;
    }

    private function validateSnapshot($volumes)
    {
        if (!is_array($volumes) || empty($volumes)) {
            throw new \UnexpectedValueException('Provider returned no valid spot USDT volume records.');
        }

        $validated = [];
        foreach ($volumes as $symbol => $volume) {
            if (!is_string($symbol) || !preg_match('/^[A-Z0-9]+USDT$/', $symbol)) {
                throw new \UnexpectedValueException('Provider returned an invalid normalized USDT symbol.');
            }
            if (!is_string($volume) || !preg_match('/^\d+(?:\.\d+)?$/', $volume)) {
                throw new \UnexpectedValueException('Provider returned an invalid quote turnover for '.$symbol.'.');
            }
            $validated[$symbol] = $volume;
        }
        ksort($validated, SORT_STRING);

        return $validated;
    }

    private function nowMs()
    {
        return (int) call_user_func($this->clock);
    }
}
