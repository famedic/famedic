<?php

use App\Enums\LaboratoryBrand;
use App\Enums\MonitoringCartStatus;
use App\Enums\MonitoringCartType;
use App\Jobs\ProcessCartsSpreadsheetExport;
use App\Models\Cart;
use App\Models\CartItem;
use App\Models\LaboratoryAppointment;
use App\Models\LaboratoryCartItem;
use App\Models\LaboratoryPurchase;
use App\Models\LaboratoryTest;
use App\Models\User;
use App\Services\Monitoring\SyncMonitoringCartService;
use Illuminate\Support\Facades\Queue;
use Inertia\Testing\AssertableInertia as Assert;
use Spatie\Permission\Models\Permission;

function cartsUxAdmin(): User
{
    Permission::findOrCreate('view carts', 'web');

    $user = User::factory()->withAdministrator()->create();
    $user->administrator->givePermissionTo('view carts');

    return $user;
}

function cartsUxUser(): User
{
    return User::factory()->withRegularCustomer()->create();
}

function cartsUxCart(User $user, LaboratoryBrand $brand = LaboratoryBrand::OLAB, array $attributes = []): Cart
{
    $test = LaboratoryTest::factory()->create([
        'brand' => $brand->value,
        'requires_appointment' => true,
    ]);

    $cart = Cart::query()->create(array_merge([
        'user_id' => $user->id,
        'type' => MonitoringCartType::Lab->value,
        'status' => MonitoringCartStatus::Active->value,
        'total' => 1200.00,
        'created_at' => now()->subDays(2),
        'updated_at' => now()->subHours(2),
    ], $attributes));

    CartItem::query()->create([
        'cart_id' => $cart->id,
        'product_id' => (string) $test->id,
        'name' => 'Estudio',
        'price' => (float) $cart->total,
        'quantity' => 1,
    ]);

    return $cart;
}

function cartsUxPurchase(User $user, array $attributes = []): LaboratoryPurchase
{
    return LaboratoryPurchase::query()->create(array_merge([
        'brand' => LaboratoryBrand::OLAB->value,
        'gda_order_id' => uniqid('gda-ux-', true),
        'name' => 'Paciente',
        'paternal_lastname' => 'UX',
        'maternal_lastname' => 'Test',
        'phone' => '8111111111',
        'phone_country' => 'MX',
        'birth_date' => '1990-01-01',
        'gender' => null,
        'street' => 'Calle',
        'number' => '1',
        'neighborhood' => 'Centro',
        'state' => 'Nuevo Leon',
        'city' => 'Monterrey',
        'zipcode' => '64000',
        'total_cents' => 100000,
        'customer_id' => $user->customer->id,
        'created_at' => now()->subDays(10),
        'updated_at' => now()->subDays(10),
    ], $attributes));
}

function cartsUxSourceItem(User $user, LaboratoryBrand $brand, int $priceCents, string $name = 'Estudio'): LaboratoryTest
{
    $test = LaboratoryTest::factory()->create([
        'brand' => $brand->value,
        'name' => $name,
        'famedic_price_cents' => $priceCents,
        'requires_appointment' => true,
    ]);

    LaboratoryCartItem::withoutEvents(fn () => LaboratoryCartItem::query()->create([
        'customer_id' => $user->customer->id,
        'laboratory_test_id' => $test->id,
    ]));

    return $test;
}

function cartsUxLegacyLabCart(User $user, array $tests, array $attributes = []): Cart
{
    $cart = Cart::query()->create(array_merge([
        'user_id' => $user->id,
        'type' => MonitoringCartType::Lab->value,
        'status' => MonitoringCartStatus::Active->value,
        'total' => collect($tests)->sum(fn (LaboratoryTest $test) => numberCents($test->famedic_price_cents)),
        'created_at' => now()->subDays(2),
        'updated_at' => now()->subHours(2),
    ], $attributes));

    foreach ($tests as $test) {
        CartItem::query()->create([
            'cart_id' => $cart->id,
            'product_id' => (string) $test->id,
            'name' => $test->name,
            'price' => numberCents($test->famedic_price_cents),
            'quantity' => 1,
        ]);
    }

    return $cart;
}

