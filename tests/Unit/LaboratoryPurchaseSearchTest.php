<?php

use App\Enums\LaboratoryBrand;
use App\Models\Customer;
use App\Models\LaboratoryPurchase;
use App\Models\User;

function makeLaboratoryPurchaseForSearch(array $purchaseAttributes = [], array $userAttributes = []): LaboratoryPurchase
{
    $user = User::factory()->create(array_merge([
        'name' => 'Eulalio',
        'paternal_lastname' => 'Medina',
        'maternal_lastname' => 'Externo',
    ], $userAttributes));

    $customer = Customer::factory()->create([
        'user_id' => $user->id,
    ]);

    return LaboratoryPurchase::query()->create(array_merge([
        'brand' => LaboratoryBrand::OLAB->value,
        'gda_order_id' => uniqid('gda-', true),
        'name' => 'Paciente',
        'paternal_lastname' => 'Demo',
        'maternal_lastname' => 'Prueba',
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
        'customer_id' => $customer->id,
    ], $purchaseAttributes));
}

test('laboratory purchase search matches patient full name on purchase record', function () {
    $purchase = makeLaboratoryPurchaseForSearch([
        'name' => 'Eulalio',
        'paternal_lastname' => 'Medina',
        'maternal_lastname' => '',
    ]);

    $ids = LaboratoryPurchase::query()
        ->filter(['search' => 'eulalio medina'])
        ->pluck('id');

    expect($ids)->toContain($purchase->id);
});

test('laboratory purchase search matches purchaser full name on linked user', function () {
    $purchase = makeLaboratoryPurchaseForSearch([], [
        'name' => 'Eulalio',
        'paternal_lastname' => 'Medina',
        'maternal_lastname' => '',
    ]);

    $ids = LaboratoryPurchase::query()
        ->filter(['search' => 'eulalio medina'])
        ->pluck('id');

    expect($ids)->toContain($purchase->id);
});

test('laboratory purchase search matches reversed full name terms', function () {
    $purchase = makeLaboratoryPurchaseForSearch([
        'name' => 'Eulalio',
        'paternal_lastname' => 'Medina',
        'maternal_lastname' => '',
    ]);

    $ids = LaboratoryPurchase::query()
        ->filter(['search' => 'medina eulalio'])
        ->pluck('id');

    expect($ids)->toContain($purchase->id);
});

test('laboratory purchase search still matches single name term', function () {
    $purchase = makeLaboratoryPurchaseForSearch([
        'name' => 'Eulalio',
        'paternal_lastname' => 'Medina',
        'maternal_lastname' => '',
    ]);

    $ids = LaboratoryPurchase::query()
        ->filter(['search' => 'eulalio'])
        ->pluck('id');

    expect($ids)->toContain($purchase->id);
});
