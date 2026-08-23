<?php

use App\Enums\PaymentAuthenticationAttemptEventType;
use App\Enums\PaymentAuthenticationAttemptStatus;
use App\Enums\PaymentAuthenticationRecoveryContextStatus;
use App\Enums\PaymentAuthenticationRecoveryContextType;
use App\Models\PaymentAuthenticationAttempt;
use App\Models\PaymentAuthenticationAttemptEvent;
use App\Models\PaymentAuthenticationRecoveryContext;
use App\Models\Transaction;
use App\Services\PaymentAuthenticationAttempts\PaymentAuthenticationAttemptDateRange;
use App\Services\PaymentAuthenticationAttempts\PaymentAuthenticationRecoveryAnalyzer;
use App\Services\PaymentAuthenticationAttempts\PaymentAuthenticationRecoveryMetrics;
use App\Support\PaymentAuthenticationRecoveryPolicy;
use Illuminate\Support\Str;

function metricsRecoveryUser(): \App\Models\User
{
    return \App\Models\User::factory()
        ->withCompleteProfile()
        ->withRegularCustomer()
        ->create(['documentation_accepted_at' => now()])
        ->fresh(['customer']);
}

function metricsDeclinedContext(\App\Models\User $user, PaymentAuthenticationRecoveryContextType $type = PaymentAuthenticationRecoveryContextType::MedicalAttentionCheckout): PaymentAuthenticationRecoveryContext
{
    $context = PaymentAuthenticationRecoveryContext::create([
        'context_uuid' => (string) Str::uuid(),
        'customer_id' => $user->customer->id,
        'context_type' => $type,
        'status' => PaymentAuthenticationRecoveryContextStatus::RecoveryAvailable,
        'return_route_name' => $type->returnRouteName(),
        'context_data' => ['step' => 'payment'],
        'started_at' => now()->subHour(),
        'expires_at' => now()->addMinutes(30),
    ]);

    $attempt = PaymentAuthenticationAttempt::create([
        'attempt_uuid' => (string) Str::uuid(),
        'customer_id' => $user->customer->id,
        'recovery_context_id' => $context->id,
        'operation_type' => PaymentAuthenticationAttempt::OPERATION_CARD_VERIFICATION_3DS,
        'provider' => PaymentAuthenticationAttempt::PROVIDER_EFEVOOPAY,
        'status' => PaymentAuthenticationAttemptStatus::Declined->value,
        'merchant_reference' => 'EFV3DS-'.Str::upper(Str::random(8)),
        'attempt_number' => 1,
        'support_reference' => 'SUP-'.Str::upper(Str::random(6)),
        'started_at' => now()->subHour(),
        'finished_at' => now()->subHour(),
        'expires_at' => now()->addMinutes(5),
    ]);

    $context->update(['root_authentication_attempt_id' => $attempt->id]);

    return $context->fresh(['rootAuthenticationAttempt']);
}

it('counts eligible recovery context once even with multiple attempts', function () {
    $user = metricsRecoveryUser();
    $context = metricsDeclinedContext($user);

    PaymentAuthenticationAttempt::create([
        'attempt_uuid' => (string) Str::uuid(),
        'customer_id' => $user->customer->id,
        'recovery_context_id' => $context->id,
        'retry_of_attempt_id' => $context->root_authentication_attempt_id,
        'operation_type' => PaymentAuthenticationAttempt::OPERATION_CARD_VERIFICATION_3DS,
        'provider' => PaymentAuthenticationAttempt::PROVIDER_EFEVOOPAY,
        'status' => PaymentAuthenticationAttemptStatus::Declined->value,
        'merchant_reference' => 'EFV3DS-'.Str::upper(Str::random(8)),
        'attempt_number' => 2,
        'support_reference' => 'SUP-'.Str::upper(Str::random(6)),
        'started_at' => now()->subMinutes(30),
        'finished_at' => now()->subMinutes(30),
        'expires_at' => now()->addMinutes(5),
    ]);

    $range = PaymentAuthenticationAttemptDateRange::fromFilters(['period' => '7d']);
    $metrics = app(PaymentAuthenticationRecoveryMetrics::class)->summarize(['period' => '7d'], $range);

    expect($metrics['eligible_terminal'])->toBe(1);
});

it('does not count selected different card as payment recovered', function () {
    $user = metricsRecoveryUser();
    $context = metricsDeclinedContext($user);
    $attempt = $context->rootAuthenticationAttempt;

    PaymentAuthenticationAttemptEvent::create([
        'event_uuid' => (string) Str::uuid(),
        'payment_authentication_attempt_id' => $attempt->id,
        'event_type' => PaymentAuthenticationAttemptEventType::ChangedCard->value,
        'source' => 'frontend',
        'occurred_at' => now(),
    ]);

    $analyzer = app(PaymentAuthenticationRecoveryAnalyzer::class);
    $flags = $analyzer->batchIntentionFlags([$attempt->id]);

    expect($analyzer->paymentRecovered($context))->toBeFalse()
        ->and($flags[$attempt->id]['selected_different_card'] ?? false)->toBeTrue();
});

