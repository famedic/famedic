<?php

use App\Enums\Gender;
use App\Enums\LaboratoryBrand;
use App\Models\LaboratoryCartItem;
use App\Models\LaboratoryPurchase;
use App\Models\LaboratoryTest;
use App\Models\Transaction;
use App\Models\User;
use App\Services\Laboratory\LaboratoryCheckoutFlowEligibility;

beforeEach(function () {
    $this->withoutMiddleware([
        \App\Http\Middleware\RedirectIfEmptyLaboratoryCartItems::class,
        \App\Http\Middleware\RedirectIfUserProfileIsIncomplete::class,
        \App\Http\Middleware\EnsureDocumentationIsAccepted::class,
        \App\Http\Middleware\EnsurePhoneIsVerified::class,
        \Illuminate\Auth\Middleware\EnsureEmailIsVerified::class,
    ]);

    $this->eligibility = app(LaboratoryCheckoutFlowEligibility::class);
});

function appointmentFirstFlowUser(): User
{
    return User::factory()
        ->withCompleteProfile()
        ->withRegularCustomer()
        ->create([
            'documentation_accepted_at' => now(),
        ])
        ->fresh(['customer']);
}

function seedLaboratoryCartForFlow(
    User $user,
    LaboratoryBrand $brand,
    bool $requiresAppointment = true,
    int $priceCents = 80000,
): LaboratoryTest {
    $test = LaboratoryTest::factory()->create([
        'brand' => $brand->value,
        'requires_appointment' => $requiresAppointment,
        'famedic_price_cents' => $priceCents,
    ]);

    LaboratoryCartItem::factory()->create([
        'customer_id' => $user->customer->id,
        'laboratory_test_id' => $test->id,
    ]);

    return $test;
}

function attachCheckoutTransaction(
    LaboratoryPurchase $purchase,
    array $transactionAttributes = [],
): Transaction {
    $transaction = Transaction::query()->create(array_merge([
        'transaction_amount_cents' => (int) $purchase->total_cents,
        'payment_method' => 'efevoopay',
        'gateway' => 'efevoopay',
        'payment_status' => 'completed',
        'reference_id' => 'ref-flow-'.$purchase->id,
        'gateway_processed_at' => now(),
    ], $transactionAttributes));

    $purchase->transactions()->attach($transaction->id);

    return $transaction;
}

function createFlowLaboratoryPurchase(
    User $user,
    LaboratoryBrand $brand,
    array $purchaseAttributes = [],
    ?array $transactionAttributes = [],
): LaboratoryPurchase {
    $purchase = LaboratoryPurchase::query()->create(array_merge([
        'customer_id' => $user->customer->id,
        'brand' => $brand->value,
        'gda_order_id' => 'gda-flow-'.fake()->unique()->numerify('######'),
        'name' => 'Paciente',
        'paternal_lastname' => 'Flujo',
        'maternal_lastname' => 'Prueba',
        'phone' => '8111111111',
        'phone_country' => 'MX',
        'birth_date' => '1990-01-01',
        'gender' => Gender::MALE,
        'street' => 'Calle',
        'number' => '1',
        'neighborhood' => 'Centro',
        'state' => 'Nuevo Leon',
        'city' => 'Monterrey',
        'zipcode' => '64000',
        'total_cents' => 80000,
    ], $purchaseAttributes));

    if ($transactionAttributes !== null) {
        attachCheckoutTransaction($purchase, $transactionAttributes ?? []);
    }

    return $purchase->refresh();
}

function seedValidPurchases(User $user, int $count, LaboratoryBrand $brand = LaboratoryBrand::OLAB): void
{
    for ($i = 0; $i < $count; $i++) {
        createFlowLaboratoryPurchase($user, $brand);
    }
}

test('uses appointment first flow with zero valid purchases and cart requiring appointment', function () {
    $user = appointmentFirstFlowUser();
    seedLaboratoryCartForFlow($user, LaboratoryBrand::OLAB, requiresAppointment: true);

    expect($this->eligibility->usesAppointmentFirstFlow($user->customer, LaboratoryBrand::OLAB))->toBeTrue();
});

