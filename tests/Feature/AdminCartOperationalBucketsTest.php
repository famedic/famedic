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
use App\Services\Carts\CartOperationalInsightResolver;
use App\Services\Carts\CartPaymentAttemptCorrelator;

function operationalBucketCustomer(): User
{
    return User::factory()->withRegularCustomer()->create();
}

function operationalBucketCart(User $user, array $attributes = []): Cart
{
    $test = LaboratoryTest::factory()->create([
        'brand' => LaboratoryBrand::OLAB->value,
        'requires_appointment' => true,
    ]);

    $cart = Cart::query()->create(array_merge([
        'user_id' => $user->id,
        'type' => MonitoringCartType::Lab->value,
        'status' => MonitoringCartStatus::Active->value,
        'total' => 1000.00,
        'created_at' => now()->subHour(),
        'updated_at' => now()->subMinutes(20),
    ], $attributes));

    CartItem::query()->create([
        'cart_id' => $cart->id,
        'product_id' => (string) $test->id,
        'name' => 'Biometría hemática',
        'price' => 1000.00,
        'quantity' => 1,
    ]);
    LaboratoryCartItem::withoutEvents(fn () => LaboratoryCartItem::query()->create([
        'customer_id' => $user->customer->id,
        'laboratory_test_id' => $test->id,
    ]));

    return $cart;
}

function operationalBucketAttempt(Cart $cart, string $status, array $attributes = []): PaymentAttempt
{
    $attempt = new PaymentAttempt(array_merge([
        'customer_id' => $cart->user->customer->id,
        'amount_cents' => 100000,
        'gateway' => 'efevoopay',
        'reference' => 'LAB-test',
        'status' => $status,
        'processor_code' => $status === PaymentAttempt::STATUS_DECLINED ? '87' : null,
        'processor_message' => $status === PaymentAttempt::STATUS_ERROR ? 'Timeout' : null,
        'processed_at' => now()->subMinutes(15),
    ], $attributes));
    $attempt->created_at = $attributes['created_at'] ?? now()->subMinutes(16);
    $attempt->updated_at = $attributes['updated_at'] ?? now()->subMinutes(15);
    $attempt->save();

    return $attempt;
}

function operationalInsightFor(Cart $cart): array
{
    $cart = $cart->fresh([
        'items',
        'user.customer.laboratoryCartItems.laboratoryTest',
        'user.customer.laboratoryAppointments',
    ]);
    $paymentInsight = app(CartPaymentAttemptCorrelator::class)->forCarts(collect([$cart]))[$cart->id] ?? null;

    return app(CartOperationalInsightResolver::class)->resolve($cart, $paymentInsight);
}

function operationalBrandCart(User $user, LaboratoryBrand $brand, bool $requiresAppointment): Cart
{
    $test = LaboratoryTest::factory()->create([
        'brand' => $brand->value,
        'requires_appointment' => $requiresAppointment,
        'famedic_price_cents' => 100000,
    ]);

    $cart = Cart::query()->create([
        'user_id' => $user->id,
        'type' => MonitoringCartType::Lab->value,
        'status' => MonitoringCartStatus::Active->value,
        'total' => 1000.00,
        'created_at' => now()->subHour(),
        'updated_at' => now()->subMinutes(20),
    ]);

    CartItem::query()->create([
        'cart_id' => $cart->id,
        'product_id' => (string) $test->id,
        'name' => $test->name,
        'price' => 1000.00,
        'quantity' => 1,
    ]);

    LaboratoryCartItem::withoutEvents(fn () => LaboratoryCartItem::query()->create([
        'customer_id' => $user->customer->id,
        'laboratory_test_id' => $test->id,
    ]));

    return $cart;
}

it('classifies declined payment as attention and payment bucket', function () {
    $cart = operationalBucketCart(operationalBucketCustomer());
    operationalBucketAttempt($cart, PaymentAttempt::STATUS_DECLINED);

    expect(Cart::query()->operationalBucket('attention')->whereKey($cart)->exists())->toBeTrue()
        ->and(Cart::query()->operationalBucket('payment')->whereKey($cart)->exists())->toBeTrue()
        ->and(operationalInsightFor($cart)['reason'])->toBe('payment_declined');
});

it('classifies technical error and pending payment as payment attention', function () {
    $errorCart = operationalBucketCart(operationalBucketCustomer());
    operationalBucketAttempt($errorCart, PaymentAttempt::STATUS_ERROR);

    $pendingCart = operationalBucketCart(operationalBucketCustomer());
    operationalBucketAttempt($pendingCart, PaymentAttempt::STATUS_PROCESSING);

    expect(operationalInsightFor($errorCart)['reason'])->toBe('payment_error')
        ->and(operationalInsightFor($pendingCart)['reason'])->toBe('payment_pending')
        ->and(Cart::query()->operationalBucket('payment')->count())->toBe(2);
});

