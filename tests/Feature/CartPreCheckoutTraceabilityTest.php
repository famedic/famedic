<?php

use App\Actions\Laboratories\AddItemToCartAction;
use App\Actions\Laboratories\DeleteItemFromCartAction;
use App\Actions\OnlinePharmacy\AddItemToCartAction as PharmacyAddItemToCartAction;
use App\Actions\OnlinePharmacy\DeleteItemFromCartAction as PharmacyDeleteItemFromCartAction;
use App\Actions\OnlinePharmacy\UpdateItemToCartAction;
use App\Enums\CartEventType;
use App\Enums\LaboratoryBrand;
use App\Enums\MonitoringCartType;
use App\Models\LaboratoryCartItem;
use App\Models\LaboratoryTest;
use App\Models\OnlinePharmacyCartItem;
use App\Models\User;
use App\Services\Carts\CartOperationalEventService;
use App\Support\ClientContext;
use Spatie\Permission\Models\Permission;

function preCheckoutTraceabilityUser(): User
{
    return User::factory()->withRegularCustomer()->create();
}

function preCheckoutDesktopContext(): array
{
    return ClientContext::fromUserAgent(
        'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36',
    );
}

function preCheckoutAdminWithCartDetailPermission(): User
{
    Permission::findOrCreate('view cart details', 'web');

    $user = User::factory()->withAdministrator()->create();
    $user->administrator->givePermissionTo('view cart details');

    return $user;
}

it('records cart_item_added when a laboratory cart item is created', function () {
    $user = preCheckoutTraceabilityUser();
    $test = LaboratoryTest::factory()->create(['brand' => LaboratoryBrand::OLAB->value]);

    app(AddItemToCartAction::class)(
        $user->customer,
        $test->id,
        preCheckoutDesktopContext(),
    );

    $cart = \App\Models\Cart::query()
        ->where('user_id', $user->id)
        ->where('type', MonitoringCartType::Lab)
        ->first();

    expect($cart)->not->toBeNull();

    $event = $cart->events()->where('event', CartEventType::CartItemAdded->value)->first();

    expect($event)->not->toBeNull()
        ->and($event->metadata['product_id'])->toBe((string) $test->id)
        ->and($event->metadata['client']['device_type'])->toBe('desktop')
        ->and($event->metadata['client']['browser'])->toBe('Chrome')
        ->and($event->metadata['client']['os'])->toBe('Windows');
});

it('records cart_item_removed when a laboratory cart item is deleted', function () {
    $user = preCheckoutTraceabilityUser();
    $test = LaboratoryTest::factory()->create(['brand' => LaboratoryBrand::OLAB->value]);
    $item = LaboratoryCartItem::factory()->create([
        'customer_id' => $user->customer->id,
        'laboratory_test_id' => $test->id,
    ]);
    app(\App\Services\Monitoring\SyncMonitoringCartService::class)->syncLaboratory($user->customer);

    app(DeleteItemFromCartAction::class)($item, preCheckoutDesktopContext());

    $cart = \App\Models\Cart::query()
        ->where('user_id', $user->id)
        ->where('type', MonitoringCartType::Lab)
        ->first();

    expect($cart)->not->toBeNull()
        ->and($cart->events()->where('event', CartEventType::CartItemRemoved->value)->exists())->toBeTrue();
});

it('records distinct cart_item_added events when the same study is added again after removal', function () {
    $user = preCheckoutTraceabilityUser();
    $test = LaboratoryTest::factory()->create(['brand' => LaboratoryBrand::OLAB->value]);

    $firstItem = app(AddItemToCartAction::class)($user->customer, $test->id, preCheckoutDesktopContext());
    app(DeleteItemFromCartAction::class)($firstItem, preCheckoutDesktopContext());

    $secondItem = app(AddItemToCartAction::class)($user->customer, $test->id, preCheckoutDesktopContext());

    $cart = \App\Models\Cart::query()
        ->where('user_id', $user->id)
        ->where('type', MonitoringCartType::Lab)
        ->first();

    $addedEvents = $cart->events()->where('event', CartEventType::CartItemAdded->value)->orderBy('id')->get();

    expect($addedEvents)->toHaveCount(2)
        ->and($addedEvents[0]->idempotency_key)->toBe("laboratory_cart_item:{$firstItem->id}:created")
        ->and($addedEvents[1]->idempotency_key)->toBe("laboratory_cart_item:{$secondItem->id}:created")
        ->and($firstItem->id)->not->toBe($secondItem->id);
});

