<?php

use App\Services\Marketing\MarketingCampaignQueryStringSanitizer;

it('conserva solo campos permitidos y recorta longitudes', function () {
    $sanitizer = new MarketingCampaignQueryStringSanitizer;

    $result = $sanitizer->sanitize([
        'utm_source' => 'facebook',
        'utm_medium' => 'cpc',
        'utm_campaign' => str_repeat('a', 200),
        'utm_term' => 'term',
        'utm_content' => 'content',
        'gclid' => 'gclid-1',
        'fbclid' => 'fbclid-1',
        'evil' => 'drop',
        'redirect' => 'https://evil.test',
    ]);

    expect($result)->toHaveKeys([
        'utm_source',
        'utm_medium',
        'utm_campaign',
        'utm_term',
        'utm_content',
        'gclid',
        'fbclid',
    ])
        ->and($result)->not->toHaveKey('evil')
        ->and($result)->not->toHaveKey('redirect')
        ->and(mb_strlen($result['utm_campaign']))->toBe(160);
});

it('rechaza arrays valores vacios y caracteres de control', function () {
    $sanitizer = new MarketingCampaignQueryStringSanitizer;

    $result = $sanitizer->sanitize([
        'utm_source' => ['facebook'],
        'utm_medium' => '',
        'utm_campaign' => "foo\x00bar",
        'utm_term' => "line\nbreak",
    ]);

    expect($result)->not->toHaveKey('utm_source')
        ->and($result)->not->toHaveKey('utm_medium')
        ->and($result['utm_campaign'])->toBe('foobar')
        ->and($result['utm_term'])->toBe('linebreak');
});