test('does not use appointment first flow with one valid purchase and cart requiring appointment', function () {
    $user = appointmentFirstFlowUser();
    seedValidPurchases($user, 1);
    seedLaboratoryCartForFlow($user, LaboratoryBrand::OLAB, requiresAppointment: true);

    expect($this->eligibility->usesAppointmentFirstFlow($user->customer, LaboratoryBrand::OLAB))->toBeFalse();
});

test('does not use appointment first flow with two valid purchases and cart requiring appointment', function () {
    $user = appointmentFirstFlowUser();
    seedValidPurchases($user, 2);
    seedLaboratoryCartForFlow($user, LaboratoryBrand::OLAB, requiresAppointment: true);

    expect($this->eligibility->usesAppointmentFirstFlow($user->customer, LaboratoryBrand::OLAB))->toBeFalse();
});

test('does not use appointment first flow with three or more valid purchases and cart requiring appointment', function () {
    $user = appointmentFirstFlowUser();
    seedValidPurchases($user, 3);
    seedLaboratoryCartForFlow($user, LaboratoryBrand::OLAB, requiresAppointment: true);

    expect($this->eligibility->usesAppointmentFirstFlow($user->customer, LaboratoryBrand::OLAB))->toBeFalse();
});

test('does not use appointment first flow when cart does not require appointment regardless of purchase count', function () {
    $user = appointmentFirstFlowUser();
    seedValidPurchases($user, 0);
    seedLaboratoryCartForFlow($user, LaboratoryBrand::OLAB, requiresAppointment: false);

    expect($this->eligibility->usesAppointmentFirstFlow($user->customer, LaboratoryBrand::OLAB))->toBeFalse();

    seedValidPurchases($user, 3);
    expect($this->eligibility->usesAppointmentFirstFlow($user->customer, LaboratoryBrand::OLAB))->toBeFalse();
});

test('does not count soft-deleted purchases', function () {
    $user = appointmentFirstFlowUser();
    $purchase = createFlowLaboratoryPurchase($user, LaboratoryBrand::OLAB);
    $purchase->delete();

    seedLaboratoryCartForFlow($user, LaboratoryBrand::OLAB, requiresAppointment: true);

    expect($this->eligibility->countValidCompletedPurchases($user->customer))->toBe(0)
        ->and($this->eligibility->usesAppointmentFirstFlow($user->customer, LaboratoryBrand::OLAB))->toBeTrue();
});

test('does not count cancelled purchases', function () {
    $user = appointmentFirstFlowUser();
    createFlowLaboratoryPurchase($user, LaboratoryBrand::OLAB, [
        'cancelled_at' => now(),
    ]);

    seedLaboratoryCartForFlow($user, LaboratoryBrand::OLAB, requiresAppointment: true);

    expect($this->eligibility->countValidCompletedPurchases($user->customer))->toBe(0);
});

test('does not count incomplete purchases without checkout transaction', function () {
    $user = appointmentFirstFlowUser();
    createFlowLaboratoryPurchase($user, LaboratoryBrand::OLAB, transactionAttributes: null);

    seedLaboratoryCartForFlow($user, LaboratoryBrand::OLAB, requiresAppointment: true);

    expect($this->eligibility->countValidCompletedPurchases($user->customer))->toBe(0);
});

test('does not count purchases with failed or pending transactions', function () {
    $user = appointmentFirstFlowUser();

    createFlowLaboratoryPurchase($user, LaboratoryBrand::OLAB, transactionAttributes: [
        'payment_status' => 'failed',
    ]);
    createFlowLaboratoryPurchase($user, LaboratoryBrand::OLAB, transactionAttributes: [
        'payment_status' => 'pending',
    ]);

    seedLaboratoryCartForFlow($user, LaboratoryBrand::OLAB, requiresAppointment: true);

    expect($this->eligibility->countValidCompletedPurchases($user->customer))->toBe(0);
});

