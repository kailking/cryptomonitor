<?php

return [
    'cookie_name' => env('DEVICE_COOKIE_NAME', 'bishuju_device'),
    'cookie_lifetime_minutes' => (int) env('DEVICE_COOKIE_LIFETIME_MINUTES', 525600),
    'cookie_secure' => filter_var(
        env('DEVICE_COOKIE_SECURE', false),
        FILTER_VALIDATE_BOOLEAN
    ),
    'rapid_switch_minutes' => 10,
    'recent_window_hours' => 24,
    'recent_device_threshold' => 3,
    'alert_cooldown_minutes' => 30,
];
