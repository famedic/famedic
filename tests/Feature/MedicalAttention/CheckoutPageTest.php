<?php

use App\Enums\MedicalSubscriptionType;
use App\Models\Documentation;
use App\Models\MedicalAttentionSubscription;
use App\Models\User;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Queue;

beforeEach(function () {
    Queue::fake();

    Config::set('famedic.medical_attention_trial_enabled', false);

    Documentation::create([
        'privacy_policy' => 'Política de privacidad de prueba.',
        'terms_of_service' => 'Términos de servicio de prueba.',
    ]);
});

test('checkout page is reachable for user without active membership', function () {
    $user = medicalAttentionUser();

    $this->actingAs($user)
        ->get(route('medical-attention.checkout'))
        ->assertOk();
});

test('checkout redirects to medical attention when membership is active', function () {
    $user = medicalAttentionUser([
        'medical_attention_subscription_expires_at' => now()->addYear(),
    ]);

    MedicalAttentionSubscription::create([
        'customer_id' => $user->customer->id,
        'start_date' => now(),
        'end_date' => now()->addYear(),
        'price_cents' => 30000,
        'type' => MedicalSubscriptionType::REGULAR,
    ]);

    $this->actingAs($user)
        ->get(route('medical-attention.checkout'))
        ->assertRedirect(route('medical-attention'));
});

test('regular subscription purchase is rejected without payment method', function () {
    $user = medicalAttentionUser();

    $this->actingAs($user)
        ->post(route('medical-attention.subscription'), [])
        ->assertSessionHasErrors('payment_method');

    expect(MedicalAttentionSubscription::count())->toBe(0);
});

test('regular subscription purchase is rejected when membership is already active', function () {
    $user = medicalAttentionUser([
        'medical_attention_subscription_expires_at' => now()->addYear(),
    ]);

    MedicalAttentionSubscription::create([
        'customer_id' => $user->customer->id,
        'start_date' => now(),
        'end_date' => now()->addYear(),
        'price_cents' => 30000,
        'type' => MedicalSubscriptionType::REGULAR,
    ]);

    $this->actingAs($user)
        ->post(route('medical-attention.subscription'), [
            'payment_method' => 'odessa',
        ])
        ->assertForbidden();
});

test('free trial endpoint is forbidden when trial is disabled', function () {
    $user = medicalAttentionUser();

    $this->actingAs($user)
        ->post(route('free-medical-attention.subscription'))
        ->assertForbidden();

    expect(MedicalAttentionSubscription::count())->toBe(0);
});

test('user with active trial keeps active membership view', function () {
    $user = medicalAttentionUser([
        'medical_attention_subscription_expires_at' => now()->addDays(10),
    ]);

    MedicalAttentionSubscription::create([
        'customer_id' => $user->customer->id,
        'start_date' => now()->subDays(5),
        'end_date' => now()->addDays(10),
        'price_cents' => 0,
        'type' => MedicalSubscriptionType::TRIAL,
    ]);

    $this->actingAs($user)
        ->get(route('medical-attention'))
        ->assertOk();

    expect($user->customer->fresh()->medical_attention_subscription_is_active)->toBeTrue();
});

test('medical attention page is reachable for new user without subscription', function () {
    $user = medicalAttentionUser();

    $this->actingAs($user)
        ->get(route('medical-attention'))
        ->assertOk();
});
