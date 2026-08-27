<?php

use App\Actions\Laboratories\AddItemToCartAction;
use App\Actions\Laboratories\DeleteItemFromCartAction;
use App\Enums\CartEventType;
use App\Enums\LaboratoryBrand;
use App\Enums\MonitoringCartStatus;
use App\Enums\MonitoringCartType;
use App\Models\Cart;
use App\Models\LaboratoryCartItem;
use App\Models\LaboratoryTest;
use App\Models\User;
use App\Services\Carts\CartAbandonmentService;
use App\Services\Monitoring\SyncMonitoringCartService;
use App\Support\ClientContext;
use Illuminate\Support\Facades\Artisan;
use Spatie\Permission\Models\Permission;

function abandonmentUser(): User
{
    return User::factory()->withRegularCustomer()->create();
}

function abandonmentCartWithItem(User $user, int $inactiveMinutes = 35, ?LaboratoryBrand $brand = null): Cart
{
    $brand ??= LaboratoryBrand::OLAB;
    $test = LaboratoryTest::factory()->create(['brand' => $brand->value]);

    LaboratoryCartItem::factory()->create([
        'customer_id' => $user->customer->id,
        'laboratory_test_id' => $test->id,
    ]);

    app(SyncMonitoringCartService::class)->syncLaboratory($user->customer);

    $cart = Cart::query()
        ->where('user_id', $user->id)
        ->where('type', MonitoringCartType::Lab)
        ->firstOrFail();

    $cart->update(['updated_at' => now()->subMinutes($inactiveMinutes)]);

    return $cart->fresh(['items']);
}

function abandonmentAdmin(): User
{
    Permission::findOrCreate('view cart details', 'web');
    $user = User::factory()->withAdministrator()->create();
    $user->administrator->givePermissionTo('view cart details');

    return $user;
}

beforeEach(function () {
    config(['carts.abandoned_after_minutes' => 30]);
});

it('does not record cart_abandoned when cart is active below threshold', function () {
    $cart = abandonmentCartWithItem(abandonmentUser(), inactiveMinutes: 10);

    Artisan::call('carts:detect-abandonment');

    expect($cart->events()->where('event', CartEventType::CartAbandoned->value)->exists())->toBeFalse();
});

it('records cart_abandoned episode 1 when cart exceeds inactivity threshold', function () {
    $cart = abandonmentCartWithItem(abandonmentUser(), inactiveMinutes: 45);

    Artisan::call('carts:detect-abandonment');

    $event = $cart->events()->where('event', CartEventType::CartAbandoned->value)->first();

    expect($event)->not->toBeNull()
        ->and($event->metadata['episode'])->toBe(1)
        ->and($event->metadata)->toHaveKeys(['last_activity_at', 'abandoned_at', 'minutes_inactive']);
});

it('records only one cart_abandoned when detect command runs twice', function () {
    $cart = abandonmentCartWithItem(abandonmentUser(), inactiveMinutes: 45);

    Artisan::call('carts:detect-abandonment');
    Artisan::call('carts:detect-abandonment');

    expect($cart->events()->where('event', CartEventType::CartAbandoned->value)->count())->toBe(1);
});

it('records cart_resumed episode 1 when user interacts after abandonment', function () {
    $user = abandonmentUser();
    $cart = abandonmentCartWithItem($user, inactiveMinutes: 45);
    app(CartAbandonmentService::class)->recordAbandoned($cart->fresh());

    $test = LaboratoryTest::factory()->create(['brand' => LaboratoryBrand::OLAB->value]);

    app(AddItemToCartAction::class)(
        $user->customer,
        $test->id,
        ClientContext::fromUserAgent('Mozilla/5.0 (Windows NT 10.0; Win64; x64) Chrome/120.0.0.0 Safari/537.36'),
    );

    $event = $cart->fresh()->events()->where('event', CartEventType::CartResumed->value)->first();

    expect($event)->not->toBeNull()
        ->and($event->metadata['episode'])->toBe(1)
        ->and($event->metadata)->toHaveKey('resumed_at');
});

it('records only one cart_resumed when resume detection runs twice', function () {
    $cart = abandonmentCartWithItem(abandonmentUser(), inactiveMinutes: 45);
    $service = app(CartAbandonmentService::class);
    $service->recordAbandoned($cart->fresh());

    $freshCart = $cart->fresh();
    $service->maybeRecordResumed($freshCart);
    $service->maybeRecordResumed($freshCart);

    expect($cart->fresh()->events()->where('event', CartEventType::CartResumed->value)->count())->toBe(1);
});

