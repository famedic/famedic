<?php

use App\DataTransferObjects\Marketing\MarketingCampaignEffectiveTrackingData;
use App\Models\MarketingCampaignLink;

it('prioriza query sobre defaults del enlace', function () {
    $link = new MarketingCampaignLink([
        'utm_source' => 'default-source',
        'utm_medium' => 'default-medium',
        'utm_campaign' => 'default-campaign',
        'utm_term' => 'default-term',
        'utm_content' => 'default-content',
    ]);

    $tracking = MarketingCampaignEffectiveTrackingData::fromLinkAndQuery($link, [
        'utm_source' => 'facebook',
        'utm_medium' => 'cpc',
    ]);

    expect($tracking->utmSource)->toBe('facebook')
        ->and($tracking->utmMedium)->toBe('cpc')
        ->and($tracking->utmCampaign)->toBe('default-campaign')
        ->and($tracking->utmTerm)->toBe('default-term')
        ->and($tracking->utmContent)->toBe('default-content');
});

it('usa defaults cuando query no llega', function () {
    $link = new MarketingCampaignLink([
        'utm_source' => 'newsletter',
        'utm_medium' => 'email',
    ]);

    $tracking = MarketingCampaignEffectiveTrackingData::fromLinkAndQuery($link, []);

    expect($tracking->utmSource)->toBe('newsletter')
        ->and($tracking->utmMedium)->toBe('email')
        ->and($tracking->utmCampaign)->toBeNull();
});

it('devuelve null cuando query y default faltan', function () {
    $link = new MarketingCampaignLink();

    $tracking = MarketingCampaignEffectiveTrackingData::fromLinkAndQuery($link, []);

    expect($tracking->utmSource)->toBeNull()
        ->and($tracking->gclid)->toBeNull();
});

it('conserva el string cero como valor valido', function () {
    $link = new MarketingCampaignLink(['utm_source' => '0']);

    $tracking = MarketingCampaignEffectiveTrackingData::fromLinkAndQuery($link, [
        'utm_medium' => '0',
    ]);

    expect($tracking->utmSource)->toBe('0')
        ->and($tracking->utmMedium)->toBe('0');
});

it('ignora arrays y recorta longitud excesiva', function () {
    $link = new MarketingCampaignLink();

    $tracking = MarketingCampaignEffectiveTrackingData::fromLinkAndQuery($link, [
        'utm_source' => ['evil'],
        'utm_campaign' => str_repeat('a', 300),
        'gclid' => "abc\x00def",
    ], utmLimit: 255, clickIdLimit: 255);

    expect($tracking->utmSource)->toBeNull()
        ->and(mb_strlen($tracking->utmCampaign))->toBe(255)
        ->and($tracking->gclid)->toBe('abcdef');
});
