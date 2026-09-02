<?php

use App\Enums\LaboratoryBrand;
use App\Enums\MonitoringCartStatus;
use App\Enums\MonitoringCartType;
use App\Models\Cart;
use App\Models\CartItem;
use App\Models\LaboratoryAppointment;
use App\Models\LaboratoryCartItem;
use App\Models\LaboratoryPurchase;
use App\Models\LaboratoryTest;
use App\Models\PaymentAttempt;
use App\Models\User;
use App\Services\CartsDashboard\CartsAnalyticsService;
use App\Support\CartsDashboard\CartsDashboardFilter;
use Carbon\Carbon;

function cartsDashboardFilter(?Carbon $start = null, ?Carbon $end = null, ?string $brand = null): CartsDashboardFilter
{
    $tz = config('app.timezone', 'America/Monterrey');
    $startLocal = ($start ?? now($tz)->subDays(2))->copy()->startOfDay();
    $endLocal = ($end ?? now($tz))->copy()->endOfDay();

    return new CartsDashboardFilter(
        start: $startLocal->copy()->utc(),
        end: $endLocal->copy()->utc(),
        previousStart: $startLocal->copy()->subDays(3)->utc(),
        previousEnd: $startLocal->copy()->subSecond()->utc(),
        startLocal: $startLocal,
        endLocal: $endLocal,
        period: 'custom',
        type: null,
        brand: $brand,
        bustCache: true,
    );
}

function cartsDashboardUser(): User
{
    return User::factory()->withRegularCustomer()->create();
}

function cartsDashboardCart(User $user, array $attributes = [], LaboratoryBrand $brand = LaboratoryBrand::OLAB): Cart
{
    $test = LaboratoryTest::factory()->create([
        'brand' => $brand->value,
        'requires_appointment' => true,
    ]);

    $cart = Cart::query()->create(array_merge([
        'user_id' => $user->id,
        'type' => MonitoringCartType::Lab->value,
        'status' => MonitoringCartStatus::Active->value,
        'total' => 1000.00,
        'created_at' => now()->subHours(4),
        'updated_at' => now()->subHours(2),
    ], $attributes));

    CartItem::query()->create([
        'cart_id' => $cart->id,
        'product_id' => (string) $test->id,
        'name' => 'Biometria hematica',
        'price' => 500.00,
        'quantity' => 2,
    ]);

    LaboratoryCartItem::withoutEvents(fn () => LaboratoryCartItem::query()->create([
        'customer_id' => $user->customer->id,
        'laboratory_test_id' => $test->id,
    ]));

    return $cart;
}

function cartsDashboardAttempt(Cart $cart, string $status, array $attributes = []): PaymentAttempt
{
    $attempt = new PaymentAttempt(array_merge([
        'customer_id' => $cart->user->customer->id,
        'amount_cents' => (int) round((float) $cart->total * 100),
        'gateway' => 'efevoopay',
        'reference' => 'LAB-dashboard-test',
        'status' => $status,
        'processed_at' => now()->subMinutes(80),
    ], $attributes));
    $attempt->created_at = $attributes['created_at'] ?? now()->subMinutes(90);
    $attempt->updated_at = $attributes['updated_at'] ?? now()->subMinutes(80);
    $attempt->save();

    return $attempt;
}

