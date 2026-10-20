<?php

use App\Models\Customer;
use App\Models\User;

function makeCustomerForSearch(array $userAttributes = []): Customer
{
    $user = User::factory()->create(array_merge([
        'name' => 'Eulalio',
        'paternal_lastname' => 'Medina',
        'maternal_lastname' => 'Externo',
        'email' => 'eulalio.medina@example.test',
    ], $userAttributes));

    return Customer::factory()->withRegularAccount()->create([
        'user_id' => $user->id,
    ]);
}

test('customer search matches user full name with multiple words', function () {
    $customer = makeCustomerForSearch();

    $ids = Customer::query()
        ->filter(['search' => 'eulalio medina'])
        ->pluck('id');

    expect($ids)->toContain($customer->id);
});

test('customer search matches reversed full name terms', function () {
    $customer = makeCustomerForSearch();

    $ids = Customer::query()
        ->filter(['search' => 'medina eulalio'])
        ->pluck('id');

    expect($ids)->toContain($customer->id);
});

test('customer search still matches single name term', function () {
    $customer = makeCustomerForSearch();

    $ids = Customer::query()
        ->filter(['search' => 'eulalio'])
        ->pluck('id');

    expect($ids)->toContain($customer->id);
});
