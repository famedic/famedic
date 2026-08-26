<?php

use App\Enums\PaymentAuthenticationAttemptEventType;
use App\Enums\PaymentAuthenticationAttemptStatus;
use App\Enums\PaymentAuthenticationRecoveryContextStatus;
use App\Enums\PaymentAuthenticationRecoveryContextType;
use App\Models\Customer;
use App\Models\Efevoo3dsSession;
use App\Models\EfevooToken;
use App\Models\PaymentAuthenticationAttempt;
use App\Models\PaymentAuthenticationAttemptEvent;
use App\Models\PaymentAuthenticationRecoveryContext;
use App\Models\User;
use App\Support\EfevooPayGatewayMode;
use App\Support\PaymentAuthentication3dsResultResource;
use App\Support\PaymentAuthenticationLocalPaymentMethodPersistence;
use Illuminate\Support\Str;

beforeEach(function () {
    config([
        'efevoopay.gateway' => 'live',
        'efevoopay.environment' => 'production',
        'efevoopay.requires_3ds' => true,
        'efevoopay.sensitive_card_data.containment_enabled' => true,
        'efevoopay.local_real_tests.enabled' => false,
    ]);
});

function persistenceUser(): User
{
    return User::factory()
        ->withCompleteProfile()
        ->withRegularCustomer()
        ->create(['documentation_accepted_at' => now()])
        ->fresh(['customer']);
}

function persistenceContext(Customer $customer): PaymentAuthenticationRecoveryContext
{
    return PaymentAuthenticationRecoveryContext::create([
        'context_uuid' => (string) Str::uuid(),
        'customer_id' => $customer->id,
        'context_type' => PaymentAuthenticationRecoveryContextType::PaymentMethodSettings->value,
        'status' => PaymentAuthenticationRecoveryContextStatus::AuthenticationInProgress->value,
        'return_route_name' => PaymentAuthenticationRecoveryContextType::PaymentMethodSettings->returnRouteName(),
        'context_data' => [],
        'started_at' => now(),
        'expires_at' => now()->addMinutes(30),
    ]);
}

function persistenceLegacyMockToken(Customer $customer, string $lastFour = '2944'): EfevooToken
{
    return EfevooToken::create([
        'customer_id' => $customer->id,
        'card_token' => 'legacy_token_'.$lastFour,
        'card_last_four' => $lastFour,
        'card_expiration' => '1129',
        'card_holder' => 'LEGACY USER',
        'alias' => 'legacy',
        'environment' => 'production',
        'is_active' => true,
        'metadata' => [
            'gateway_origin' => EfevooPayGatewayMode::MOCK,
        ],
    ]);
}

it('promotes legacy mock-origin token to live after successful tokencard reuse', function () {
    $user = persistenceUser();
    $legacy = persistenceLegacyMockToken($user->customer, '2944');

    expect(EfevooPayGatewayMode::current())->toBe('live')
        ->and(app(PaymentAuthenticationLocalPaymentMethodPersistence::class)->isListableForCustomer($legacy, $user->customer))->toBeFalse();

    $promoted = app(PaymentAuthenticationLocalPaymentMethodPersistence::class)->promoteToCurrentGateway($legacy);

    expect($promoted->fresh()->metadata['gateway_origin'] ?? null)->toBe('live')
        ->and(app(PaymentAuthenticationLocalPaymentMethodPersistence::class)->isListableForCustomer($promoted->fresh(), $user->customer))->toBeTrue();
});

it('does not promote legacy token while runtime gateway is mock', function () {
    config(['efevoopay.gateway' => 'mock', 'efevoopay.environment' => 'production']);
    $user = persistenceUser();
    $legacy = persistenceLegacyMockToken($user->customer, '2944');

    $promoted = app(PaymentAuthenticationLocalPaymentMethodPersistence::class)->promoteToCurrentGateway($legacy);

    expect($promoted->fresh()->metadata['gateway_origin'] ?? null)->toBe('mock');
});