function cartsDashboardPurchase(User $user, array $attributes = []): LaboratoryPurchase
{
    return LaboratoryPurchase::query()->create(array_merge([
        'brand' => LaboratoryBrand::OLAB->value,
        'gda_order_id' => uniqid('gda-', true),
        'name' => 'Paciente',
        'paternal_lastname' => 'Prueba',
        'maternal_lastname' => 'Dashboard',
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

it('builds executive KPIs, amounts, conversion and daily series', function () {
    cartsDashboardCart(cartsDashboardUser(), ['total' => 1200.00]);
    cartsDashboardCart(cartsDashboardUser(), ['total' => 800.00]);
    cartsDashboardCart(cartsDashboardUser(), [
        'status' => MonitoringCartStatus::Completed->value,
        'total' => 1500.00,
        'completed_at' => now()->subHour(),
        'updated_at' => now()->subHour(),
    ]);

    $dashboard = app(CartsAnalyticsService::class)->build(cartsDashboardFilter());

    expect(collect($dashboard['kpis'])->firstWhere('id', 'created')['value'])->toBe(3)
        ->and(collect($dashboard['kpis'])->firstWhere('id', 'abandoned')['value'])->toBe(2)
        ->and(collect($dashboard['kpis'])->firstWhere('id', 'completed')['value'])->toBe(1)
        ->and(collect($dashboard['kpis'])->firstWhere('id', 'conversion')['value'])->toBe(33.3)
        ->and(collect($dashboard['kpis'])->firstWhere('id', 'abandoned_amount')['value'])->toBe(2000.0)
        ->and(collect($dashboard['kpis'])->firstWhere('id', 'completed_amount')['value'])->toBe(1500.0)
        ->and(collect($dashboard['daily']['rows'])->sum('created_count'))->toBe(3);
});

it('counts correlated payment incidents and excludes ambiguous payment attempts', function () {
    $declined = cartsDashboardCart(cartsDashboardUser(), ['total' => 1000.00]);
    cartsDashboardAttempt($declined, PaymentAttempt::STATUS_DECLINED);

    $error = cartsDashboardCart(cartsDashboardUser(), ['total' => 900.00]);
    cartsDashboardAttempt($error, PaymentAttempt::STATUS_ERROR);

    $ambiguousUser = cartsDashboardUser();
    $first = cartsDashboardCart($ambiguousUser, ['total' => 700.00]);
    cartsDashboardCart($ambiguousUser, [
        'total' => 700.00,
        'created_at' => now()->subHours(3),
        'updated_at' => now()->subHour(),
    ]);
    cartsDashboardAttempt($first, PaymentAttempt::STATUS_DECLINED, [
        'created_at' => now()->subMinutes(95),
        'updated_at' => now()->subMinutes(90),
        'processed_at' => now()->subMinutes(90),
    ]);

    $dashboard = app(CartsAnalyticsService::class)->build(cartsDashboardFilter());
    $operational = collect($dashboard['operational_kpis']);
    $paymentRows = collect($dashboard['payments']['status_breakdown']);

    expect($operational->firstWhere('id', 'payment_declined')['value'])->toBe(1)
        ->and($operational->firstWhere('id', 'payment_error')['value'])->toBe(1)
        ->and($paymentRows->firstWhere('key', 'declined')['count'])->toBe(1)
        ->and($paymentRows->firstWhere('key', 'error')['count'])->toBe(1);
});

it('counts explicit payment incidents while preserving legacy fallback in dashboard analytics', function () {
    $explicit = cartsDashboardCart(cartsDashboardUser(), ['total' => 1000.00]);
    cartsDashboardAttempt($explicit, PaymentAttempt::STATUS_DECLINED, [
        'cart_id' => $explicit->id,
        'created_at' => now()->subMinutes(50),
        'updated_at' => now()->subMinutes(45),
        'processed_at' => now()->subMinutes(45),
    ]);
    cartsDashboardAttempt($explicit, PaymentAttempt::STATUS_APPROVED, [
        'created_at' => now()->subMinutes(55),
        'updated_at' => now()->subMinutes(54),
        'processed_at' => now()->subMinutes(54),
    ]);

    $legacy = cartsDashboardCart(cartsDashboardUser(), ['total' => 900.00]);
    cartsDashboardAttempt($legacy, PaymentAttempt::STATUS_ERROR);

    $dashboard = app(CartsAnalyticsService::class)->build(cartsDashboardFilter());
    $paymentRows = collect($dashboard['payments']['status_breakdown']);

    expect($paymentRows->firstWhere('key', 'declined')['count'])->toBe(1)
        ->and($paymentRows->firstWhere('key', 'error')['count'])->toBe(1);
});

it('counts appointments, contact signals, laboratories, customer profile and top abandoned studies', function () {
    $pending = cartsDashboardCart(cartsDashboardUser(), [], LaboratoryBrand::OLAB);
    LaboratoryAppointment::factory()->create([
        'customer_id' => $pending->user->customer->id,
        'brand' => LaboratoryBrand::OLAB->value,
        'confirmed_at' => null,
    ]);

    $confirmed = cartsDashboardCart(cartsDashboardUser(), [], LaboratoryBrand::SWISSLAB);
    LaboratoryAppointment::factory()->confirmed()->create([
        'customer_id' => $confirmed->user->customer->id,
        'brand' => LaboratoryBrand::SWISSLAB->value,
        'laboratory_purchase_id' => null,
        'patient_gender' => null,
    ]);

    $callback = cartsDashboardCart(cartsDashboardUser(), [], LaboratoryBrand::OLAB);
    LaboratoryAppointment::factory()->create([
        'customer_id' => $callback->user->customer->id,
        'brand' => LaboratoryBrand::OLAB->value,
        'patient_callback_comment' => 'Llamar',
    ]);

    $recurrentUser = cartsDashboardUser();
    cartsDashboardPurchase($recurrentUser, ['created_at' => now()->subDays(20), 'updated_at' => now()->subDays(20)]);
    cartsDashboardPurchase($recurrentUser, ['created_at' => now()->subDays(15), 'updated_at' => now()->subDays(15)]);
    cartsDashboardCart($recurrentUser, [], LaboratoryBrand::SWISSLAB);

    $dashboard = app(CartsAnalyticsService::class)->build(cartsDashboardFilter());

    expect(collect($dashboard['appointments']['status_breakdown'])->firstWhere('key', 'pending')['count'])->toBe(2)
        ->and(collect($dashboard['appointments']['status_breakdown'])->firstWhere('key', 'confirmed_without_payment')['count'])->toBe(1)
        ->and(collect($dashboard['contact']['summary'])->firstWhere('key', 'callback_requested')['count'])->toBe(1)
        ->and(collect($dashboard['laboratories'])->firstWhere('brand', LaboratoryBrand::OLAB->value)['abandoned_count'])->toBe(0)
        ->and(collect($dashboard['laboratories'])->firstWhere('brand', LaboratoryBrand::SWISSLAB->value)['abandoned_count'])->toBeGreaterThanOrEqual(2)
        ->and(collect($dashboard['customer_profile']['segments'])->firstWhere('key', 'recurring')['abandoned_count'])->toBe(1)
        ->and($dashboard['top_studies']['abandoned'][0]['carts'])->toBeGreaterThanOrEqual(1)
        ->and($dashboard['top_studies']['abandoned'][0]['quantity'])->toBeGreaterThanOrEqual(2);
});

it('honors date and brand filters', function () {
    cartsDashboardCart(cartsDashboardUser(), [
        'created_at' => now()->subDays(10),
        'updated_at' => now()->subDays(10),
    ], LaboratoryBrand::OLAB);
    cartsDashboardCart(cartsDashboardUser(), [], LaboratoryBrand::SWISSLAB);

    $dashboard = app(CartsAnalyticsService::class)->build(cartsDashboardFilter(
        start: now()->subDay(),
        end: now(),
        brand: LaboratoryBrand::SWISSLAB->value,
    ));

    expect(collect($dashboard['kpis'])->firstWhere('id', 'created')['value'])->toBe(1)
        ->and($dashboard['laboratories'])->toHaveCount(1)
        ->and($dashboard['laboratories'][0]['brand'])->toBe(LaboratoryBrand::SWISSLAB->value);
});