it('counts card verified as authentication recovered but not payment recovered', function () {
    $user = metricsRecoveryUser();
    $context = metricsDeclinedContext($user, PaymentAuthenticationRecoveryContextType::PaymentMethodSettings);
    $root = $context->rootAuthenticationAttempt;

    $completed = PaymentAuthenticationAttempt::create([
        'attempt_uuid' => (string) Str::uuid(),
        'customer_id' => $user->customer->id,
        'recovery_context_id' => $context->id,
        'retry_of_attempt_id' => $root->id,
        'operation_type' => PaymentAuthenticationAttempt::OPERATION_CARD_VERIFICATION_3DS,
        'provider' => PaymentAuthenticationAttempt::PROVIDER_EFEVOOPAY,
        'status' => PaymentAuthenticationAttemptStatus::Completed->value,
        'merchant_reference' => 'EFV3DS-'.Str::upper(Str::random(8)),
        'attempt_number' => 2,
        'support_reference' => 'SUP-'.Str::upper(Str::random(6)),
        'started_at' => now()->subMinutes(20),
        'finished_at' => now()->subMinutes(19),
        'expires_at' => now()->addMinutes(5),
    ]);

    $context->update([
        'status' => PaymentAuthenticationRecoveryContextStatus::CardVerified,
        'card_verified_at' => now()->subMinutes(19),
    ]);

    $analyzer = app(PaymentAuthenticationRecoveryAnalyzer::class);

    expect($analyzer->authenticationRecovered($context->fresh()))->toBeTrue()
        ->and($analyzer->paymentRecovered($context->fresh()))->toBeFalse();
});

it('counts paypal payment recovered only with captured transaction and purchase outcome', function () {
    $user = metricsRecoveryUser();
    $context = metricsDeclinedContext($user, PaymentAuthenticationRecoveryContextType::LaboratoryCheckout);

    $transaction = Transaction::create([
        'transaction_amount_cents' => 50000,
        'payment_method' => 'paypal',
        'payment_provider' => 'paypal',
        'gateway' => 'paypal',
        'reference_id' => 'PP-METRICS-1',
        'provider_order_id' => 'PP-METRICS-1',
        'payment_status' => 'captured',
        'details' => ['customer_id' => $user->customer->id],
    ]);

    $purchase = \App\Models\LaboratoryPurchase::factory()->create([
        'customer_id' => $user->customer->id,
        'brand' => 'swisslab',
        'gda_order_id' => 'GDA-METRICS-1',
        'name' => 'Paciente',
        'paternal_lastname' => 'Metrics',
        'maternal_lastname' => 'Test',
        'phone' => '8111111111',
        'phone_country' => 'MX',
        'birth_date' => '1990-01-01',
        'street' => 'Calle',
        'number' => '1',
        'neighborhood' => 'Centro',
        'state' => 'Nuevo Leon',
        'city' => 'Monterrey',
        'zipcode' => '64000',
        'total_cents' => 50000,
    ]);
    $purchase->transactions()->attach($transaction);

    $context->update([
        'status' => PaymentAuthenticationRecoveryContextStatus::Recovered,
        'recovery_method' => 'paypal',
        'recovered_transaction_id' => $transaction->id,
        'recovered_at' => now(),
        'recovery_transaction_id' => null,
    ]);

    expect(app(PaymentAuthenticationRecoveryAnalyzer::class)->paymentRecovered($context->fresh()))->toBeTrue();
});

it('paypal create-order timeout returns sanitized 503 without exception message', function () {
    $user = metricsRecoveryUser();
    $context = metricsDeclinedContext($user);
    $attempt = $context->rootAuthenticationAttempt;

    PaymentAuthenticationAttemptEvent::create([
        'event_uuid' => (string) Str::uuid(),
        'payment_authentication_attempt_id' => $attempt->id,
        'event_type' => PaymentAuthenticationAttemptEventType::ChangedToPaypal->value,
        'source' => 'frontend',
        'occurred_at' => now(),
    ]);

    $context->update(['status' => PaymentAuthenticationRecoveryContextStatus::RecoveryAvailable]);

    $paypal = Mockery::mock(\App\Services\PayPalService::class);
    $paypal->shouldReceive('createOrder')->once()->andThrow(new RuntimeException('timeout simulated internal'));
    app()->instance(\App\Services\PayPalService::class, $paypal);

    $response = test()->actingAs($user)
        ->postJson(route('medical-attention.paypal.create-order'), [
            'recovery_context_uuid' => $context->context_uuid,
        ]);

    $response->assertStatus(503)
        ->assertJsonMissing(['trace'])
        ->assertJson([
            'error' => 'recovery_confirmation_pending',
        ]);

    expect($response->json('message'))->not->toContain('timeout simulated internal');
});

it('denies admin metrics to regular users without admin account', function () {
    $user = metricsRecoveryUser();

    $this->actingAs($user)
        ->get(route('admin.payment-authentication-attempts.index'))
        ->assertNotFound();
});