test('counts zero total purchases completed with coupon balance', function () {
    $user = appointmentFirstFlowUser();

    createFlowLaboratoryPurchase($user, LaboratoryBrand::OLAB, [
        'total_cents' => 0,
    ], [
        'transaction_amount_cents' => 0,
        'payment_method' => 'coupon_balance',
        'gateway' => 'coupon_balance',
        'payment_status' => null,
        'gateway_status' => 'completed',
        'reference_id' => 'COUPON-99',
    ]);

    seedLaboratoryCartForFlow($user, LaboratoryBrand::OLAB, requiresAppointment: true);

    expect($this->eligibility->countValidCompletedPurchases($user->customer))->toBe(1)
        ->and($this->eligibility->usesAppointmentFirstFlow($user->customer, LaboratoryBrand::OLAB))->toBeFalse();
});

test('counts refunded purchases because checkout was completed', function () {
    $user = appointmentFirstFlowUser();

    createFlowLaboratoryPurchase($user, LaboratoryBrand::OLAB, transactionAttributes: [
        'payment_status' => 'refunded',
    ]);

    seedLaboratoryCartForFlow($user, LaboratoryBrand::OLAB, requiresAppointment: true);

    expect($this->eligibility->countValidCompletedPurchases($user->customer))->toBe(1)
        ->and($this->eligibility->usesAppointmentFirstFlow($user->customer, LaboratoryBrand::OLAB))->toBeFalse();
});

test('does not count simulated checkout transactions', function () {
    $user = appointmentFirstFlowUser();

    createFlowLaboratoryPurchase($user, LaboratoryBrand::OLAB, transactionAttributes: [
        'details' => ['simulated' => true],
    ]);

    seedLaboratoryCartForFlow($user, LaboratoryBrand::OLAB, requiresAppointment: true);

    expect($this->eligibility->countValidCompletedPurchases($user->customer))->toBe(0);
});

test('counts purchases globally across olab and swisslab brands', function () {
    $user = appointmentFirstFlowUser();

    createFlowLaboratoryPurchase($user, LaboratoryBrand::OLAB);
    createFlowLaboratoryPurchase($user, LaboratoryBrand::SWISSLAB);

    seedLaboratoryCartForFlow($user, LaboratoryBrand::OLAB, requiresAppointment: true);

    expect($this->eligibility->countValidCompletedPurchases($user->customer))->toBe(2)
        ->and($this->eligibility->usesAppointmentFirstFlow($user->customer, LaboratoryBrand::OLAB))->toBeFalse();

    createFlowLaboratoryPurchase($user, LaboratoryBrand::SWISSLAB);

    expect($this->eligibility->countValidCompletedPurchases($user->customer))->toBe(3)
        ->and($this->eligibility->usesAppointmentFirstFlow($user->customer, LaboratoryBrand::OLAB))->toBeFalse();
});

test('checkout exposes usesAppointmentFirstFlow for first-time customers without changing existing props', function () {
    $user = appointmentFirstFlowUser();
    seedLaboratoryCartForFlow($user, LaboratoryBrand::OLAB, requiresAppointment: true);

    $this->actingAs($user)
        ->get(route('laboratory.checkout', ['laboratory_brand' => LaboratoryBrand::OLAB]))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('LaboratoryCheckout')
            ->where('requiresAppointment', true)
            ->where('usesAppointmentFirstFlow', true)
        );
});

test('checkout exposes usesAppointmentFirstFlow as false when cart does not require appointment', function () {
    $user = appointmentFirstFlowUser();
    seedLaboratoryCartForFlow($user, LaboratoryBrand::OLAB, requiresAppointment: false);

    $this->actingAs($user)
        ->get(route('laboratory.checkout', ['laboratory_brand' => LaboratoryBrand::OLAB]))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('requiresAppointment', false)
            ->where('usesAppointmentFirstFlow', false)
        );
});
