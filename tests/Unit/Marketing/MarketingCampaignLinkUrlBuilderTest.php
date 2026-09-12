<?php

use App\Enums\LaboratoryBrand;
use App\Enums\MarketingCampaignLinkStatus;
use App\Enums\MarketingCampaignStatus;
use App\Enums\MarketingCampaignTargetType;
use App\Models\MarketingCampaign;
use App\Models\MarketingCampaignLink;
use App\Services\Marketing\MarketingCampaignLinkUrlBuilder;
use Illuminate\Support\Facades\URL;

require_once __DIR__.'/marketingCampaignIsolatedSchema.php';

beforeEach(function () {
    bootstrapIsolatedMarketingCampaignSchema();
    URL::forceRootUrl('https://campanas.famedic.test');
    URL::forceScheme('https');
});

afterEach(function () {
    URL::forceRootUrl(null);
    URL::forceScheme(null);
    tearDownIsolatedMarketingCampaignSchema();
});

function makeUrlBuilderLink(array $attributes = []): MarketingCampaignLink
{
    $campaign = MarketingCampaign::factory()->create([
        'status' => MarketingCampaignStatus::Active,
    ]);

    return MarketingCampaignLink::factory()->for($campaign, 'campaign')->create(array_merge([
        'slug' => 'mes-mas-patrio',
        'status' => MarketingCampaignLinkStatus::Active,
        'target_type' => MarketingCampaignTargetType::Brand,
        'target_payload' => ['brand' => LaboratoryBrand::OLAB->value],
    ], $attributes));
}

function campaignUrlBuilder(): MarketingCampaignLinkUrlBuilder
{
    return app(MarketingCampaignLinkUrlBuilder::class);
}

it('genera url completa con todas las utms', function () {
    $link = makeUrlBuilderLink([
        'utm_source' => 'google',
        'utm_medium' => 'cpc',
        'utm_campaign' => 'mes patrio',
        'utm_term' => 'rayos x',
        'utm_content' => 'hero principal',
    ]);

    expect(campaignUrlBuilder()->fullUrl($link))->toBe(
        'https://campanas.famedic.test/c/mes-mas-patrio?utm_source=google&utm_medium=cpc&utm_campaign=mes%20patrio&utm_term=rayos%20x&utm_content=hero%20principal'
    );
});

it('incluye solo utms con valor y no deja separadores vacios', function () {
    $link = makeUrlBuilderLink([
        'utm_source' => 'email',
        'utm_medium' => '',
        'utm_campaign' => null,
        'utm_term' => '0',
        'utm_content' => '',
    ]);

    expect(campaignUrlBuilder()->fullUrl($link))->toBe(
        'https://campanas.famedic.test/c/mes-mas-patrio?utm_source=email&utm_term=0'
    );
});

it('devuelve la url base cuando no hay utms', function () {
    $link = makeUrlBuilderLink([
        'utm_source' => null,
        'utm_medium' => null,
        'utm_campaign' => null,
        'utm_term' => null,
        'utm_content' => null,
    ]);

    expect(campaignUrlBuilder()->fullUrl($link))->toBe('https://campanas.famedic.test/c/mes-mas-patrio');
});

it('codifica acentos espacios y caracteres especiales sin agregar ids ni clicks externos', function () {
    $link = makeUrlBuilderLink([
        'utm_source' => 'red social',
        'utm_medium' => 'pago/especial',
        'utm_campaign' => 'niñez & salud',
        'utm_content' => 'banner #1',
    ]);

    $url = campaignUrlBuilder()->fullUrl($link);

    expect($url)
        ->toContain('utm_source=red%20social')
        ->toContain('utm_medium=pago%2Fespecial')
        ->toContain('utm_campaign=ni%C3%B1ez%20%26%20salud')
        ->toContain('utm_content=banner%20%231')
        ->not->toContain('gclid')
        ->not->toContain('fbclid')
        ->not->toContain('id=')
        ->not->toContain('/'.$link->id);
});
