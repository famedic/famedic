<?php

use App\Enums\LaboratoryBrand;
use App\Enums\MarketingCampaignLinkPublicAvailability;
use App\Enums\MarketingCampaignLinkStatus;
use App\Enums\MarketingCampaignStatus;
use App\Enums\MarketingCampaignTargetType;
use App\Models\MarketingCampaign;
use App\Models\MarketingCampaignLink;
use App\Services\Marketing\MarketingCampaignLinkAvailabilityService;

require_once __DIR__.'/marketingCampaignIsolatedSchema.php';

beforeEach(function () {
    bootstrapIsolatedMarketingCampaignSchema();
});

afterEach(function () {
    tearDownIsolatedMarketingCampaignSchema();
});

function makePublicLink(array $campaignAttrs = [], array $linkAttrs = []): MarketingCampaignLink
{
    $campaign = MarketingCampaign::factory()->create(array_merge([
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

function availability(): MarketingCampaignLinkAvailabilityService
{
    return app(MarketingCampaignLinkAvailabilityService::class);
}

it('marca campaign draft como not found', function () {
    $link = makePublicLink(['status' => MarketingCampaignStatus::Draft]);
    expect(availability()->evaluate($link))->toBe(MarketingCampaignLinkPublicAvailability::NotFound);
});

it('marca scheduled futura como not started', function () {
    $link = makePublicLink([
        'status' => MarketingCampaignStatus::Scheduled,
        'starts_at' => now()->addDay(),
    ]);
    expect(availability()->evaluate($link))->toBe(MarketingCampaignLinkPublicAvailability::NotStarted);
});

it('permite scheduled ya iniciada', function () {
    $link = makePublicLink([
        'status' => MarketingCampaignStatus::Scheduled,
        'starts_at' => now()->subHour(),
        'ends_at' => now()->addDay(),
    ]);
    expect(availability()->evaluate($link))->toBe(MarketingCampaignLinkPublicAvailability::Available);
});

it('permite campaign active', function () {
    $link = makePublicLink(['status' => MarketingCampaignStatus::Active]);
    expect(availability()->evaluate($link))->toBe(MarketingCampaignLinkPublicAvailability::Available);
});

it('marca paused', function () {
    $link = makePublicLink(['status' => MarketingCampaignStatus::Paused]);
    expect(availability()->evaluate($link))->toBe(MarketingCampaignLinkPublicAvailability::Paused);
});

it('marca finished como expired', function () {
    $link = makePublicLink(['status' => MarketingCampaignStatus::Finished]);
    expect(availability()->evaluate($link))->toBe(MarketingCampaignLinkPublicAvailability::Expired);
});

it('marca archived', function () {
    $link = makePublicLink(['status' => MarketingCampaignStatus::Archived]);
    expect(availability()->evaluate($link))->toBe(MarketingCampaignLinkPublicAvailability::Archived);
});

it('marca starts_at futuro de campaign', function () {
    $link = makePublicLink([
        'status' => MarketingCampaignStatus::Active,
        'starts_at' => now()->addHour(),
    ]);
    expect(availability()->evaluate($link))->toBe(MarketingCampaignLinkPublicAvailability::NotStarted);
});

it('marca ends_at pasado de campaign', function () {
    $link = makePublicLink([
        'status' => MarketingCampaignStatus::Active,
        'ends_at' => now()->subMinute(),
    ]);
    expect(availability()->evaluate($link))->toBe(MarketingCampaignLinkPublicAvailability::Expired);
});

it('marca link draft como not found', function () {
    $link = makePublicLink([], ['status' => MarketingCampaignLinkStatus::Draft]);
    expect(availability()->evaluate($link))->toBe(MarketingCampaignLinkPublicAvailability::NotFound);
});

it('marca link paused', function () {
    $link = makePublicLink([], ['status' => MarketingCampaignLinkStatus::Paused]);
    expect(availability()->evaluate($link))->toBe(MarketingCampaignLinkPublicAvailability::Paused);
});

it('marca link archived', function () {
    $link = makePublicLink([], ['status' => MarketingCampaignLinkStatus::Archived]);
    expect(availability()->evaluate($link))->toBe(MarketingCampaignLinkPublicAvailability::Archived);
});

it('marca link futuro', function () {
    $link = makePublicLink([], ['starts_at' => now()->addDay()]);
    expect(availability()->evaluate($link))->toBe(MarketingCampaignLinkPublicAvailability::NotStarted);
});

it('marca link vencido', function () {
    $link = makePublicLink([], ['ends_at' => now()->subDay()]);
    expect(availability()->evaluate($link))->toBe(MarketingCampaignLinkPublicAvailability::Expired);
});

it('marca soft-deleted como not found', function () {
    $link = makePublicLink();
    $link->delete();
    $trashed = MarketingCampaignLink::withTrashed()->findOrFail($link->id);
    expect(availability()->evaluate($trashed))->toBe(MarketingCampaignLinkPublicAvailability::NotFound);
});

it('marca brand invalida como invalid target', function () {
    $link = makePublicLink([], [
        'target_payload' => ['brand' => 'no-existe'],
    ]);
    expect(availability()->evaluate($link))->toBe(MarketingCampaignLinkPublicAvailability::InvalidTarget);
});
