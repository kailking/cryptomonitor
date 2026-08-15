<?php

return [
    // API-side freshness guard. The collector TTL is deliberately longer;
    // expired business data must be hidden before KeyDB physically removes it.
    'max_age_seconds' => (int) env('MARKET_VOLUME_MAX_AGE_SECONDS', 1800),
];
