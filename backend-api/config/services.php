<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Third Party Services
    |--------------------------------------------------------------------------
    |
    | This file is for storing the credentials for third party services such
    | as Mailgun, Postmark, AWS and more. This file provides the de facto
    | location for this type of information, allowing packages to have
    | a conventional file to locate the various service credentials.
    |
    */

    'mailgun' => [
        'domain' => env('MAILGUN_DOMAIN'),
        'secret' => env('MAILGUN_SECRET'),
        'endpoint' => env('MAILGUN_ENDPOINT', 'api.mailgun.net'),
    ],

    'postmark' => [
        'token' => env('POSTMARK_TOKEN'),
    ],

    'ses' => [
        'key' => env('AWS_ACCESS_KEY_ID'),
        'secret' => env('AWS_SECRET_ACCESS_KEY'),
        'region' => env('AWS_DEFAULT_REGION', 'us-east-1'),
    ],

    'bishuju_proxy' => [
        'url' => env('BISHUJU_PROXY_URL'),
        'credentials' => env('BISHUJU_PROXY_CREDENTIALS'),
    ],

    'okx' => [
        'api_key' => env('OKX_API_KEY'),
        'api_secret' => env('OKX_API_SECRET'),
        'passphrase' => env('OKX_PASSPHRASE'),
    ],

    'rpc' => [
        'eth' => env('ETH_RPC_URL'),
        'bsc' => env('BSC_RPC_URL', 'https://bsc-dataseed.binance.org/'),
        'sol' => env('SOL_RPC_URL', 'https://api.mainnet-beta.solana.com'),
    ],

];