it('syncs laboratory monitoring carts as one active cart per brand', function () {
    $user = cartsUxUser();
    $olab = LaboratoryTest::factory()->create(['brand' => LaboratoryBrand::OLAB->value, 'famedic_price_cents' => 120000]);
    $swisslab = LaboratoryTest::factory()->create(['brand' => LaboratoryBrand::SWISSLAB->value, 'famedic_price_cents' => 90000]);

    LaboratoryCartItem::withoutEvents(fn () => LaboratoryCartItem::query()->create([
        'customer_id' => $user->customer->id,
        'laboratory_test_id' => $olab->id,
    ]));
    LaboratoryCartItem::withoutEvents(fn () => LaboratoryCartItem::query()->create([
        'customer_id' => $user->customer->id,
        'laboratory_test_id' => $swisslab->id,
    ]));

    app(SyncMonitoringCartService::class)->syncLaboratory($user->customer);

    $carts = Cart::query()->with('items')->where('user_id', $user->id)->where('type', MonitoringCartType::Lab)->get();

    expect($carts)->toHaveCount(2)
        ->and($carts->map(fn (Cart $cart) => collect($cart->labBrands())->pluck('value')->all())->flatten()->sort()->values()->all())
        ->toBe([LaboratoryBrand::OLAB->value, LaboratoryBrand::SWISSLAB->value]);
});

it('keeps single-brand laboratory carts as one active cart per brand', function (LaboratoryBrand $brand) {
    $user = cartsUxUser();
    cartsUxSourceItem($user, $brand, 100000, 'Test A');

    app(SyncMonitoringCartService::class)->syncLaboratory($user->customer);

    $carts = Cart::query()
        ->with('items')
        ->where('user_id', $user->id)
        ->where('type', MonitoringCartType::Lab)
        ->where('status', MonitoringCartStatus::Active)
        ->get();

    expect($carts)->toHaveCount(1)
        ->and(collect($carts->first()->labBrands())->pluck('value')->all())->toBe([$brand->value])
        ->and((float) $carts->first()->total)->toBe(1000.00);
})->with([
    'olab' => [LaboratoryBrand::OLAB],
    'swisslab' => [LaboratoryBrand::SWISSLAB],
]);

it('reconciles a legacy mixed active lab cart into one active cart per brand', function () {
    $user = cartsUxUser();
    $olabA = cartsUxSourceItem($user, LaboratoryBrand::OLAB, 100000, 'OLAB A');
    $olabB = cartsUxSourceItem($user, LaboratoryBrand::OLAB, 200000, 'OLAB B');
    $swiss = cartsUxSourceItem($user, LaboratoryBrand::SWISSLAB, 147159, 'Swiss C');
    $legacy = cartsUxLegacyLabCart($user, [$olabA, $olabB, $swiss]);

    app(SyncMonitoringCartService::class)->syncLaboratory($user->customer);
    app(SyncMonitoringCartService::class)->syncLaboratory($user->customer);

    $carts = Cart::query()
        ->with('items')
        ->where('user_id', $user->id)
        ->where('type', MonitoringCartType::Lab)
        ->where('status', MonitoringCartStatus::Active)
        ->get();
    $cartIdsAfterSecondSync = $carts->pluck('id')->sort()->values()->all();

    app(SyncMonitoringCartService::class)->syncLaboratory($user->customer);

    $cartIdsAfterThirdSync = Cart::query()
        ->where('user_id', $user->id)
        ->where('type', MonitoringCartType::Lab)
        ->where('status', MonitoringCartStatus::Active)
        ->pluck('id')
        ->sort()
        ->values()
        ->all();

    $olab = $carts->first(fn (Cart $cart) => collect($cart->labBrands())->pluck('value')->contains(LaboratoryBrand::OLAB->value));
    $swisslab = $carts->first(fn (Cart $cart) => collect($cart->labBrands())->pluck('value')->contains(LaboratoryBrand::SWISSLAB->value));

    expect($carts)->toHaveCount(2)
        ->and($olab)->not->toBeNull()
        ->and($swisslab)->not->toBeNull()
        ->and($olab->items)->toHaveCount(2)
        ->and((float) $olab->total)->toBe(3000.00)
        ->and($swisslab->items)->toHaveCount(1)
        ->and((float) $swisslab->total)->toBe(1471.59)
        ->and($carts->every(fn (Cart $cart) => collect($cart->labBrands())->count() === 1))->toBeTrue()
        ->and(Cart::query()->whereKey($legacy)->exists())->toBeTrue()
        ->and($cartIdsAfterThirdSync)->toBe($cartIdsAfterSecondSync);
});