it('classifies callback and phone intent as contact attention', function () {
    $callbackCart = operationalBucketCart(operationalBucketCustomer());
    LaboratoryAppointment::factory()->create([
        'customer_id' => $callbackCart->user->customer->id,
        'brand' => LaboratoryBrand::OLAB->value,
        'patient_callback_comment' => 'Prefiere llamada por la tarde',
    ]);

    $phoneCart = operationalBucketCart(operationalBucketCustomer());
    $phonePurchase = LaboratoryPurchase::factory()->create([
        'customer_id' => $phoneCart->user->customer->id,
        'brand' => LaboratoryBrand::OLAB->value,
        'gda_order_id' => uniqid('gda-operational-', true),
        'name' => 'Paciente',
        'paternal_lastname' => 'Operativo',
        'maternal_lastname' => 'Test',
        'phone' => '8111111111',
        'phone_country' => 'MX',
        'birth_date' => '1990-01-01',
        'street' => 'Calle',
        'number' => '1',
        'neighborhood' => 'Centro',
        'state' => 'Nuevo Leon',
        'city' => 'Monterrey',
        'zipcode' => '64000',
        'total_cents' => 100000,
    ]);
    LaboratoryAppointment::factory()->create([
        'customer_id' => $phoneCart->user->customer->id,
        'brand' => LaboratoryBrand::OLAB->value,
        'confirmed_at' => now(),
        'laboratory_purchase_id' => $phonePurchase->id,
        'phone_call_intent_at' => now()->subMinutes(10),
    ]);

    expect(operationalInsightFor($callbackCart)['reason'])->toBe('callback_requested')
        ->and(operationalInsightFor($phoneCart)['reason'])->toBe('phone_call_intent')
        ->and(Cart::query()->operationalBucket('contact')->count())->toBe(2);
});

it('classifies pending and confirmed without payment appointments as appointment attention', function () {
    $pendingCart = operationalBucketCart(operationalBucketCustomer());
    LaboratoryAppointment::factory()->create([
        'customer_id' => $pendingCart->user->customer->id,
        'brand' => LaboratoryBrand::OLAB->value,
        'confirmed_at' => null,
    ]);

    $confirmedCart = operationalBucketCart(operationalBucketCustomer());
    LaboratoryAppointment::factory()->confirmed()->create([
        'customer_id' => $confirmedCart->user->customer->id,
        'brand' => LaboratoryBrand::OLAB->value,
        'laboratory_purchase_id' => null,
        'patient_gender' => null,
    ]);

    expect(operationalInsightFor($pendingCart)['reason'])->toBe('appointment_pending')
        ->and(operationalInsightFor($confirmedCart)['reason'])->toBe('appointment_confirmed_without_payment')
        ->and(Cart::query()->operationalBucket('appointment')->count())->toBe(2);
});

it('does not require attention for simple abandoned or completed carts', function () {
    $abandoned = operationalBucketCart(operationalBucketCustomer(), [
        'updated_at' => now()->subHours(2),
    ]);
    $completed = operationalBucketCart(operationalBucketCustomer(), [
        'status' => MonitoringCartStatus::Completed->value,
        'completed_at' => now(),
    ]);
    operationalBucketAttempt($completed, PaymentAttempt::STATUS_DECLINED);

    expect(operationalInsightFor($abandoned)['requires_attention'])->toBeFalse()
        ->and(operationalInsightFor($completed)['requires_attention'])->toBeFalse()
        ->and(Cart::query()->operationalBucket('attention')->whereKey($abandoned)->exists())->toBeFalse()
        ->and(Cart::query()->operationalBucket('attention')->whereKey($completed)->exists())->toBeFalse();
});

it('uses latest payment state for operational reason', function () {
    $cart = operationalBucketCart(operationalBucketCustomer());
    operationalBucketAttempt($cart, PaymentAttempt::STATUS_ERROR, [
        'created_at' => now()->subMinutes(30),
        'updated_at' => now()->subMinutes(29),
        'processed_at' => now()->subMinutes(29),
    ]);
    operationalBucketAttempt($cart, PaymentAttempt::STATUS_DECLINED, [
        'created_at' => now()->subMinutes(10),
        'updated_at' => now()->subMinutes(9),
        'processed_at' => now()->subMinutes(9),
    ]);

    expect(operationalInsightFor($cart)['reason'])->toBe('payment_declined');
});

it('does not use ambiguous payment as principal reason when callback exists', function () {
    $user = operationalBucketCustomer();
    $first = operationalBucketCart($user);
    operationalBucketCart($user, [
        'created_at' => now()->subMinutes(55),
        'updated_at' => now()->subMinutes(10),
    ]);
    operationalBucketAttempt($first, PaymentAttempt::STATUS_DECLINED, [
        'created_at' => now()->subMinutes(31),
        'updated_at' => now()->subMinutes(30),
        'processed_at' => now()->subMinutes(30),
    ]);
    LaboratoryAppointment::factory()->create([
        'customer_id' => $user->customer->id,
        'brand' => LaboratoryBrand::OLAB->value,
        'patient_callback_comment' => 'Llamar por la tarde',
    ]);

    expect(operationalInsightFor($first)['reason'])->toBe('callback_requested')
        ->and(Cart::query()->operationalBucket('payment')->whereKey($first)->exists())->toBeFalse();
});