it('records cart_emptied and preserves the monitoring cart when the last laboratory item is removed', function () {
    $user = preCheckoutTraceabilityUser();
    $test = LaboratoryTest::factory()->create(['brand' => LaboratoryBrand::OLAB->value]);
    $item = app(AddItemToCartAction::class)($user->customer, $test->id, preCheckoutDesktopContext());

    $cart = \App\Models\Cart::query()
        ->where('user_id', $user->id)
        ->where('type', MonitoringCartType::Lab)
        ->first();

    app(DeleteItemFromCartAction::class)($item, preCheckoutDesktopContext());

    $cart->refresh();

    expect($cart)->not->toBeNull()
        ->and((float) $cart->total)->toBe(0.0)
        ->and($cart->items()->count())->toBe(0)
        ->and($cart->events()->where('event', CartEventType::CartEmptied->value)->exists())->toBeTrue();
});

it('records pharmacy quantity changes with transition-based idempotency keys', function () {
    $user = preCheckoutTraceabilityUser();
    $item = app(PharmacyAddItemToCartAction::class)($user->customer, 424242, preCheckoutDesktopContext());

    app(UpdateItemToCartAction::class)($item, 3, preCheckoutDesktopContext());

    $cart = \App\Models\Cart::query()
        ->where('user_id', $user->id)
        ->where('type', MonitoringCartType::Pharmacy)
        ->first();

    $event = $cart->events()->where('event', CartEventType::CartItemQuantityChanged->value)->first();

    expect($event)->not->toBeNull()
        ->and($event->idempotency_key)->toStartWith("online_pharmacy_cart_item:{$item->id}:quantity_changed:")
        ->and($event->metadata['previous_quantity'])->toBe(1)
        ->and($event->metadata['quantity'])->toBe(3);
});

it('records separate quantity events for repeated 1 to 2 transitions', function () {
    $user = preCheckoutTraceabilityUser();
    $item = app(PharmacyAddItemToCartAction::class)($user->customer, 424243, preCheckoutDesktopContext());

    app(UpdateItemToCartAction::class)($item, 2, preCheckoutDesktopContext());
    app(UpdateItemToCartAction::class)($item->fresh(), 1, preCheckoutDesktopContext());
    app(UpdateItemToCartAction::class)($item->fresh(), 2, preCheckoutDesktopContext());

    $cart = \App\Models\Cart::query()
        ->where('user_id', $user->id)
        ->where('type', MonitoringCartType::Pharmacy)
        ->first();

    $events = $cart->events()
        ->where('event', CartEventType::CartItemQuantityChanged->value)
        ->orderBy('id')
        ->get();

    expect($events)->toHaveCount(3)
        ->and($events[0]->metadata['previous_quantity'])->toBe(1)
        ->and($events[0]->metadata['quantity'])->toBe(2)
        ->and($events[2]->metadata['previous_quantity'])->toBe(1)
        ->and($events[2]->metadata['quantity'])->toBe(2)
        ->and($events[0]->idempotency_key)->not->toBe($events[2]->idempotency_key);
});

