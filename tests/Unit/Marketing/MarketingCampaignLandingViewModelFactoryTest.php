<?php

use App\Enums\LaboratoryBrand;
use App\Enums\MarketingCampaignHeroImageSource;
use App\Enums\MarketingCampaignLinkStatus;
use App\Enums\MarketingCampaignStatus;
use App\Enums\MarketingCampaignTargetType;
use App\Models\MarketingCampaign;
use App\Models\MarketingCampaignLink;
use App\Services\Marketing\MarketingCampaignLandingViewModelFactory;
use App\Services\Marketing\Targets\MarketingCampaignResolvedTarget;
use Illuminate\Support\Facades\Storage;

require_once __DIR__.'/marketingCampaignIsolatedSchema.php';

beforeEach(function () {
    bootstrapIsolatedMarketingCampaignSchema();
});

afterEach(function () {
    tearDownIsolatedMarketingCampaignSchema();
});

function makeLandingLink(array $campaignAttrs = [], array $linkAttrs = []): MarketingCampaignLink
{
    $campaign = MarketingCampaign::factory()->create(array_merge([
        'name' => 'Campaña base',
        'description' => 'Descripción de campaña',
        'status' => MarketingCampaignStatus::Active,
        'starts_at' => null,
        'ends_at' => null,
    ], $campaignAttrs));

    return MarketingCampaignLink::factory()->for($campaign, 'campaign')->create(array_merge([
        'status' => MarketingCampaignLinkStatus::Active,
        'target_type' => MarketingCampaignTargetType::Brand,
        'target_payload' => ['brand' => LaboratoryBrand::OLAB->value],
        'starts_at' => null,
        'ends_at' => null,
    ], $linkAttrs))->load('campaign');
}

function brandResolvedTarget(array $overrides = []): MarketingCampaignResolvedTarget
{
    return new MarketingCampaignResolvedTarget(
        type: $overrides['type'] ?? MarketingCampaignTargetType::Brand,
        brand: $overrides['brand'] ?? [
            'value' => LaboratoryBrand::OLAB->value,
            'label' => LaboratoryBrand::OLAB->label(),
            'logo_url' => '/images/gda/'.LaboratoryBrand::OLAB->imageSrc(),
            'states' => LaboratoryBrand::OLAB->states(),
            'catalog_url' => route('laboratory-tests', [
                'laboratory_brand' => LaboratoryBrand::OLAB->value,
            ]),
            'stores_url' => route('laboratory-stores.index', [
                'brand' => LaboratoryBrand::OLAB->value,
            ]),
        ],
        category: $overrides['category'] ?? null,
        products: $overrides['products'] ?? [],
        primaryDestinationUrl: $overrides['primaryDestinationUrl'] ?? route('laboratory-tests', [
            'laboratory_brand' => LaboratoryBrand::OLAB->value,
        ]),
        secondaryDestinationUrl: $overrides['secondaryDestinationUrl'] ?? route('laboratory-brand-selection'),
        sourceTitle: array_key_exists('sourceTitle', $overrides)
            ? $overrides['sourceTitle']
            : LaboratoryBrand::OLAB->label(),
        sourceDescription: array_key_exists('sourceDescription', $overrides)
            ? $overrides['sourceDescription']
            : null,
    );
}

function landingFactory(): MarketingCampaignLandingViewModelFactory
{
    return app(MarketingCampaignLandingViewModelFactory::class);
}

it('aplica fallbacks de titulo subtitulo descripcion y eyebrow', function () {
    $link = makeLandingLink();
    $resolved = brandResolvedTarget([
        'sourceTitle' => 'Título desde target',
        'sourceDescription' => 'Desc desde target',
        'category' => ['name' => 'Chequeos'],
    ]);

    $view = landingFactory()->make($link->campaign, $link, $resolved);

    expect($view['content']['title'])->toBe('Título desde target')
        ->and($view['content']['subtitle'])->toBe(LaboratoryBrand::OLAB->label().' · Chequeos')
        ->and($view['content']['description'])->toBe('Desc desde target')
        ->and($view['content']['eyebrow'])->toBe('Campaña Famedic');
});

it('prioriza overrides del enlace sobre target y campaña', function () {
    $link = makeLandingLink([], [
        'public_title' => 'Override título',
        'public_subtitle' => 'Override subtítulo',
        'public_description' => 'Override descripción',
        'eyebrow' => 'Eyebrow custom',
        'primary_cta_label' => 'CTA custom',
        'secondary_cta_label' => 'Secundario custom',
    ]);

    $resolved = brandResolvedTarget([
        'sourceTitle' => 'Target title',
        'sourceDescription' => 'Target desc',
    ]);

    $view = landingFactory()->make($link->campaign, $link, $resolved);

    expect($view['content']['title'])->toBe('Override título')
        ->and($view['content']['subtitle'])->toBe('Override subtítulo')
        ->and($view['content']['description'])->toBe('Override descripción')
        ->and($view['content']['eyebrow'])->toBe('Eyebrow custom')
        ->and($view['primary_action']['label'])->toBe('CTA custom')
        ->and($view['secondary_action']['label'])->toBe('Secundario custom');
});

