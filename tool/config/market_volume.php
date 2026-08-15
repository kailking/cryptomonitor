<?php

use App\Service\MarketVolume\Providers\BinanceProvider;
use App\Service\MarketVolume\Providers\BitgetProvider;
use App\Service\MarketVolume\Providers\BybitProvider;
use App\Service\MarketVolume\Providers\CoinexProvider;
use App\Service\MarketVolume\Providers\GateProvider;
use App\Service\MarketVolume\Providers\HtxProvider;
use App\Service\MarketVolume\Providers\KucoinProvider;
use App\Service\MarketVolume\Providers\LbankProvider;
use App\Service\MarketVolume\Providers\MexcProvider;
use App\Service\MarketVolume\Providers\OkxProvider;
use App\Service\MarketVolume\Providers\PhemexProvider;
use App\Service\MarketVolume\Providers\PionexProvider;
use App\Service\MarketVolume\Providers\WeexProvider;
use App\Service\MarketVolume\Providers\XtProvider;

return [
    // DB10 is dedicated to this feature. Production must confirm it is empty
    // before the first run; the namespace marker then prevents accidental use
    // of an unrelated non-empty logical database.
    // Keep the raw value so the Store can reject malformed values such as
    // "12abc" instead of PHP silently coercing them to another database.
    'redis_db' => env('MARKET_VOLUME_REDIS_DB', '10'),
    'prefix' => env('MARKET_VOLUME_REDIS_PREFIX', 'market_volume:v1'),
    'namespace_value' => 'cryptomonitor-market-volume-v1',
    'forbidden_redis_dbs' => [0, 1, 2, 3, 4, 5, 6, 9, 11],

    // A snapshot is hidden by consumers after 30 minutes and is physically
    // deleted after 60 minutes. Failed requests never refresh either value.
    'max_age_seconds' => (int) env('MARKET_VOLUME_MAX_AGE_SECONDS', 1800),
    'ttl_seconds' => (int) env('MARKET_VOLUME_TTL_SECONDS', 3600),
    'temp_ttl_seconds' => (int) env('MARKET_VOLUME_TEMP_TTL_SECONDS', 600),
    'min_snapshot_ratio' => (float) env('MARKET_VOLUME_MIN_SNAPSHOT_RATIO', 0.5),

    // A manual full `market-volume:sync` remains serial. The scheduler invokes
    // one platform per process through scripts/update_market_volume.sh instead.
    'platform_delay_ms' => (int) env('MARKET_VOLUME_PLATFORM_DELAY_MS', 500),
    // Opt in only after DB10 ownership, the shell script and the first manual
    // snapshot have been verified. Uploading code alone must never start it.
    'schedule_enabled' => env('MARKET_VOLUME_SCHEDULE_ENABLED', false),

    'http' => [
        'connect_timeout' => (int) env('MARKET_VOLUME_HTTP_CONNECT_TIMEOUT', 3),
        'timeout' => (int) env('MARKET_VOLUME_HTTP_TIMEOUT', 10),
        'retries' => (int) env('MARKET_VOLUME_HTTP_RETRIES', 1),
        'retry_delay_ms' => (int) env('MARKET_VOLUME_HTTP_RETRY_DELAY_MS', 500),
        'user_agent' => env('MARKET_VOLUME_HTTP_USER_AGENT', 'cryptomonitor-market-volume/1.0'),
    ],

    // This is the only provider class map. The command separately validates
    // it against the currently enabled CurrencyQuotation::$platform_text IDs.
    'providers' => [
        1 => HtxProvider::class,
        2 => BinanceProvider::class,
        3 => OkxProvider::class,
        4 => GateProvider::class,
        5 => MexcProvider::class,
        8 => KucoinProvider::class,
        9 => CoinexProvider::class,
        10 => LbankProvider::class,
        15 => BitgetProvider::class,
        16 => BybitProvider::class,
        19 => WeexProvider::class,
        21 => XtProvider::class,
        22 => PhemexProvider::class,
        23 => PionexProvider::class,
    ],
];