it('does not return a mixed active laboratory cart as the cart for a specific brand', function () {
    $user = cartsUxUser();
    $olab = cartsUxSourceItem($user, LaboratoryBrand::OLAB, 100000, 'OLAB A');
    $swiss = cartsUxSourceItem($user, LaboratoryBrand::SWISSLAB, 300000, 'Swiss C');
    $legacy = cartsUxLegacyLabCart($user, [$olab, $swiss]);

    $cart = app(SyncMonitoringCartService::class)->activeLaboratoryCart($user->customer, LaboratoryBrand::OLAB);

    expect($cart)->toBeNull()
        ->and(collect($legacy->labBrands())->pluck('value')->sort()->values()->all())
        ->toBe([LaboratoryBrand::OLAB->value, LaboratoryBrand::SWISSLAB->value]);
});

it('keeps the legacy cart id for the highest amount brand when no explicit relation exists', function () {
    $user = cartsUxUser();
    $olab = cartsUxSourceItem($user, LaboratoryBrand::OLAB, 100000, 'OLAB A');
    $swiss = cartsUxSourceItem($user, LaboratoryBrand::SWISSLAB, 300000, 'Swiss C');
    $legacy = cartsUxLegacyLabCart($user, [$olab, $swiss]);

    app(SyncMonitoringCartService::class)->syncLaboratory($user->customer);

    $legacy->refresh()->load('items');

    expect(collect($legacy->labBrands())->pluck('value')->all())->toBe([LaboratoryBrand::SWISSLAB->value])
        ->and((float) $legacy->total)->toBe(3000.00);
});

it('flags mixed active laboratory carts in admin rows instead of presenting combined brands as normal', function () {
    $admin = cartsUxAdmin();
    $user = cartsUxUser();
    $olab = cartsUxSourceItem($user, LaboratoryBrand::OLAB, 100000, 'OLAB A');
    $swiss = cartsUxSourceItem($user, LaboratoryBrand::SWISSLAB, 300000, 'Swiss C');
    $legacy = cartsUxLegacyLabCart($user, [$olab, $swiss], ['updated_at' => now()]);

    $this->actingAs($admin)
        ->get(route('admin.carts.index'))
        ->assertInertia(fn (Assert $page) => $page
            ->where('carts.data.0.id', $legacy->id)
            ->where('carts.data.0.cart_summary.brand_label', 'Inconsistencia: multiples marcas')
            ->where('carts.data.0.cart_summary.brand_integrity.has_multiple_brands', true)
        );
});

it('uses explicit same-brand relations to decide which brand keeps the legacy cart id', function () {
    $user = cartsUxUser();
    $olab = cartsUxSourceItem($user, LaboratoryBrand::OLAB, 100000, 'OLAB A');
    $swiss = cartsUxSourceItem($user, LaboratoryBrand::SWISSLAB, 300000, 'Swiss C');
    $legacy = cartsUxLegacyLabCart($user, [$olab, $swiss]);

    LaboratoryAppointment::query()->create([
        'customer_id' => $user->customer->id,
        'cart_id' => $legacy->id,
        'brand' => LaboratoryBrand::OLAB->value,
        'confirmed_at' => null,
    ]);

    app(SyncMonitoringCartService::class)->syncLaboratory($user->customer);

    $legacy->refresh()->load('items');

    expect(collect($legacy->labBrands())->pluck('value')->all())->toBe([LaboratoryBrand::OLAB->value])
        ->and((float) $legacy->total)->toBe(1000.00);
});

