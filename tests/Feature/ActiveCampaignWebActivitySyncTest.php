<?php

use App\Models\ActiveCampaignWebActivity;
use App\Models\Cart;
use App\Models\Customer;
use App\Models\User;
use App\Enums\MonitoringCartStatus;
use App\Enums\MonitoringCartType;
use App\Services\ActiveCampaign\ActiveCampaignWebActivitySyncService;
use Illuminate\Support\Carbon;

function webActivitySyncCustomer(): Customer
{
    $user = User::factory()->create();

    return Customer::factory()->withRegularAccount()->create(['user_id' => $user->id]);
}

function trackingLogPayload(array $overrides = []): array
{
    return array_merge([
        'id' => 'activity-1',
        'tstamp' => now()->subMinutes(5)->toIso8601String(),
        'reference_type' => 'TrackingLog',
        'reference_id' => 'track-1',
        'jsonData' => [
            'url' => 'https://famedic.com.mx/laboratory/olab/checkout?brand=olab#x',
            'title' => 'Checkout laboratorio',
        ],
    ], $overrides);
}

it('persists valid TrackingLog activities safely and idempotently', function () {
    $customer = webActivitySyncCustomer();
    $service = app(ActiveCampaignWebActivitySyncService::class);

    $service->syncForCustomer($customer, 316, [trackingLogPayload()]);
    $service->syncForCustomer($customer, 316, [trackingLogPayload()]);

    expect(ActiveCampaignWebActivity::query()->count())->toBe(1);

    $activity = ActiveCampaignWebActivity::query()->first();
    expect($activity->customer_id)->toBe($customer->id)
        ->and($activity->ac_contact_id)->toBe(316)
        ->and($activity->path)->toBe('/laboratory/olab/checkout')
        ->and($activity->label)->toBe('Checkout')
        ->and($activity->title)->toBe('Checkout laboratorio');
});

it('does nothing when there are no valid TrackingLog activities', function () {
    $customer = webActivitySyncCustomer();

    app(ActiveCampaignWebActivitySyncService::class)->syncForCustomer($customer, 316, [
        trackingLogPayload(['reference_type' => 'Log']),
    ]);

    expect(ActiveCampaignWebActivity::query()->count())->toBe(0);
});

it('does not delete previous rows when incoming activities are ignored', function () {
    $customer = webActivitySyncCustomer();
    ActiveCampaignWebActivity::query()->create([
        'customer_id' => $customer->id,
        'ac_contact_id' => 316,
        'path' => '/laboratories',
        'label' => 'Catalogo de laboratorios',
        'occurred_at' => now()->subMinutes(10),
        'source' => 'activecampaign_site_tracking',
        'raw_reference_type' => 'TrackingLog',
        'raw_reference_id' => 'existing',
        'activity_hash' => hash('sha256', 'existing'),
    ]);

    app(ActiveCampaignWebActivitySyncService::class)->syncForCustomer($customer, 316, [
        trackingLogPayload(['reference_type' => 'Log']),
    ]);

    expect(ActiveCampaignWebActivity::query()->count())->toBe(1);
});

it('prunes activity older than 60 days', function () {
    $customer = webActivitySyncCustomer();
    ActiveCampaignWebActivity::query()->create([
        'customer_id' => $customer->id,
        'ac_contact_id' => 316,
        'path' => '/laboratories',
        'label' => 'Catalogo de laboratorios',
        'occurred_at' => now()->subDays(61),
        'source' => 'activecampaign_site_tracking',
        'raw_reference_type' => 'TrackingLog',
        'raw_reference_id' => 'old',
        'activity_hash' => hash('sha256', 'old'),
    ]);

    app(ActiveCampaignWebActivitySyncService::class)->syncForCustomer($customer, 316, [
        trackingLogPayload(['reference_id' => 'fresh']),
    ]);

    expect(ActiveCampaignWebActivity::query()->pluck('raw_reference_id')->all())->toBe(['fresh']);
});

it('returns cart-correlated activity by customer window, capped and chronological', function () {
    $customer = webActivitySyncCustomer();
    $otherCustomer = webActivitySyncCustomer();
    $cart = Cart::query()->create([
        'user_id' => $customer->user_id,
        'type' => MonitoringCartType::Lab->value,
        'status' => MonitoringCartStatus::Active->value,
        'total' => 1000.00,
        'created_at' => Carbon::parse('2026-08-21 14:00:00'),
        'updated_at' => Carbon::parse('2026-08-21 14:20:00'),
    ]);

    foreach (range(1, 12) as $index) {
        ActiveCampaignWebActivity::query()->create([
            'customer_id' => $customer->id,
            'ac_contact_id' => 316,
            'path' => '/laboratories/'.$index,
            'label' => 'Pagina visitada',
            'occurred_at' => Carbon::parse('2026-08-21 14:00:00')->addMinutes($index),
            'source' => 'activecampaign_site_tracking',
            'raw_reference_type' => 'TrackingLog',
            'raw_reference_id' => 'in-'.$index,
            'activity_hash' => hash('sha256', 'in-'.$index),
        ]);
    }

    ActiveCampaignWebActivity::query()->create([
        'customer_id' => $customer->id,
        'ac_contact_id' => 316,
        'path' => '/laboratories/before',
        'label' => 'Pagina visitada',
        'occurred_at' => Carbon::parse('2026-08-21 13:40:00'),
        'source' => 'activecampaign_site_tracking',
        'raw_reference_type' => 'TrackingLog',
        'raw_reference_id' => 'before',
        'activity_hash' => hash('sha256', 'before'),
    ]);
    ActiveCampaignWebActivity::query()->create([
        'customer_id' => $otherCustomer->id,
        'ac_contact_id' => 999,
        'path' => '/laboratories/other',
        'label' => 'Pagina visitada',
        'occurred_at' => Carbon::parse('2026-08-21 14:02:00'),
        'source' => 'activecampaign_site_tracking',
        'raw_reference_type' => 'TrackingLog',
        'raw_reference_id' => 'other',
        'activity_hash' => hash('sha256', 'other'),
    ]);

    $items = app(ActiveCampaignWebActivitySyncService::class)->forCart($cart, 10);

    expect($items)->toHaveCount(10)
        ->and($items->first()->raw_reference_id)->toBe('in-1')
        ->and($items->last()->raw_reference_id)->toBe('in-10')
        ->and($items->pluck('raw_reference_id')->contains('before'))->toBeFalse()
        ->and($items->pluck('raw_reference_id')->contains('other'))->toBeFalse();
});
