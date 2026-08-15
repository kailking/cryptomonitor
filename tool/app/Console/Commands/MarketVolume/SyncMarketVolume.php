<?php

namespace App\Console\Commands\MarketVolume;

use App\Model\CurrencyQuotation;
use App\Service\MarketVolume\Http\CurlJsonHttpClient;
use App\Service\MarketVolume\MarketVolumeCollector;
use App\Service\MarketVolume\MarketVolumeProviderRegistry;
use App\Service\MarketVolume\RedisMarketVolumeStore;
use App\Service\RedisService;
use Illuminate\Console\Command;

class SyncMarketVolume extends Command
{
    protected $signature = 'market-volume:sync
                            {--platform= : Only sync one active platform ID}
                            {--list-platforms : Print active platform IDs without requesting an exchange or writing KeyDB}
                            {--dry-run : Fetch and validate without writing KeyDB}';

    protected $description = 'Collect 24-hour spot USDT quote turnover into the dedicated KeyDB database';

    public function handle()
    {
        $settings = (array) config('market_volume', []);
        $activePlatformIds = array_map('intval', array_keys(CurrencyQuotation::$platform_text));
        sort($activePlatformIds, SORT_NUMERIC);

        $http = new CurlJsonHttpClient((array) ($settings['http'] ?? []));
        $registry = new MarketVolumeProviderRegistry((array) ($settings['providers'] ?? []), $http);

        try {
            // Fail before any exchange request when an active platform adapter is missing.
            $registry->validate($activePlatformIds);
        } catch (\Throwable $exception) {
            $this->error($exception->getMessage());

            return 1;
        }

        if ((bool) $this->option('list-platforms')) {
            foreach ($activePlatformIds as $platformId) {
                $this->line((string) $platformId);
            }

            return 0;
        }

        $requestedPlatform = $this->option('platform');
        if ($requestedPlatform !== null) {
            if (!ctype_digit((string) $requestedPlatform)) {
                $this->error('--platform must be an active numeric platform ID.');

                return 1;
            }
            $requestedPlatform = (int) $requestedPlatform;
            if (!in_array($requestedPlatform, $activePlatformIds, true)) {
                $this->error('Platform '.$requestedPlatform.' is not enabled in CurrencyQuotation::$platform_text.');

                return 1;
            }
            $platformIds = [$requestedPlatform];
        } else {
            $platformIds = $activePlatformIds;
        }

        $store = new RedisMarketVolumeStore(function () use ($settings) {
            return RedisService::getInstance((int) ($settings['redis_db'] ?? 10));
        }, $settings);

        $dryRun = (bool) $this->option('dry-run');
        if (!$dryRun) {
            try {
                // DB10 must either be empty or already carry our exact marker.
                $store->ensureNamespace();
            } catch (\Throwable $exception) {
                $this->error($exception->getMessage());

                return 1;
            }
        }

        $collector = new MarketVolumeCollector(
            $registry,
            $store,
            (int) ($settings['platform_delay_ms'] ?? 500)
        );
        $results = $collector->collect($platformIds, $dryRun);

        $rows = [];
        $failed = 0;
        foreach ($results as $result) {
            if (!$result['success']) {
                $failed++;
            }
            $rows[] = [
                $result['platform_id'],
                $result['provider'],
                $result['success'] ? ($dryRun ? 'DRY-RUN OK' : 'PUBLISHED') : 'FAILED',
                $result['symbol_count'],
                $result['duration_ms'],
                $result['error'] ?: '-',
            ];
        }

        $this->table(['Platform', 'Provider', 'Status', 'USDT symbols', 'Duration ms', 'Message'], $rows);
        if ($failed > 0) {
            $this->error($failed.' platform(s) failed; successful platform snapshots were kept.');

            return 2;
        }

        $this->info(($dryRun ? 'Dry-run validation' : 'Market-volume publication').' completed successfully.');

        return 0;
    }
}
