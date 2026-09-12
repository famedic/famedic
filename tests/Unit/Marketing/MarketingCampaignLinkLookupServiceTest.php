<?php

use App\Models\MarketingCampaignLink;
use App\Models\MarketingCampaignLinkAlias;
use App\Services\Marketing\MarketingCampaignLinkLookupService;

require_once __DIR__.'/marketingCampaignIsolatedSchema.php';

beforeEach(function () {
    bootstrapIsolatedMarketingCampaignSchema();
});

afterEach(function () {
    tearDownIsolatedMarketingCampaignSchema();
});

it('encuentra slug principal', function () {
    $link = MarketingCampaignLink::factory()->create(['slug' => 'promo-verano']);

    $result = app(MarketingCampaignLinkLookupService::class)->find('promo-verano');

    expect($result->found())->toBeTrue()
        ->and($result->wasAlias)->toBeFalse()
        ->and($result->canonicalSlug)->toBe('promo-verano')
        ->and($result->link?->is($link))->toBeTrue();
});

it('encuentra alias historico', function () {
    $link = MarketingCampaignLink::factory()->create(['slug' => 'slug-actual']);
    MarketingCampaignLinkAlias::factory()->create([
        'marketing_campaign_link_id' => $link->id,
        'slug' => 'slug-anterior',
    ]);

    $result = app(MarketingCampaignLinkLookupService::class)->find('slug-anterior');

    expect($result->found())->toBeTrue()
        ->and($result->wasAlias)->toBeTrue()
        ->and($result->canonicalSlug)->toBe('slug-actual')
        ->and($result->requestedSlug)->toBe('slug-anterior');
});

it('devuelve no encontrado para slug inexistente', function () {
    $result = app(MarketingCampaignLinkLookupService::class)->find('no-existe');

    expect($result->found())->toBeFalse()
        ->and($result->link)->toBeNull();
});

it('incluye soft-deleted para identificar indisponibilidad', function () {
    $link = MarketingCampaignLink::factory()->create(['slug' => 'borrado']);
    $link->delete();

    $result = app(MarketingCampaignLinkLookupService::class)->find('borrado');

    expect($result->found())->toBeTrue()
        ->and($result->link?->trashed())->toBeTrue();
});