it('does not silently split a mixed active cart with conflicting explicit relations', function () {
    $user = cartsUxUser();
    $olab = cartsUxSourceItem($user, LaboratoryBrand::OLAB, 100000, 'OLAB A');
    $swiss = cartsUxSourceItem($user, LaboratoryBrand::SWISSLAB, 300000, 'Swiss C');
    $legacy = cartsUxLegacyLabCart($user, [$olab, $swiss]);

    LaboratoryAppointment::query()->create([
        'customer_id' => $user->customer->id,
        'cart_id' => $legacy->id,
        'brand' => LaboratoryBrand::OLAB->value,
        'confirmed_at' => null,
    ]);
    cartsUxPurchase($user, [
        'cart_id' => $legacy->id,
        'brand' => LaboratoryBrand::SWISSLAB->value,
    ]);

    app(SyncMonitoringCartService::class)->syncLaboratory($user->customer);

    $carts = Cart::query()
        ->with('items')
        ->where('user_id', $user->id)
        ->where('type', MonitoringCartType::Lab)
        ->where('status', MonitoringCartStatus::Active)
        ->get();

    expect($carts)->toHaveCount(1)
        ->and($carts->first()->id)->toBe($legacy->id)
        ->and(collect($carts->first()->labBrands())->pluck('value')->sort()->values()->all())
        ->toBe([LaboratoryBrand::OLAB->value, LaboratoryBrand::SWISSLAB->value]);
});

it('does not modify historical completed mixed laboratory carts', function () {
    $user = cartsUxUser();
    $olab = cartsUxSourceItem($user, LaboratoryBrand::OLAB, 100000, 'OLAB A');
    $swiss = cartsUxSourceItem($user, LaboratoryBrand::SWISSLAB, 300000, 'Swiss C');
    $completed = cartsUxLegacyLabCart($user, [$olab, $swiss], [
        'status' => MonitoringCartStatus::Completed->value,
        'completed_at' => now()->subDay(),
    ]);

    app(SyncMonitoringCartService::class)->syncLaboratory($user->customer);

    $completed->refresh()->load('items');

    expect($completed->status)->toBe(MonitoringCartStatus::Completed)
        ->and($completed->items)->toHaveCount(2)
        ->and(collect($completed->labBrands())->pluck('value')->sort()->values()->all())
        ->toBe([LaboratoryBrand::OLAB->value, LaboratoryBrand::SWISSLAB->value]);
});

it('completes only the active laboratory cart for the purchased brand', function () {
    $user = cartsUxUser();
    cartsUxSourceItem($user, LaboratoryBrand::OLAB, 100000, 'OLAB A');
    cartsUxSourceItem($user, LaboratoryBrand::SWISSLAB, 300000, 'Swiss C');

    app(SyncMonitoringCartService::class)->syncLaboratory($user->customer);
    app(SyncMonitoringCartService::class)->markLaboratoryCartCompleted($user->customer, LaboratoryBrand::OLAB);

    $olab = Cart::query()
        ->with('items')
        ->where('user_id', $user->id)
        ->where('type', MonitoringCartType::Lab)
        ->get()
        ->first(fn (Cart $cart) => collect($cart->labBrands())->pluck('value')->contains(LaboratoryBrand::OLAB->value));
    $swisslab = Cart::query()
        ->with('items')
        ->where('user_id', $user->id)
        ->where('type', MonitoringCartType::Lab)
        ->get()
        ->first(fn (Cart $cart) => collect($cart->labBrands())->pluck('value')->contains(LaboratoryBrand::SWISSLAB->value));

    expect($olab->status)->toBe(MonitoringCartStatus::Completed)
        ->and($swisslab->status)->toBe(MonitoringCartStatus::Active);
});