it('keeps metric counts equal to backend bucket results', function () {
    $paymentCart = operationalBucketCart(operationalBucketCustomer());
    operationalBucketAttempt($paymentCart, PaymentAttempt::STATUS_DECLINED);

    $appointmentCart = operationalBucketCart(operationalBucketCustomer());
    LaboratoryAppointment::factory()->create([
        'customer_id' => $appointmentCart->user->customer->id,
        'brand' => LaboratoryBrand::OLAB->value,
        'confirmed_at' => null,
    ]);

    expect(Cart::query()->operationalBucket('attention')->count())->toBe(2)
        ->and(Cart::query()->operationalBucket('attention')->paginate(1)->total())->toBe(2);
});

it('does not leak an explicit Swisslab appointment into an Olab cart for the same customer', function () {
    $user = operationalBucketCustomer();
    $olab = operationalBrandCart($user, LaboratoryBrand::OLAB, false);
    $swisslab = operationalBrandCart($user, LaboratoryBrand::SWISSLAB, true);

    $appointment = LaboratoryAppointment::factory()->create([
        'customer_id' => $user->customer->id,
        'cart_id' => $swisslab->id,
        'brand' => LaboratoryBrand::SWISSLAB->value,
        'confirmed_at' => null,
    ]);

    expect(Cart::query()->operationalBucket('appointment')->whereKey($olab)->exists())->toBeFalse()
        ->and(Cart::query()->operationalBucket('appointment')->whereKey($swisslab)->exists())->toBeTrue()
        ->and(operationalInsightFor($olab)['reason'])->toBe('none')
        ->and(operationalInsightFor($swisslab)['reason'])->toBe('appointment_pending')
        ->and($olab->fresh(['items', 'user.customer.laboratoryAppointments'])->laboratoryAppointmentsForDisplay())->toHaveCount(0)
        ->and($swisslab->fresh(['items', 'user.customer.laboratoryAppointments'])->laboratoryAppointmentsForDisplay()->pluck('id')->all())->toBe([$appointment->id]);
});

it('matches legacy brand appointments only to carts with that brand', function () {
    $user = operationalBucketCustomer();
    $olab = operationalBrandCart($user, LaboratoryBrand::OLAB, false);
    $swisslab = operationalBrandCart($user, LaboratoryBrand::SWISSLAB, true);

    $appointment = LaboratoryAppointment::factory()->create([
        'customer_id' => $user->customer->id,
        'cart_id' => null,
        'brand' => LaboratoryBrand::SWISSLAB->value,
        'confirmed_at' => null,
    ]);

    expect(Cart::query()->operationalBucket('appointment')->whereKey($olab)->exists())->toBeFalse()
        ->and(Cart::query()->operationalBucket('appointment')->whereKey($swisslab)->exists())->toBeTrue()
        ->and(operationalInsightFor($olab)['reason'])->toBe('none')
        ->and(operationalInsightFor($swisslab)['reason'])->toBe('appointment_pending')
        ->and($olab->fresh(['items', 'user.customer.laboratoryAppointments'])->laboratoryAppointmentsForDisplay())->toHaveCount(0)
        ->and($swisslab->fresh(['items', 'user.customer.laboratoryAppointments'])->laboratoryAppointmentsForDisplay()->pluck('id')->all())->toBe([$appointment->id]);
});

it('keeps explicit cart appointment precedence over a nearby legacy appointment for another brand', function () {
    $user = operationalBucketCustomer();
    $olab = operationalBrandCart($user, LaboratoryBrand::OLAB, false);
    $swisslab = operationalBrandCart($user, LaboratoryBrand::SWISSLAB, true);

    $explicitOlab = LaboratoryAppointment::factory()->create([
        'customer_id' => $user->customer->id,
        'cart_id' => $olab->id,
        'brand' => LaboratoryBrand::OLAB->value,
        'confirmed_at' => null,
    ]);
    LaboratoryAppointment::factory()->create([
        'customer_id' => $user->customer->id,
        'cart_id' => null,
        'brand' => LaboratoryBrand::SWISSLAB->value,
        'confirmed_at' => null,
        'updated_at' => now(),
    ]);

    expect($olab->fresh(['items', 'laboratoryAppointments', 'user.customer.laboratoryAppointments'])->laboratoryAppointmentsForDisplay()->pluck('id')->all())
        ->toBe([$explicitOlab->id])
        ->and(Cart::query()->operationalBucket('appointment')->whereKey($olab)->exists())->toBeTrue()
        ->and(Cart::query()->operationalBucket('appointment')->whereKey($swisslab)->exists())->toBeTrue();
});
