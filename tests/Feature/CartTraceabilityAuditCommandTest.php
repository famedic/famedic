<?php

use App\Enums\CartEventType;
use App\Enums\LaboratoryBrand;
use App\Enums\MonitoringCartStatus;
use App\Enums\MonitoringCartType;
use App\Models\Cart;
use App\Models\CartEvent;
use App\Models\CartItem;
use App\Models\LaboratoryAppointment;
use App\Models\LaboratoryPurchase;
use App\Models\LaboratoryTest;
use App\Models\PaymentAttempt;
use App\Models\User;
use Carbon\Carbon;

function auditCommandUser(): User
{
    return User::factory()->withRegularCustomer()->create();
}

function auditCommandCart(User $user, array $attributes = [], ?LaboratoryBrand $brand = LaboratoryBrand::OLAB): Cart
{
    $cart = Cart::query()->create(array_merge([
        'user_id' => $user->id,
        'type' => MonitoringCartType::Lab->value,
        'status' => MonitoringCartStatus::Active->value,
        'total' => 1000.00,
        'created_at' => now()->subHours(4),
        'updated_at' => now()->subHour(),
    ], $attributes));

    if ($brand) {
        $test = LaboratoryTest::factory()->create([
            'brand' => $brand->value,
            'requires_appointment' => true,
        ]);

        CartItem::query()->create([
            'cart_id' => $cart->id,
            'product_id' => (string) $test->id,
            'name' => 'Biometria hematica',
            'price' => (float) $cart->total,
            'quantity' => 1,
        ]);
    }

    return $cart;
}

function auditCommandPurchase(User $user, array $attributes = []): LaboratoryPurchase
{
    return LaboratoryPurchase::query()->create(array_merge([
        'brand' => LaboratoryBrand::OLAB->value,
        'gda_order_id' => uniqid('gda-audit-', true),
        'name' => 'Paciente',
        'paternal_lastname' => 'Audit',
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
        'created_at' => now()->subMinutes(30),
        'updated_at' => now()->subMinutes(30),
    ], $attributes));
}

it('reports a fully explicit and consistent traceability audit', function () {
    Carbon::setTestNow(Carbon::parse('2026-08-20 12:00:00', 'America/Monterrey'));

    $user = auditCommandUser();
    $cart = auditCommandCart($user, [
        'status' => MonitoringCartStatus::Completed->value,
        'completed_at' => now()->subMinutes(10),
        'updated_at' => now()->subMinutes(10),
    ]);
    $purchase = auditCommandPurchase($user, ['cart_id' => $cart->id]);
    $appointment = LaboratoryAppointment::query()->create([
        'customer_id' => $user->customer->id,
        'cart_id' => $cart->id,
        'brand' => LaboratoryBrand::OLAB->value,
        'confirmed_at' => now()->subMinutes(20),
        'created_at' => now()->subHour(),
        'updated_at' => now()->subMinutes(20),
    ]);
    PaymentAttempt::query()->create([
        'customer_id' => $user->customer->id,
        'cart_id' => $cart->id,
        'amount_cents' => 100000,
        'gateway' => 'efevoopay',
        'status' => PaymentAttempt::STATUS_APPROVED,
        'created_at' => now()->subMinutes(35),
        'updated_at' => now()->subMinutes(34),
        'processed_at' => now()->subMinutes(34),
    ]);

    foreach ([
        CartEventType::CartCreated,
        CartEventType::CheckoutStarted,
        CartEventType::AppointmentConfirmed,
        CartEventType::PaymentStarted,
        CartEventType::PaymentApproved,
        CartEventType::PurchaseCreated,
        CartEventType::CartCompleted,
    ] as $event) {
        CartEvent::query()->create([
            'cart_id' => $cart->id,
            'event' => $event->value,
            'metadata' => match ($event) {
                CartEventType::PurchaseCreated => ['laboratory_purchase_id' => $purchase->id],
                CartEventType::AppointmentConfirmed => ['laboratory_appointment_id' => $appointment->id],
                default => [],
            },
            'occurred_at' => now()->subMinutes(5),
        ]);
    }

    $this->artisan('carts:traceability-audit', ['--days' => 1])
        ->expectsOutputToContain('TRACEABILITY AUDIT')
        ->expectsOutputToContain('Explicit cart_id:                   1 (100.0%)')
        ->expectsOutputToContain('Approved payments without purchase: 0')
        ->expectsOutputToContain('Payment correlations:')
        ->expectsOutputToContain('Explicit:      1')
        ->assertExitCode(0);
});

