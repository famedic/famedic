<?php

use App\Support\ClientContext;

it('parses known user agents conservatively', function (string $userAgent, array $expected) {
    expect(ClientContext::fromUserAgent($userAgent))->toMatchArray([
        ...$expected,
        'source' => 'request_user_agent',
    ]);
})->with([
    'iPhone Safari' => [
        'Mozilla/5.0 (iPhone; CPU iPhone OS 17_0 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/17.0 Mobile/15E148 Safari/604.1',
        ['device_type' => 'mobile', 'browser' => 'Safari', 'os' => 'iOS'],
    ],
    'Android Chrome' => [
        'Mozilla/5.0 (Linux; Android 14; Pixel 8) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Mobile Safari/537.36',
        ['device_type' => 'mobile', 'browser' => 'Chrome', 'os' => 'Android'],
    ],
    'iPad Safari' => [
        'Mozilla/5.0 (iPad; CPU OS 17_0 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/17.0 Mobile/15E148 Safari/604.1',
        ['device_type' => 'tablet', 'browser' => 'Safari', 'os' => 'iOS'],
    ],
    'Windows Chrome' => [
        'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36',
        ['device_type' => 'desktop', 'browser' => 'Chrome', 'os' => 'Windows'],
    ],
    'Windows Edge' => [
        'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36 Edg/120.0.0.0',
        ['device_type' => 'desktop', 'browser' => 'Edge', 'os' => 'Windows'],
    ],
    'macOS Safari' => [
        'Mozilla/5.0 (Macintosh; Intel Mac OS X 14_1) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/17.1 Safari/605.1.15',
        ['device_type' => 'desktop', 'browser' => 'Safari', 'os' => 'macOS'],
    ],
    'Samsung Internet' => [
        'Mozilla/5.0 (Linux; Android 13; SAMSUNG SM-S918B) AppleWebKit/537.36 (KHTML, like Gecko) SamsungBrowser/21.0 Chrome/110.0.5481.154 Mobile Safari/537.36',
        ['device_type' => 'mobile', 'browser' => 'Samsung Internet', 'os' => 'Android'],
    ],
    'Unknown UA' => [
        '',
        ['device_type' => 'unknown', 'browser' => 'Unknown', 'os' => 'Unknown'],
    ],
]);