it('keeps cart item events idempotent when recording twice for the same operational item', function () {
    $user = preCheckoutTraceabilityUser();
    $test = LaboratoryTest::factory()->create(['brand' => LaboratoryBrand::OLAB->value]);
    $item = app(AddItemToCartAction::class)($user->customer, $test->id, preCheckoutDesktopContext());

    $service = app(CartOperationalEventService::class);
    $service->recordLaboratoryItemAdded($item->load('laboratoryTest'), preCheckoutDesktopContext());
    $service->recordLaboratoryItemAdded($item->load('laboratoryTest'), preCheckoutDesktopContext());

    $cart = \App\Models\Cart::query()
        ->where('user_id', $user->id)
        ->where('type', MonitoringCartType::Lab)
        ->first();

    expect($cart->events()->where('event', CartEventType::CartItemAdded->value)->count())->toBe(1);
});

it('sanitizes sensitive metadata from cart item events', function () {
    $user = preCheckoutTraceabilityUser();
    $test = LaboratoryTest::factory()->create(['brand' => LaboratoryBrand::OLAB->value]);

    app(AddItemToCartAction::class)($user->customer, $test->id, preCheckoutDesktopContext());

    $cart = \App\Models\Cart::query()
        ->where('user_id', $user->id)
        ->where('type', MonitoringCartType::Lab)
        ->first();

    app(\App\Services\Carts\CartEventRecorder::class)->recordOnce(
        $cart,
        CartEventType::CartItemAdded,
        'test-sensitive-sanitize',
        [
            'product_id' => (string) $test->id,
            'card_token' => 'tok_test',
            'secret_key' => 'hidden',
            'client' => preCheckoutDesktopContext(),
        ],
        source: 'test',
    );

    $event = $cart->events()->where('idempotency_key', 'test-sensitive-sanitize')->first();

    expect($event->metadata)->toHaveKey('product_id')
        ->and($event->metadata)->toHaveKey('client')
        ->and($event->metadata)->not->toHaveKey('card_token')
        ->and($event->metadata)->not->toHaveKey('secret_key');
});

it('returns drawer session context from pre-checkout cart item events without checkout', function () {
    $admin = preCheckoutAdminWithCartDetailPermission();
    $customerUser = preCheckoutTraceabilityUser();
    $test = LaboratoryTest::factory()->create(['brand' => LaboratoryBrand::OLAB->value]);

    app(AddItemToCartAction::class)($customerUser->customer, $test->id, preCheckoutDesktopContext());

    $cart = \App\Models\Cart::query()
        ->where('user_id', $customerUser->id)
        ->where('type', MonitoringCartType::Lab)
        ->first();

    $response = $this->actingAs($admin)
        ->getJson(route('admin.carts.show', ['cart' => $cart->id]))
        ->assertOk()
        ->assertJsonPath('data.client_context.has_data', true)
        ->assertJsonPath('data.client_context.last_device.device_type', 'desktop')
        ->assertJsonPath('data.client_context.last_device.browser', 'Chrome')
        ->assertJsonPath('data.client_context.last_device.os', 'Windows');

    $eventTypes = collect($response->json('data.events'))->pluck('event')->all();

    expect($eventTypes)->toContain(CartEventType::CartCreated->value)
        ->and($eventTypes)->toContain(CartEventType::CartItemAdded->value);
});

it('excludes empty active carts from operational monitoring queries', function () {
    $user = preCheckoutTraceabilityUser();
    $test = LaboratoryTest::factory()->create(['brand' => LaboratoryBrand::OLAB->value]);
    $item = app(AddItemToCartAction::class)($user->customer, $test->id, preCheckoutDesktopContext());

    $cart = \App\Models\Cart::query()
        ->where('user_id', $user->id)
        ->where('type', MonitoringCartType::Lab)
        ->first();

    app(DeleteItemFromCartAction::class)($item, preCheckoutDesktopContext());

    expect($cart->refresh()->isEmptyActiveMonitoringCart())->toBeTrue()
        ->and($cart->displayStatus())->toBe('empty')
        ->and(\App\Models\Cart::query()->operationalMonitoring()->where('id', $cart->id)->exists())->toBeFalse()
        ->and(\App\Models\Cart::query()->where('id', $cart->id)->exists())->toBeTrue();
});