it('usa campaign.name cuando no hay titulo de link ni target', function () {
    $link = makeLandingLink(['name' => 'Solo campaña']);
    $resolved = brandResolvedTarget([
        'sourceTitle' => null,
        'sourceDescription' => null,
    ]);

    $view = landingFactory()->make($link->campaign, $link, $resolved);

    expect($view['content']['title'])->toBe('Solo campaña')
        ->and($view['content']['description'])->toBe('Descripción de campaña');
});

it('elige etiquetas CTA por target_type', function (MarketingCampaignTargetType $type, string $expectedLabel) {
    $link = makeLandingLink([], [
        'target_type' => $type,
    ]);

    $resolved = brandResolvedTarget([
        'type' => $type,
        'category' => $type === MarketingCampaignTargetType::Category
            ? ['name' => 'Chequeos']
            : null,
        'sourceTitle' => 'Fuente',
    ]);

    $view = landingFactory()->make($link->campaign, $link, $resolved);

    expect($view['primary_action']['label'])->toBe($expectedLabel);
})->with([
    [MarketingCampaignTargetType::Brand, 'Ver todos los estudios'],
    [MarketingCampaignTargetType::Category, 'Ver estudios de esta categoría'],
    [MarketingCampaignTargetType::Product, 'Ver estudio'],
    [MarketingCampaignTargetType::Collection, 'Explorar estudios disponibles'],
]);

it('refleja flags show_prices show_brand_logo y show_campaign_dates', function () {
    $link = makeLandingLink([], [
        'show_prices' => false,
        'show_brand_logo' => false,
        'show_campaign_dates' => true,
    ]);

    $view = landingFactory()->make($link->campaign, $link, brandResolvedTarget());

    expect($view['content']['show_prices'])->toBeFalse()
        ->and($view['content']['show_brand_logo'])->toBeFalse()
        ->and($view['content']['show_campaign_dates'])->toBeTrue();
});

it('resuelve hero segun hero_image_source', function () {
    Storage::fake('public');

    $none = makeLandingLink([], [
        'hero_image_source' => MarketingCampaignHeroImageSource::None,
        'hero_image_path' => 'https://evil.com/x.jpg',
    ]);

    $upload = makeLandingLink([], [
        'hero_image_source' => MarketingCampaignHeroImageSource::Upload,
        'hero_image_disk' => 'public',
        'hero_image_path' => 'images/campaigns/hero.jpg',
    ]);

    $external = makeLandingLink([], [
        'hero_image_source' => MarketingCampaignHeroImageSource::External,
        'hero_image_url' => 'https://cdn.example.com/hero.jpg',
    ]);

    $noneView = landingFactory()->make($none->campaign, $none, brandResolvedTarget());
    $uploadView = landingFactory()->make($upload->campaign, $upload, brandResolvedTarget());
    $externalView = landingFactory()->make($external->campaign, $external, brandResolvedTarget());

    expect($noneView['content']['hero_image'])->toBeNull()
        ->and($uploadView['content']['hero_image'])->toBe(Storage::disk('public')->url('images/campaigns/hero.jpg'))
        ->and($externalView['content']['hero_image'])->toBe('https://cdn.example.com/hero.jpg');
});

it('expone logo_url root relative para marcas disponibles', function (LaboratoryBrand $brand) {
    $link = makeLandingLink([], [
        'target_type' => MarketingCampaignTargetType::Brand,
        'target_payload' => ['brand' => $brand->value],
    ]);

    $resolved = brandResolvedTarget([
        'brand' => app(\App\Services\Marketing\MarketingCampaignBrandPresenter::class)->present($brand),
    ]);

    $view = landingFactory()->make($link->campaign, $link, $resolved);

    expect($view['brand']['logo_url'])->toBe('/images/gda/'.$brand->imageSrc())
        ->and($view['brand']['catalog_url'])->toBe(route('laboratory-tests', [
            'laboratory_brand' => $brand->value,
        ]));
})->with(array_values(LaboratoryBrand::cases()));

it('devuelve payload slim sin created_by ni campos internos', function () {
    $link = makeLandingLink();
    $view = landingFactory()->make($link->campaign, $link, brandResolvedTarget([
        'products' => [[
            'id' => 1,
            'name' => 'Producto',
        ]],
    ]));

    expect($view)->toHaveKeys([
        'campaign',
        'link',
        'content',
        'brand',
        'category',
        'products',
        'related_products',
        'related_categories',
        'stores_url',
        'cart',
        'can_add_to_cart',
        'primary_action',
        'secondary_action',
        'empty_message',
    ])
        ->and($view)->not->toHaveKey('created_by')
        ->and($view)->not->toHaveKey('updated_by')
        ->and($view)->not->toHaveKey('target_payload')
        ->and($view['campaign'])->toHaveKeys(['name', 'starts_at', 'ends_at'])
        ->and($view['campaign'])->not->toHaveKey('created_by')
        ->and($view['link'])->toHaveKeys(['slug', 'target_type'])
        ->and($view['link'])->not->toHaveKey('created_by')
        ->and($view['empty_message'])->toBe('No hay estudios disponibles en esta campaña por el momento.');
});