it('records cart_abandoned episode 2 after a resumed cycle', function () {
    $user = abandonmentUser();
    $cart = abandonmentCartWithItem($user, inactiveMinutes: 45);
    $service = app(CartAbandonmentService::class);
    $service->recordAbandoned($cart->fresh());

    $test = LaboratoryTest::factory()->create(['brand' => LaboratoryBrand::OLAB->value]);
    app(AddItemToCartAction::class)($user->customer, $test->id, ClientContext::fromUserAgent('Mozilla/5.0 Chrome/120.0.0.0'));

    $cart->fresh()->update(['updated_at' => now()->subMinutes(45)]);

    Artisan::call('carts:detect-abandonment');

    $episodes = $cart->fresh()->events()
        ->where('event', CartEventType::CartAbandoned->value)
        ->orderBy('id')
        ->pluck('metadata')
        ->map(fn (array $metadata) => $metadata['episode'] ?? null)
        ->all();

    expect($episodes)->toBe([1, 2]);
});

it('records cart_recovered when cart completes after abandonment', function () {
    $cart = abandonmentCartWithItem(abandonmentUser(), inactiveMinutes: 45);
    app(CartAbandonmentService::class)->recordAbandoned($cart->fresh());

    app(CartAbandonmentService::class)->recordRecoveredIfEligible(
        $cart->fresh(),
        purchaseId: 12345,
    );

    $event = $cart->fresh()->events()->where('event', CartEventType::CartRecovered->value)->first();

    expect($event)->not->toBeNull()
        ->and($event->metadata['purchase_id'])->toBe(12345)
        ->and($event->metadata['episodes_count'])->toBe(1);
});

it('does not record cart_recovered when cart was never abandoned', function () {
    $cart = abandonmentCartWithItem(abandonmentUser(), inactiveMinutes: 10);

    app(CartAbandonmentService::class)->recordRecoveredIfEligible($cart, purchaseId: 999);

    expect($cart->events()->where('event', CartEventType::CartRecovered->value)->exists())->toBeFalse();
});

it('does not record cart_abandoned for empty monitoring carts', function () {
    $user = abandonmentUser();
    $cart = Cart::query()->create([
        'user_id' => $user->id,
        'type' => MonitoringCartType::Lab,
        'status' => MonitoringCartStatus::Active,
        'total' => 0,
        'updated_at' => now()->subMinutes(45),
    ]);

    Artisan::call('carts:detect-abandonment');

    expect($cart->events()->where('event', CartEventType::CartAbandoned->value)->exists())->toBeFalse();
});

it('does not record cart_abandoned for completed carts', function () {
    $user = abandonmentUser();
    $cart = abandonmentCartWithItem($user, inactiveMinutes: 45);
    $cart->update([
        'status' => MonitoringCartStatus::Completed,
        'completed_at' => now(),
    ]);

    Artisan::call('carts:detect-abandonment');

    expect($cart->fresh()->events()->where('event', CartEventType::CartAbandoned->value)->exists())->toBeFalse();
});

it('does not resume an invalidated episode after abandoned emptied and re-add', function () {
    $user = abandonmentUser();
    $cart = abandonmentCartWithItem($user, inactiveMinutes: 45);
    $service = app(CartAbandonmentService::class);
    $service->recordAbandoned($cart->fresh());

    $item = $user->customer->laboratoryCartItems()->first();
    app(DeleteItemFromCartAction::class)($item, ClientContext::fromUserAgent('Mozilla/5.0 Chrome/120.0.0.0'));

    expect($cart->fresh()->isEmptyActiveMonitoringCart())->toBeTrue();

    $test = LaboratoryTest::factory()->create(['brand' => LaboratoryBrand::OLAB->value]);
    app(AddItemToCartAction::class)($user->customer, $test->id, ClientContext::fromUserAgent('Mozilla/5.0 Chrome/120.0.0.0'));

    expect($cart->fresh()->events()->where('event', CartEventType::CartResumed->value)->exists())->toBeFalse();
});

it('exposes abandonment episode events in admin cart timeline', function () {
    $user = abandonmentUser();
    $cart = abandonmentCartWithItem($user, inactiveMinutes: 45);
    $service = app(CartAbandonmentService::class);
    $service->recordAbandoned($cart->fresh());
    $service->maybeRecordResumed($cart->fresh(), ClientContext::fromUserAgent('Mozilla/5.0 Chrome/120.0.0.0'));
    $service->recordRecoveredIfEligible($cart->fresh(), purchaseId: 555);

    $response = $this->actingAs(abandonmentAdmin())
        ->getJson(route('admin.carts.show', ['cart' => $cart->id]))
        ->assertOk();

    $labels = collect($response->json('data.events'))->pluck('label')->all();

    expect($labels)->toContain('Carrito abandonado — episodio #1')
        ->and($labels)->toContain('Carrito retomado — episodio #1')
        ->and($labels)->toContain('Carrito recuperado');
});

it('records cart_recovered only once even if completion is retried', function () {
    $cart = abandonmentCartWithItem(abandonmentUser(), inactiveMinutes: 45);
    $service = app(CartAbandonmentService::class);
    $service->recordAbandoned($cart->fresh());
    $service->recordRecoveredIfEligible($cart->fresh(), purchaseId: 1);
    $service->recordRecoveredIfEligible($cart->fresh(), purchaseId: 1);

    expect($cart->fresh()->events()->where('event', CartEventType::CartRecovered->value)->count())->toBe(1);
});