it('reuses the same monitoring cart id after emptying and re-adding', function () {
    $user = preCheckoutTraceabilityUser();
    $test = LaboratoryTest::factory()->create(['brand' => LaboratoryBrand::OLAB->value]);
    $item = app(AddItemToCartAction::class)($user->customer, $test->id, preCheckoutDesktopContext());

    $cart = \App\Models\Cart::query()
        ->where('user_id', $user->id)
        ->where('type', MonitoringCartType::Lab)
        ->first();

    $createdEventsBefore = $cart->events()->where('event', CartEventType::CartCreated->value)->count();

    app(DeleteItemFromCartAction::class)($item, preCheckoutDesktopContext());
    app(AddItemToCartAction::class)($user->customer, $test->id, preCheckoutDesktopContext());

    $cartAfter = \App\Models\Cart::query()
        ->where('user_id', $user->id)
        ->where('type', MonitoringCartType::Lab)
        ->whereHas('items')
        ->first();

    expect($cartAfter)->not->toBeNull()
        ->and($cartAfter->id)->toBe($cart->id)
        ->and($cartAfter->events()->where('event', CartEventType::CartCreated->value)->count())->toBe($createdEventsBefore);
});

it('does not resolve abandoned operational insight for empty monitoring carts', function () {
    $user = preCheckoutTraceabilityUser();
    $test = LaboratoryTest::factory()->create(['brand' => LaboratoryBrand::OLAB->value]);
    $item = app(AddItemToCartAction::class)($user->customer, $test->id, preCheckoutDesktopContext());

    $cart = \App\Models\Cart::query()
        ->where('user_id', $user->id)
        ->where('type', MonitoringCartType::Lab)
        ->first();

    app(DeleteItemFromCartAction::class)($item, preCheckoutDesktopContext());

    $cart->update(['updated_at' => now()->subHours(2)]);

    $insight = app(\App\Services\Carts\CartOperationalInsightResolver::class)->resolve($cart->fresh());

    expect($insight['reason'])->toBe('none')
        ->and($insight['requires_attention'])->toBeFalse();
});

it('records cart_emptied after cart_item_removed with preserved metadata', function () {
    $user = preCheckoutTraceabilityUser();
    $test = LaboratoryTest::factory()->create(['brand' => LaboratoryBrand::OLAB->value]);
    $item = app(AddItemToCartAction::class)($user->customer, $test->id, preCheckoutDesktopContext());

    $cart = \App\Models\Cart::query()
        ->where('user_id', $user->id)
        ->where('type', MonitoringCartType::Lab)
        ->first();

    app(DeleteItemFromCartAction::class)($item, preCheckoutDesktopContext());

    $removed = $cart->events()->where('event', CartEventType::CartItemRemoved->value)->first();
    $emptied = $cart->events()->where('event', CartEventType::CartEmptied->value)->first();

    expect($removed)->not->toBeNull()
        ->and($emptied)->not->toBeNull()
        ->and($removed->occurred_at->lte($emptied->occurred_at))->toBeTrue()
        ->and($emptied->cart_id)->toBe($cart->id)
        ->and($emptied->metadata['brand'])->toBe(LaboratoryBrand::OLAB->value)
        ->and($emptied->metadata['cart_total'])->toBe(0)
        ->and($emptied->metadata['client']['browser'])->toBe('Chrome');
});

it('records pharmacy cart_item_removed and cart_emptied when deleting the last product', function () {
    $user = preCheckoutTraceabilityUser();
    $item = app(PharmacyAddItemToCartAction::class)($user->customer, 987654, preCheckoutDesktopContext());

    app(PharmacyDeleteItemFromCartAction::class)($item, preCheckoutDesktopContext());

    $cart = \App\Models\Cart::query()
        ->where('user_id', $user->id)
        ->where('type', MonitoringCartType::Pharmacy)
        ->first();

    expect($cart)->not->toBeNull()
        ->and($cart->events()->where('event', CartEventType::CartItemRemoved->value)->exists())->toBeTrue()
        ->and($cart->events()->where('event', CartEventType::CartEmptied->value)->exists())->toBeTrue();
});
