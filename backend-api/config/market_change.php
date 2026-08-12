<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Extreme market data source
    |--------------------------------------------------------------------------
    |
    | mysql  - legacy market_change reads
    | shadow - return MySQL while comparing a Redis generation in the log
    | redis  - serve only a validated Redis generation (never silently fall back)
    |
    */
    'source' => strtolower(env('MARKET_CHANGE_SOURCE', 'mysql')),

    'redis_db' => (int) env('MARKET_CHANGE_REDIS_DB', 9),
    'redis_prefix' => env('MARKET_CHANGE_REDIS_PREFIX', 'v2:market_change'),
    'redis_schema_version' => 2,
    'redis_max_age_seconds' => (int) env('MARKET_CHANGE_REDIS_MAX_AGE_SECONDS', 5),
    'shadow_sample_percent' => (int) env('MARKET_CHANGE_SHADOW_SAMPLE_PERCENT', 10),
    'error_log_interval_seconds' => (int) env('MARKET_CHANGE_ERROR_LOG_INTERVAL_SECONDS', 10),
];
