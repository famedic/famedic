<?php

use App\Enums\CartEventType;
use App\Enums\LaboratoryBrand;
use App\Enums\MonitoringCartStatus;
use App\Enums\MonitoringCartType;
use App\Models\Cart;
use App\Models\LaboratoryAppointment;
use App\Models\LaboratoryPurchase;
use App\Models\PaymentAttempt;
use App\Models\User;
use App\Services\Carts\CartEventRecorder;

function traceabilityUser(): User
{
    return User::factory()->withRegularCustomer()->create();
}

function traceabilityCart(User $user, array $attributes = []): Cart
{
    return Cart::query()->create(array_merge([
        'user_id' => $user->id,
        'type' => MonitoringCartType::Lab->value,
        'status' => MonitoringCartStatus::Active->value,
        'total' => 1000.00,
        'created_at' => now()->subHour(),
        'updated_at' => now()->subMinutes(20),
    ], $attributes));
}

function traceabilityPurchase(User $user, array $attributes = []): LaboratoryPurchase
{
    return LaboratoryPurchase::query()->create(array_merge([
        'brand' => LaboratoryBrand::OLAB->value,
        'gda_order_id' => uniqid('gda-', true),
        'name' => 'Paciente',
        'paternal_lastname' => 'Prueba',
        'maternal_lastname' => 'Trace',
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
    ], $attributes));
}

it('records idempotent cart events and removes sensitive metadata', function () {
    $cart = traceabilityCart(traceabilityUser());

    $recorder = app(CartEventRecorder::class);
    $recorder->recordOnce(
        $cart,
        CartEventType::PaymentDeclined,
        'attempt-1-declined',
        [
            'payment_attempt_id' => 123,
            'processor_code' => '87',
            'raw_response' => ['secret' => 'hidden'],
            'card_token' => 'tok_hidden',
        ],
        source: 'test',
    );
    $recorder->recordOnce($cart, CartEventType::PaymentDeclined, 'attempt-1-declined', source: 'test');

    $event = $cart->events()->first();

    expect($cart->events()->count())->toBe(1)
        ->and($event->event)->toBe(CartEventType::PaymentDeclined)
        ->and($event->metadata)->toHaveKey('payment_attempt_id')
        ->and($event->metadata)->toHaveKey('processor_code')
        ->and($event->metadata)->not->toHaveKey('raw_response')
        ->and($event->metadata)->not->toHaveKey('card_token');
});

it('uses explicit purchase and appointment links before historical fallback', function () {
    $user = traceabilityUser();
    $cart = traceabilityCart($user, [
        'status' => MonitoringCartStatus::Completed->value,
        'completed_at' => now()->subMinutes(10),
        'updated_at' => now()->subMinutes(10),
    ]);

    $explicitPurchase = traceabilityPurchase($user, [
        'cart_id' => $cart->id,
        'created_at' => now()->subMinutes(20),
        'updated_at' => now()->subMinutes(20),
    ]);
    traceabilityPurchase($user, [
        'created_at' => now()->subMinute(),
        'updated_at' => now()->subMinute(),
    ]);

    $explicitAppointment = LaboratoryAppointment::query()->create([
        'customer_id' => $user->customer->id,
        'cart_id' => $cart->id,
        'brand' => LaboratoryBrand::OLAB->value,
        'laboratory_purchase_id' => $explicitPurchase->id,
        'confirmed_at' => now()->subMinutes(15),
    ]);
    LaboratoryAppointment::query()->create([
        'customer_id' => $user->customer->id,
        'brand' => LaboratoryBrand::OLAB->value,
        'confirmed_at' => null,
    ]);

    $cart = $cart->fresh(['user.customer', 'laboratoryPurchases', 'laboratoryAppointments']);

    expect($cart->relatedLaboratoryPurchase()->id)->toBe($explicitPurchase->id)
        ->and($cart->laboratoryAppointmentsForDisplay()->pluck('id')->all())->toBe([$explicitAppointment->id]);
});

it('filters payment status by explicit attempts before legacy fallback', function () {
    $user = traceabilityUser();
    $explicitCart = traceabilityCart($user);

    PaymentAttempt::query()->create([
        'customer_id' => $user->customer->id,
        'cart_id' => $explicitCart->id,
        'amount_cents' => 100000,
        'gateway' => 'efevoopay',
        'status' => PaymentAttempt::STATUS_DECLINED,
        'processed_at' => now()->subMinutes(5),
    ]);
    PaymentAttempt::query()->create([
        'customer_id' => $user->customer->id,
        'amount_cents' => 100000,
        'gateway' => 'efevoopay',
        'status' => PaymentAttempt::STATUS_APPROVED,
        'created_at' => now()->subMinutes(6),
        'updated_at' => now()->subMinutes(5),
        'processed_at' => now()->subMinutes(5),
    ]);

    $legacyUser = traceabilityUser();
    $legacyCart = traceabilityCart($legacyUser, ['total' => 700.00]);
    PaymentAttempt::query()->create([
        'customer_id' => $legacyUser->customer->id,
        'amount_cents' => 70000,
        'gateway' => 'efevoopay',
        'status' => PaymentAttempt::STATUS_ERROR,
        'created_at' => now()->subMinutes(6),
        'updated_at' => now()->subMinutes(5),
        'processed_at' => now()->subMinutes(5),
    ]);

    expect(Cart::query()->relatedPaymentAttemptStatus(PaymentAttempt::STATUS_DECLINED)->pluck('id')->all())
        ->toContain($explicitCart->id)
        ->and(Cart::query()->relatedPaymentAttemptStatus(PaymentAttempt::STATUS_APPROVED)->pluck('id')->all())
        ->not->toContain($explicitCart->id)
        ->and(Cart::query()->relatedPaymentAttemptStatus(PaymentAttempt::STATUS_ERROR)->pluck('id')->all())
        ->toContain($legacyCart->id);
});
