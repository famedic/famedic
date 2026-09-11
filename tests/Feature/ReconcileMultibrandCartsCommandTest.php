<?php

use App\Enums\CartEventType;
use App\Enums\LaboratoryBrand;
use App\Enums\MonitoringCartStatus;
use App\Enums\MonitoringCartType;
use App\Models\Cart;
use App\Models\CartItem;
use App\Models\LaboratoryAppointment;
use App\Models\LaboratoryCartItem;
use App\Models\LaboratoryPurchase;
use App\Models\LaboratoryTest;
use App\Models\User;
use App\Services\Carts\CartEventRecorder;
use Carbon\CarbonInterface;

function mbcUser(): User
{
    return User::factory()->withRegularCustomer()->create();
}

function mbcSourceItem(User $user, LaboratoryBrand $brand, int $priceCents, string $name): LaboratoryTest
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

function mbcCart(User $user, array $tests, array $attributes = []): Cart
{
    $cart = Cart::query()->create(array_merge([
        'user_id' => $user->id,
        'type' => MonitoringCartType::Lab->value,
        'status' => MonitoringCartStatus::Active->value,
        'total' => collect($tests)->sum(fn (LaboratoryTest $test) => numberCents($test->famedic_price_cents)),
        'created_at' => now()->subDays(2),
        'updated_at' => now()->subHour(),
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

function mbcBrands(Cart $cart): array
{
    return collect($cart->refresh()->load('items.laboratoryTest')->labBrands())->pluck('value')->sort()->values()->all();
}

it('dry run reports active multibrand carts and does not modify data', function () {
    $user = mbcUser();
    $olab = mbcSourceItem($user, LaboratoryBrand::OLAB, 100000, 'OLAB A');
    $swiss = mbcSourceItem($user, LaboratoryBrand::SWISSLAB, 200000, 'Swiss B');
    $legacy = mbcCart($user, [$olab, $swiss]);

    $this->artisan('carts:reconcile-multibrand', ['--cart' => $legacy->id])
        ->expectsOutputToContain('DRY RUN')
        ->expectsOutputToContain('cart_id: '.$legacy->id)
        ->expectsOutputToContain('resultado esperado: RECONCILED')
        ->assertExitCode(0);

    expect(Cart::query()->where('user_id', $user->id)->where('type', MonitoringCartType::Lab)->count())->toBe(1)
        ->and(mbcBrands($legacy))->toBe([LaboratoryBrand::OLAB->value, LaboratoryBrand::SWISSLAB->value]);
});

it('apply splits an active multibrand cart and is idempotent on a second cart run', function () {
    $user = mbcUser();
    $olab = mbcSourceItem($user, LaboratoryBrand::OLAB, 100000, 'OLAB A');
    $swiss = mbcSourceItem($user, LaboratoryBrand::SWISSLAB, 200000, 'Swiss B');
    $legacy = mbcCart($user, [$olab, $swiss]);

    $this->artisan('carts:reconcile-multibrand', ['--cart' => $legacy->id, '--apply' => true])
        ->expectsOutputToContain('resultado: reconciled')
        ->assertExitCode(0);

    $afterFirst = Cart::query()
        ->with('items.laboratoryTest')
        ->where('user_id', $user->id)
        ->where('type', MonitoringCartType::Lab)
        ->where('status', MonitoringCartStatus::Active)
        ->orderBy('id')
        ->get();

    $this->artisan('carts:reconcile-multibrand', ['--cart' => $legacy->id, '--apply' => true])
        ->expectsOutputToContain('resultado esperado: NO_CHANGES')
        ->expectsOutputToContain('resultado: no_changes')
        ->assertExitCode(0);

    $afterSecond = Cart::query()
        ->with('items.laboratoryTest')
        ->where('user_id', $user->id)
        ->where('type', MonitoringCartType::Lab)
        ->where('status', MonitoringCartStatus::Active)
        ->orderBy('id')
        ->get();

    expect($afterFirst)->toHaveCount(2)
        ->and($afterSecond->pluck('id')->all())->toBe($afterFirst->pluck('id')->all())
        ->and($afterSecond->map(fn (Cart $cart) => collect($cart->labBrands())->pluck('value')->all())->flatten()->sort()->values()->all())
        ->toBe([LaboratoryBrand::OLAB->value, LaboratoryBrand::SWISSLAB->value]);
});

it('keeps original id for explicit brand relation and for highest amount fallback', function () {
    $explicitUser = mbcUser();
    $explicitOlab = mbcSourceItem($explicitUser, LaboratoryBrand::OLAB, 100000, 'OLAB A');
    $explicitSwiss = mbcSourceItem($explicitUser, LaboratoryBrand::SWISSLAB, 300000, 'Swiss B');
    $explicitCart = mbcCart($explicitUser, [$explicitOlab, $explicitSwiss]);
    LaboratoryAppointment::query()->create([
        'customer_id' => $explicitUser->customer->id,
        'cart_id' => $explicitCart->id,
        'brand' => LaboratoryBrand::OLAB->value,
    ]);

    $amountUser = mbcUser();
    $amountOlab = mbcSourceItem($amountUser, LaboratoryBrand::OLAB, 100000, 'OLAB A');
    $amountSwiss = mbcSourceItem($amountUser, LaboratoryBrand::SWISSLAB, 300000, 'Swiss B');
    $amountCart = mbcCart($amountUser, [$amountOlab, $amountSwiss]);

    $this->artisan('carts:reconcile-multibrand', ['--cart' => $explicitCart->id, '--apply' => true])->assertExitCode(0);
    $this->artisan('carts:reconcile-multibrand', ['--cart' => $amountCart->id, '--apply' => true])->assertExitCode(0);

    expect(mbcBrands($explicitCart))->toBe([LaboratoryBrand::OLAB->value])
        ->and(mbcBrands($amountCart))->toBe([LaboratoryBrand::SWISSLAB->value]);
});

it('skips conflicting explicit relations without modifying data', function () {
    $user = mbcUser();
    $olab = mbcSourceItem($user, LaboratoryBrand::OLAB, 100000, 'OLAB A');
    $swiss = mbcSourceItem($user, LaboratoryBrand::SWISSLAB, 200000, 'Swiss B');
    $legacy = mbcCart($user, [$olab, $swiss]);

    LaboratoryAppointment::query()->create([
        'customer_id' => $user->customer->id,
        'cart_id' => $legacy->id,
        'brand' => LaboratoryBrand::OLAB->value,
    ]);
    LaboratoryPurchase::query()->create([
        'customer_id' => $user->customer->id,
        'cart_id' => $legacy->id,
        'brand' => LaboratoryBrand::SWISSLAB->value,
        'gda_order_id' => uniqid('gda-mbc-', true),
        'name' => 'Paciente',
        'paternal_lastname' => 'Test',
        'maternal_lastname' => 'Conflict',
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
        'total_cents' => 200000,
    ]);

    $this->artisan('carts:reconcile-multibrand', ['--cart' => $legacy->id, '--apply' => true])
        ->expectsOutputToContain('resultado: skipped_conflict')
        ->assertExitCode(0);

    expect(Cart::query()->where('user_id', $user->id)->where('type', MonitoringCartType::Lab)->count())->toBe(1)
        ->and(mbcBrands($legacy))->toBe([LaboratoryBrand::OLAB->value, LaboratoryBrand::SWISSLAB->value]);
});

it('ignores completed and pharmacy multibrand carts for apply scope', function () {
    $labUser = mbcUser();
    $olab = mbcSourceItem($labUser, LaboratoryBrand::OLAB, 100000, 'OLAB A');
    $swiss = mbcSourceItem($labUser, LaboratoryBrand::SWISSLAB, 200000, 'Swiss B');
    $completed = mbcCart($labUser, [$olab, $swiss], [
        'status' => MonitoringCartStatus::Completed->value,
        'completed_at' => now()->subHour(),
    ]);

    $pharmacyUser = mbcUser();
    $pharmacy = mbcCart($pharmacyUser, [$olab, $swiss], [
        'type' => MonitoringCartType::Pharmacy->value,
    ]);

    $this->artisan('carts:reconcile-multibrand', ['--apply' => true])->assertExitCode(0);

    expect(mbcBrands($completed))->toBe([LaboratoryBrand::OLAB->value, LaboratoryBrand::SWISSLAB->value])
        ->and($pharmacy->refresh()->items)->toHaveCount(2);
});

it('rolls back only the failing cart and continues with the next cart', function () {
    app()->bind(CartEventRecorder::class, fn () => new class extends CartEventRecorder
    {
        public function recordOnce(
            \App\Models\Cart $cart,
            CartEventType|string $event,
            string $idempotencyKey,
            array $metadata = [],
            ?CarbonInterface $occurredAt = null,
            ?string $source = null,
        ): ?\App\Models\CartEvent {
            if ($source === 'monitoring_cart_reconciler') {
                throw new RuntimeException('forced cart event failure');
            }

            return null;
        }
    });

    $failingUser = mbcUser();
    $failingOlab = mbcSourceItem($failingUser, LaboratoryBrand::OLAB, 300000, 'OLAB A');
    $failingSwiss = mbcSourceItem($failingUser, LaboratoryBrand::SWISSLAB, 100000, 'Swiss B');
    $failing = mbcCart($failingUser, [$failingOlab, $failingSwiss]);

    $okUser = mbcUser();
    $okOlab = mbcSourceItem($okUser, LaboratoryBrand::OLAB, 200000, 'OLAB A');
    $okSwiss = mbcSourceItem($okUser, LaboratoryBrand::SWISSLAB, 200000, 'Swiss B');
    $ok = mbcCart($okUser, [$okOlab, $okSwiss]);
    Cart::query()->create([
        'user_id' => $okUser->id,
        'type' => MonitoringCartType::Lab->value,
        'status' => MonitoringCartStatus::Active->value,
        'total' => 0,
    ])->items()->create([
        'product_id' => (string) $okSwiss->id,
        'name' => $okSwiss->name,
        'price' => 2000.00,
        'quantity' => 1,
    ]);

    $this->artisan('carts:reconcile-multibrand', ['--apply' => true])
        ->expectsOutputToContain('resultado: error')
        ->expectsOutputToContain('resultado: reconciled')
        ->assertExitCode(1);

    expect(mbcBrands($failing))->toBe([LaboratoryBrand::OLAB->value, LaboratoryBrand::SWISSLAB->value])
        ->and(mbcBrands($ok))->toBe([LaboratoryBrand::OLAB->value]);
});