it('applies the last seven days by default and custom period replaces it', function () {
    $admin = cartsUxAdmin();
    $recent = cartsUxCart(cartsUxUser(), attributes: ['updated_at' => now()->subDays(2), 'created_at' => now()->subDays(2)]);
    $old = cartsUxCart(cartsUxUser(), attributes: ['updated_at' => now()->subDays(10), 'created_at' => now()->subDays(10)]);

    $this->actingAs($admin)
        ->get(route('admin.carts.index'))
        ->assertInertia(fn (Assert $page) => $page
            ->where('carts.data.0.id', $recent->id)
            ->where('filters.start_date', now('America/Monterrey')->subDays(6)->toDateString())
            ->where('usingDefaultPeriod', true)
        );

    $this->actingAs($admin)
        ->get(route('admin.carts.index', [
            'start_date' => now('America/Monterrey')->subDays(15)->toDateString(),
            'end_date' => now('America/Monterrey')->toDateString(),
        ]))
        ->assertInertia(fn (Assert $page) => $page
            ->where('usingDefaultPeriod', false)
            ->has('carts.data', 2)
        );

    expect($old->exists)->toBeTrue();
});

it('filters no progress, pending appointment, recurrent customer, brand, amount and inactivity before pagination', function () {
    $admin = cartsUxAdmin();
    $user = cartsUxUser();
    cartsUxPurchase($user, ['created_at' => now()->subDays(20), 'updated_at' => now()->subDays(20)]);
    cartsUxPurchase($user, ['created_at' => now()->subDays(15), 'updated_at' => now()->subDays(15)]);
    $match = cartsUxCart($user, LaboratoryBrand::OLAB, [
        'total' => 6000.00,
        'updated_at' => now()->subDays(4),
        'created_at' => now()->subDays(5),
    ]);
    LaboratoryAppointment::query()->create([
        'customer_id' => $user->customer->id,
        'cart_id' => $match->id,
        'brand' => LaboratoryBrand::OLAB->value,
        'confirmed_at' => null,
        'created_at' => now()->subDays(4),
        'updated_at' => now()->subDays(4),
    ]);
    cartsUxCart(cartsUxUser(), LaboratoryBrand::SWISSLAB, ['total' => 800.00]);

    $this->actingAs($admin)
        ->get(route('admin.carts.index', [
            'checkout_stage' => 'no_progress',
            'appointment_filter' => 'pending',
            'customer_segment' => 'recurrent',
            'brand' => LaboratoryBrand::OLAB->value,
            'amount_range' => 'gt_5000',
            'inactivity_range' => 'gt_3d',
            'start_date' => now('America/Monterrey')->subDays(6)->toDateString(),
            'end_date' => now('America/Monterrey')->toDateString(),
        ]))
        ->assertInertia(fn (Assert $page) => $page
            ->has('carts.data', 1)
            ->where('carts.data.0.id', $match->id)
        );
});

it('export accepts and forwards new filters', function () {
    Queue::fake();
    $admin = cartsUxAdmin();

    $this->actingAs($admin)->post(route('admin.carts.export'), [
        'checkout_stage' => 'no_progress',
        'appointment_filter' => 'pending',
        'contact_filter' => 'callback_requested',
        'customer_segment' => 'recurrent',
        'brand' => LaboratoryBrand::OLAB->value,
        'amount_range' => 'gt_5000',
        'inactivity_range' => 'gt_3d',
    ]);

    Queue::assertPushed(ProcessCartsSpreadsheetExport::class, fn (ProcessCartsSpreadsheetExport $job) => $job->filters['checkout_stage'] === 'no_progress'
        && $job->filters['brand'] === LaboratoryBrand::OLAB->value
        && $job->filters['amount_range'] === 'gt_5000');
});
