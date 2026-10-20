<?php

use App\Enums\MedicalSubscriptionType;
use App\Models\MedicalAttentionSubscription;
use App\Models\User;

test('medical attention subscription search matches user full name with multiple words', function () {
    $user = User::factory()->withRegularCustomer()->create([
        'name' => 'Leobardo',
        'paternal_lastname' => 'Lozano',
        'maternal_lastname' => 'Perez',
    ]);

    $subscription = MedicalAttentionSubscription::query()->create([
        'customer_id' => $user->customer->id,
        'start_date' => now()->subDay(),
        'end_date' => now()->addYear(),
        'price_cents' => 30000,
        'type' => MedicalSubscriptionType::REGULAR,
    ]);

    $ids = MedicalAttentionSubscription::query()
        ->filter(['search' => 'leobardo lozano'])
        ->pluck('id');

    expect($ids)->toContain($subscription->id);
});

test('medical attention subscription search matches reversed full name terms', function () {
    $user = User::factory()->withRegularCustomer()->create([
        'name' => 'Leobardo',
        'paternal_lastname' => 'Lozano',
        'maternal_lastname' => 'Perez',
    ]);

    $subscription = MedicalAttentionSubscription::query()->create([
        'customer_id' => $user->customer->id,
        'start_date' => now()->subDay(),
        'end_date' => now()->addYear(),
        'price_cents' => 30000,
        'type' => MedicalSubscriptionType::REGULAR,
    ]);

    $ids = MedicalAttentionSubscription::query()
        ->filter(['search' => 'lozano leobardo'])
        ->pluck('id');

    expect($ids)->toContain($subscription->id);
});