it('session listable check mirrors payment methods index visibility', function () {
    $user = persistenceUser();
    $legacy = persistenceLegacyMockToken($user->customer, '2944');
    $persistence = app(PaymentAuthenticationLocalPaymentMethodPersistence::class);

    expect($persistence->isListableForCustomer($legacy, $user->customer))->toBeFalse();

    $session = Efevoo3dsSession::create([
        'customer_id' => $user->customer->id,
        'order_id' => 'ORDER-VIS',
        'card_last_four' => '2944',
        'amount' => 1.5,
        'status' => 'completed',
        'efevoo_token_id' => $legacy->id,
        'completed_at' => now(),
    ]);

    expect($persistence->sessionHasListableToken($session, $user->customer))->toBeFalse();

    $promoted = $persistence->promoteToCurrentGateway($legacy);

    expect($persistence->sessionHasListableToken($session->fresh(), $user->customer))->toBeTrue()
        ->and($persistence->isListableForCustomer($promoted, $user->customer))->toBeTrue();
});

it('result resource does not present success when attempt completed but token is not listable', function () {
    $user = persistenceUser();
    $context = persistenceContext($user->customer);
    $legacy = persistenceLegacyMockToken($user->customer, '8888');
    $attempt = PaymentAuthenticationAttempt::create([
        'attempt_uuid' => (string) Str::uuid(),
        'support_reference' => 'AUTH-PRES',
        'customer_id' => $user->customer->id,
        'recovery_context_id' => $context->id,
        'operation_type' => PaymentAuthenticationAttempt::OPERATION_CARD_VERIFICATION_3DS,
        'provider' => PaymentAuthenticationAttempt::PROVIDER_EFEVOOPAY,
        'status' => PaymentAuthenticationAttemptStatus::Completed->value,
        'merchant_reference' => 'EFV3DS-PRES',
        'attempt_number' => 1,
        'failure_category' => 'success',
        'started_at' => now()->subMinute(),
        'finished_at' => now(),
    ]);
    $session = Efevoo3dsSession::create([
        'customer_id' => $user->customer->id,
        'payment_authentication_attempt_id' => $attempt->id,
        'order_id' => 'ORDER-PRES',
        'card_last_four' => '8888',
        'amount' => 1.5,
        'status' => 'completed',
        'efevoo_token_id' => $legacy->id,
        'completed_at' => now(),
    ]);
    $attempt->update(['efevoo_3ds_session_id' => $session->id]);

    $result = app(PaymentAuthentication3dsResultResource::class)->make($session, $user->customer, $attempt->fresh());

    expect($result['presentation'])->toBe('provider_confirmation_pending')
        ->and($result['success'])->toBeFalse();
});

it('get result does not append card verified events on read', function () {
    $user = persistenceUser();
    $context = persistenceContext($user->customer);
    $token = persistenceLegacyMockToken($user->customer, '7777');
    $token->update(['metadata' => ['gateway_origin' => EfevooPayGatewayMode::LIVE]]);
    $attempt = PaymentAuthenticationAttempt::create([
        'attempt_uuid' => (string) Str::uuid(),
        'support_reference' => 'AUTH-READ',
        'customer_id' => $user->customer->id,
        'recovery_context_id' => $context->id,
        'operation_type' => PaymentAuthenticationAttempt::OPERATION_CARD_VERIFICATION_3DS,
        'provider' => PaymentAuthenticationAttempt::PROVIDER_EFEVOOPAY,
        'status' => PaymentAuthenticationAttemptStatus::Completed->value,
        'merchant_reference' => 'EFV3DS-READ',
        'attempt_number' => 1,
        'started_at' => now(),
        'finished_at' => now(),
    ]);
    $session = Efevoo3dsSession::create([
        'customer_id' => $user->customer->id,
        'payment_authentication_attempt_id' => $attempt->id,
        'order_id' => 'ORDER-READ',
        'card_last_four' => '7777',
        'amount' => 1.5,
        'status' => 'completed',
        'efevoo_token_id' => $token->id,
        'completed_at' => now(),
    ]);
    $attempt->update(['efevoo_3ds_session_id' => $session->id]);

    $before = PaymentAuthenticationAttemptEvent::count();

    test()->actingAs($user)->get(route('payment-methods.3ds-result', $session))->assertOk();

    expect(PaymentAuthenticationAttemptEvent::count())->toBe($before);
});
