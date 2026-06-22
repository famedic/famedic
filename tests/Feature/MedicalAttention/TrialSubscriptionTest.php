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

test('free trial endpoint is forbidden when trial is disabled', function () {
    $user = medicalAttentionUser();

    $this->actingAs($user)
        ->post(route('free-medical-attention.subscription'))
        ->assertForbidden();

    expect(MedicalAttentionSubscription::count())->toBe(0);
});

test('free trial endpoint does not create trial subscriptions when disabled', function () {
    $user = medicalAttentionUser();

    $this->actingAs($user)
        ->post(route('free-medical-attention.subscription'));

    expect(MedicalAttentionSubscription::query()
        ->where('type', MedicalSubscriptionType::TRIAL)
        ->count())->toBe(0);
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

test('user with expired trial can access medical attention page for regular purchase', function () {
    $user = medicalAttentionUser([
        'medical_attention_subscription_expires_at' => now()->subDay(),
    ]);

    MedicalAttentionSubscription::create([
        'customer_id' => $user->customer->id,
        'start_date' => now()->subDays(40),
        'end_date' => now()->subDay(),
        'price_cents' => 0,
        'type' => MedicalSubscriptionType::TRIAL,
    ]);

    $this->actingAs($user)
        ->get(route('medical-attention'))
        ->assertOk();

    expect($user->customer->fresh()->medical_attention_subscription_is_active)->toBeFalse();
});

test('free trial endpoint works when trial is enabled and user has no subscriptions', function () {
    Config::set('famedic.medical_attention_trial_enabled', true);

    $user = medicalAttentionUser();

    $this->actingAs($user)
        ->post(route('free-medical-attention.subscription'))
        ->assertRedirect(route('medical-attention'));

    expect(MedicalAttentionSubscription::query()
        ->where('type', MedicalSubscriptionType::TRIAL)
        ->where('customer_id', $user->customer->id)
        ->exists())->toBeTrue();
});

test('medical attention page is reachable for new user without subscription', function () {
    $user = medicalAttentionUser();

    $this->actingAs($user)
        ->get(route('medical-attention'))
        ->assertOk();
});
