<?php

use App\Enums\Gender;
use App\Enums\LaboratoryBrand;
use App\Models\Documentation;
use App\Models\LaboratoryPurchase;
use App\Models\User;
use Illuminate\Support\Facades\Queue;

beforeEach(function () {
    Queue::fake();

    Documentation::create([
        'privacy_policy' => 'Política de privacidad de prueba.',
        'terms_of_service' => 'Términos de servicio de prueba.',
    ]);
});

function laboratoryCheckoutUser(): User
{
    return User::factory()
        ->withCompleteProfile()
        ->withRegularCustomer()
        ->create([
            'documentation_accepted_at' => now(),
        ])
        ->fresh(['customer']);
}

test('laboratory checkout get redirects to laboratory tests when cart is empty', function () {
    $user = laboratoryCheckoutUser();

    $this->actingAs($user)
        ->get(route('laboratory.checkout', [
            'laboratory_brand' => LaboratoryBrand::SWISSLAB,
        ]))
        ->assertRedirect(route('laboratory-tests', [
            'laboratory_brand' => LaboratoryBrand::SWISSLAB,
        ]));
});

test('duplicate laboratory checkout store redirects to recent purchase instead of laboratory tests', function () {
    $user = laboratoryCheckoutUser();
    $brand = LaboratoryBrand::SWISSLAB;

    $purchase = LaboratoryPurchase::query()->create([
        'customer_id' => $user->customer->id,
        'brand' => $brand,
        'gda_order_id' => 123456,
        'name' => 'Juan',
        'paternal_lastname' => 'Pérez',
        'maternal_lastname' => 'López',
        'phone' => '8112345678',
        'phone_country' => 'MX',
        'birth_date' => '1990-01-01',
        'gender' => Gender::MALE,
        'street' => 'Calle 1',
        'number' => '100',
        'neighborhood' => 'Centro',
        'state' => 'Nuevo León',
        'city' => 'Monterrey',
        'zipcode' => '64000',
        'total_cents' => 104929,
    ]);

    $this->actingAs($user)
        ->post(route('laboratory.checkout.store', [
            'laboratory_brand' => $brand,
        ]), [
            'total' => 104929,
            'address' => 1,
            'payment_method' => '1',
        ])
        ->assertRedirect(route('laboratory-purchases.show', [
            'laboratory_purchase' => $purchase,
        ]));
});

test('laboratory checkout store with empty cart and no recent purchase redirects to purchases index', function () {
    $user = laboratoryCheckoutUser();

    $this->actingAs($user)
        ->post(route('laboratory.checkout.store', [
            'laboratory_brand' => LaboratoryBrand::SWISSLAB,
        ]), [
            'total' => 104929,
            'address' => 1,
            'payment_method' => '1',
        ])
        ->assertRedirect(route('laboratory-purchases.index'));
});
