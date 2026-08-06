<?php

use App\Models\MarketingCampaignLink;
use App\Models\MarketingCampaignLinkAlias;
use App\Services\Marketing\MarketingCampaignLinkSlugService;
use Illuminate\Validation\ValidationException;

require_once __DIR__.'/marketingCampaignIsolatedSchema.php';

beforeEach(function () {
    bootstrapIsolatedMarketingCampaignSchema();
});

afterEach(function () {
    tearDownIsolatedMarketingCampaignSchema();
});

test('normaliza slugs de campaña', function () {
    $service = app(MarketingCampaignLinkSlugService::class);

    expect($service->normalize('  Campaña   MÉDICA -- 2026  '))
        ->toBe('campana-medica-2026');
});

test('detecta colisión con slug principal incluso si fue eliminado lógicamente', function () {
    $link = MarketingCampaignLink::factory()->create(['slug' => 'slug-reservado']);
    $link->delete();

    app(MarketingCampaignLinkSlugService::class)->assertAvailable('slug reservado');
})->throws(ValidationException::class);

test('detecta colisión con alias histórico', function () {
    MarketingCampaignLinkAlias::factory()->create(['slug' => 'alias-reservado']);

    app(MarketingCampaignLinkSlugService::class)->assertAvailable('alias reservado');
})->throws(ValidationException::class);

test('reserva slug principal tras soft delete y no permite reutilizarlo', function () {
    $link = MarketingCampaignLink::factory()->create(['slug' => 'slug-historico']);
    $link->delete();

    expect(fn () => app(MarketingCampaignLinkSlugService::class)->assertAvailable('slug-historico'))
        ->toThrow(ValidationException::class);
});

test('cambia el slug y conserva el anterior como alias', function () {
    $link = MarketingCampaignLink::factory()->create(['slug' => 'slug-anterior']);

    $updated = app(MarketingCampaignLinkSlugService::class)
        ->changeSlug($link, ' Nuevo Slug ');

    expect($updated->slug)->toBe('nuevo-slug')
        ->and($updated->aliases)->toHaveCount(1)
        ->and($updated->aliases->first()->slug)->toBe('slug-anterior');
});

test('mantiene el enlace sin crear alias si el slug normalizado no cambió', function () {
    $link = MarketingCampaignLink::factory()->create(['slug' => 'mismo-slug']);

    $updated = app(MarketingCampaignLinkSlugService::class)
        ->changeSlug($link, 'Mismo Slug');

    expect($updated->slug)->toBe('mismo-slug')
        ->and($link->aliases()->count())->toBe(0);
});

test('revierte el alias si falla la actualización del enlace', function () {
    $link = MarketingCampaignLink::factory()->create(['slug' => 'slug-original']);

    MarketingCampaignLink::updating(function () {
        throw new RuntimeException('Fallo simulado');
    });

    try {
        app(MarketingCampaignLinkSlugService::class)
            ->changeSlug($link, 'slug-nuevo');

        $this->fail('La actualización debía fallar.');
    } catch (RuntimeException $exception) {
        expect($exception->getMessage())->toBe('Fallo simulado');
    } finally {
        MarketingCampaignLink::flushEventListeners();
    }

    expect($link->fresh()->slug)->toBe('slug-original')
        ->and(MarketingCampaignLinkAlias::query()->count())->toBe(0);
});
