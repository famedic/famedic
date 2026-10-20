<?php

use App\Enums\MonitoringCartStatus;
use App\Enums\MonitoringCartType;
use App\Models\Cart;
use App\Models\User;

test('cart search matches user full name with multiple words', function () {
    $user = User::factory()->create([
        'name' => 'Eulalio',
        'paternal_lastname' => 'Medina',
        'maternal_lastname' => 'Externo',
    ]);

    $cart = Cart::query()->create([
        'user_id' => $user->id,
        'type' => MonitoringCartType::Lab->value,
        'status' => MonitoringCartStatus::Active->value,
        'total' => 100,
    ]);

    $ids = Cart::query()
        ->adminMonitoringFilter(['search' => 'eulalio medina'])
        ->pluck('id');

    expect($ids)->toContain($cart->id);
});

test('cart search matches reversed full name terms', function () {
    $user = User::factory()->create([
        'name' => 'Eulalio',
        'paternal_lastname' => 'Medina',
        'maternal_lastname' => 'Externo',
    ]);

    $cart = Cart::query()->create([
        'user_id' => $user->id,
        'type' => MonitoringCartType::Lab->value,
        'status' => MonitoringCartStatus::Active->value,
        'total' => 100,
    ]);

    $ids = Cart::query()
        ->adminMonitoringFilter(['search' => 'medina eulalio'])
        ->pluck('id');

    expect($ids)->toContain($cart->id);
});
