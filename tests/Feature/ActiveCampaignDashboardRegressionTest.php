<?php

use App\DataTransferObjects\ActiveCampaign\Operations\OperationsPlatformDto;
use App\Models\ActiveCampaignDispatch;
use App\Models\User;
use App\Services\ActiveCampaign\ActiveCampaignDashboardService;
use App\Services\ActiveCampaign\ActiveCampaignOperationsPlatformService;
use App\Support\ActiveCampaign\ActiveCampaignDashboardFilter;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Spatie\Permission\Models\Permission;

function activeCampaignDashboardAdmin(): User
{
    Permission::findOrCreate('activecampaign.manage', 'web');

    $user = User::factory()->withAdministrator()->create();
    $user->administrator->givePermissionTo('activecampaign.manage');

    return $user;
}

function activeCampaignDispatchRow(string $status, ?string $syncedAt = null, string $suffix = ''): ActiveCampaignDispatch
{
    return ActiveCampaignDispatch::query()->create([
        'event_type' => 'cart_abandoned_tag_add',
        'entity_type' => 'cart',
        'entity_id' => random_int(1000, 9999),
        'idempotency_key' => 'ac-dashboard-regression:'.$status.':'.$suffix.':'.uniqid(),
        'status' => $status,
        'email' => 'patient@example.com',
        'synced_at' => $syncedAt,
        'created_at' => now()->subMinutes(10),
        'updated_at' => now()->subMinutes(5),
    ]);
}

it('maps pending dispatch rows with null synced_at without error', function () {
    activeCampaignDispatchRow(ActiveCampaignDispatch::STATUS_PENDING);

    $filter = ActiveCampaignDashboardFilter::fromRequest(Request::create('/admin/activecampaign', 'GET'));
    Cache::forget($filter->cacheKey('overview'));

    $overview = app(ActiveCampaignDashboardService::class)->buildOverview($filter);
    $row = collect($overview['tables']['recent_activity'])->first();

    expect($row)->not->toBeNull()
        ->and($row['status'])->toBe(ActiveCampaignDispatch::STATUS_PENDING)
        ->and($row['synced_at'])->toBe('—');
});

it('maps failed dispatch rows with null synced_at without error', function () {
    activeCampaignDispatchRow(ActiveCampaignDispatch::STATUS_FAILED);

    $filter = ActiveCampaignDashboardFilter::fromRequest(Request::create('/admin/activecampaign', 'GET'));
    Cache::forget($filter->cacheKey('overview'));

    $overview = app(ActiveCampaignDashboardService::class)->buildOverview($filter);
    $failed = collect($overview['tables']['recent_errors'])->first();

    expect($failed)->not->toBeNull()
        ->and($failed['status'])->toBe(ActiveCampaignDispatch::STATUS_FAILED)
        ->and($failed['synced_at'])->toBe('—');
});

it('maps skipped dispatch rows with null synced_at without error', function () {
    activeCampaignDispatchRow(ActiveCampaignDispatch::STATUS_SKIPPED);

    $filter = ActiveCampaignDashboardFilter::fromRequest(Request::create('/admin/activecampaign', 'GET'));
    Cache::forget($filter->cacheKey('overview'));

    $overview = app(ActiveCampaignDashboardService::class)->buildOverview($filter);
    $row = collect($overview['tables']['recent_activity'])->first();

    expect($row)->not->toBeNull()
        ->and($row['status'])->toBe(ActiveCampaignDispatch::STATUS_SKIPPED)
        ->and($row['synced_at'])->toBe('—');
});

it('maps synced dispatch rows with a formatted synced_at', function () {
    $syncedAt = now()->subHour();
    activeCampaignDispatchRow(ActiveCampaignDispatch::STATUS_SYNCED, $syncedAt->toDateTimeString(), 'synced');

    $filter = ActiveCampaignDashboardFilter::fromRequest(Request::create('/admin/activecampaign', 'GET'));
    Cache::forget($filter->cacheKey('overview'));

    $overview = app(ActiveCampaignDashboardService::class)->buildOverview($filter);
    $row = collect($overview['tables']['recent_activity'])->first();

    expect($row)->not->toBeNull()
        ->and($row['status'])->toBe(ActiveCampaignDispatch::STATUS_SYNCED)
        ->and($row['synced_at'])->toBe($syncedAt->timezone('America/Monterrey')->format('d/m/Y H:i'));
});

it('returns 200 for integrations activecampaign page', function () {
    $admin = activeCampaignDashboardAdmin();

    activeCampaignDispatchRow(ActiveCampaignDispatch::STATUS_PENDING, null, 'integrations');

    $this->mock(ActiveCampaignOperationsPlatformService::class, function ($mock) {
        $mock->shouldReceive('build')->andReturn(new OperationsPlatformDto(
            executive: [],
            funnel: [],
            laboratories: [],
            memberships: [],
            purchases: [],
            automations: [],
            contactHealth: [],
            alerts: [],
            analytics: [],
            filters: [],
        ));
    });

    $this->actingAs($admin)
        ->get(route('admin.integrations.activecampaign'))
        ->assertOk();
});

it('returns 200 for workspace activecampaign page', function () {
    $admin = activeCampaignDashboardAdmin();

    activeCampaignDispatchRow(ActiveCampaignDispatch::STATUS_FAILED, null, 'workspace');

    $this->actingAs($admin)
        ->get(route('admin.workspace.activecampaign'))
        ->assertOk();
});

it('keeps appointment-first cart event type names stable for phase five', function () {
    expect(\App\Enums\CartEventType::AppointmentRequested->value)->toBe('appointment_requested')
        ->and(\App\Enums\CartEventType::AppointmentPending5m->value)->toBe('appointment_pending_5m')
        ->and(\App\Enums\CartEventType::AppointmentConfirmed->value)->toBe('appointment_confirmed')
        ->and(\App\Enums\CartEventType::PaymentMethodSelected->value)->toBe('payment_method_selected')
        ->and(\App\Enums\CartEventType::CartAbandoned->value)->toBe('cart_abandoned')
        ->and(\App\Enums\CartEventType::CartResumed->value)->toBe('cart_resumed')
        ->and(\App\Enums\CartEventType::CartRecovered->value)->toBe('cart_recovered')
        ->and(\App\Enums\CartEventType::CheckoutStarted->value)->toBe('checkout_started')
        ->and(\App\Enums\CartEventType::CheckoutVisited->value)->toBe('checkout_visited');
});