it('reports new missing links, consistency issues, duplicates and legacy fallback', function () {
    Carbon::setTestNow(Carbon::parse('2026-08-20 12:00:00', 'America/Monterrey'));

    $user = auditCommandUser();
    $cart = auditCommandCart($user);

    PaymentAttempt::query()->create([
        'customer_id' => $user->customer->id,
        'amount_cents' => 100000,
        'gateway' => 'efevoopay',
        'status' => PaymentAttempt::STATUS_DECLINED,
        'created_at' => now()->subMinutes(50),
        'updated_at' => now()->subMinutes(49),
        'processed_at' => now()->subMinutes(49),
    ]);

    auditCommandPurchase($user);

    LaboratoryAppointment::query()->create([
        'customer_id' => $user->customer->id,
        'brand' => LaboratoryBrand::OLAB->value,
        'confirmed_at' => now()->subMinutes(45),
        'created_at' => now()->subMinutes(50),
        'updated_at' => now()->subMinutes(45),
    ]);

    $approvedWithoutPurchase = auditCommandCart(auditCommandUser());
    PaymentAttempt::query()->create([
        'customer_id' => $approvedWithoutPurchase->user->customer->id,
        'cart_id' => $approvedWithoutPurchase->id,
        'amount_cents' => 100000,
        'gateway' => 'efevoopay',
        'status' => PaymentAttempt::STATUS_APPROVED,
        'created_at' => now()->subMinutes(40),
        'updated_at' => now()->subMinutes(39),
        'processed_at' => now()->subMinutes(39),
    ]);

    $purchaseWithoutCompletedCart = auditCommandCart(auditCommandUser());
    auditCommandPurchase($purchaseWithoutCompletedCart->user, ['cart_id' => $purchaseWithoutCompletedCart->id]);

    auditCommandCart(auditCommandUser(), [
        'status' => MonitoringCartStatus::Completed->value,
        'completed_at' => now()->subMinutes(20),
        'updated_at' => now()->subMinutes(20),
    ]);

    $duplicateEventCart = auditCommandCart(auditCommandUser(), [
        'status' => MonitoringCartStatus::Completed->value,
        'completed_at' => now()->subMinutes(20),
        'updated_at' => now()->subMinutes(20),
    ]);
    $duplicatedPurchase = auditCommandPurchase($duplicateEventCart->user, ['cart_id' => $duplicateEventCart->id]);
    CartEvent::query()->create([
        'cart_id' => $duplicateEventCart->id,
        'event' => CartEventType::PurchaseCreated->value,
        'metadata' => ['laboratory_purchase_id' => $duplicatedPurchase->id],
        'occurred_at' => now()->subMinutes(10),
    ]);
    CartEvent::query()->create([
        'cart_id' => $duplicateEventCart->id,
        'event' => CartEventType::PurchaseCreated->value,
        'metadata' => ['laboratory_purchase_id' => $duplicatedPurchase->id],
        'occurred_at' => now()->subMinutes(9),
    ]);
    CartEvent::query()->create([
        'cart_id' => $duplicateEventCart->id,
        'event' => CartEventType::CartCompleted->value,
        'occurred_at' => now()->subMinutes(15),
    ]);

    $this->artisan('carts:traceability-audit', ['--days' => 1, '--strict' => true])
        ->expectsOutputToContain('PaymentAttempts without cart_id: 1')
        ->expectsOutputToContain('LaboratoryPurchases without cart_id: 1')
        ->expectsOutputToContain('LaboratoryAppointments without cart_id: 1')
        ->expectsOutputToContain('sospechoso')
        ->expectsOutputToContain('Approved payments without purchase: 1')
        ->expectsOutputToContain('Purchases without cart_completed: 1')
        ->expectsOutputToContain('Completed lab carts without purchase: 1')
        ->expectsOutputToContain('Suspicious duplicate events: 1')
        ->expectsOutputToContain('Sequence issues: 1')
        ->expectsOutputToContain('Legacy high:   1')
        ->assertExitCode(1);
});

it('does not report historical records outside the requested range', function () {
    Carbon::setTestNow(Carbon::parse('2026-08-20 12:00:00', 'America/Monterrey'));

    $oldUser = auditCommandUser();
    $oldAttempt = new PaymentAttempt([
        'customer_id' => $oldUser->customer->id,
        'amount_cents' => 100000,
        'gateway' => 'efevoopay',
        'status' => PaymentAttempt::STATUS_DECLINED,
    ]);
    $oldAttempt->created_at = now()->subDays(10);
    $oldAttempt->updated_at = now()->subDays(10);
    $oldAttempt->save();

    $this->artisan('carts:traceability-audit', ['--days' => 1])
        ->expectsOutputToContain('PaymentAttempts without cart_id: 0')
        ->assertExitCode(0);
});
