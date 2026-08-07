<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Marketing campaign attribution
    |--------------------------------------------------------------------------
    |
    | Captura anónima first/last touch para landings /c/{slug}. La cookie sólo
    | contiene un token opaco; nunca UTMs, IDs internos ni PII.
    |
    */

    'enabled' => filter_var(env('MARKETING_ATTRIBUTION_ENABLED', true), FILTER_VALIDATE_BOOL),

    'cookie_name' => env('MARKETING_ATTRIBUTION_COOKIE_NAME', 'famedic_campaign_attribution'),

    'window_days' => (int) env('MARKETING_ATTRIBUTION_WINDOW_DAYS', 30),

    'cookie_path' => '/',

    'cookie_same_site' => 'lax',

    'token_hash_key' => env('MARKETING_ATTRIBUTION_TOKEN_HASH_KEY', env('APP_KEY')),

    'secure' => env('MARKETING_ATTRIBUTION_COOKIE_SECURE') !== null
        ? filter_var(env('MARKETING_ATTRIBUTION_COOKIE_SECURE'), FILTER_VALIDATE_BOOL)
        : parse_url((string) env('APP_URL', 'http://localhost'), PHP_URL_SCHEME) === 'https',

    'limits' => [
        'utm' => 255,
        'gclid' => 255,
        'fbclid' => 255,
        'referrer_host' => 255,
        'landing_path' => 255,
    ],

];
