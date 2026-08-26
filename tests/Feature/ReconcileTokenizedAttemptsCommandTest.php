<?php

use App\Enums\PaymentAuthenticationAttemptEventType;
use App\Enums\PaymentAuthenticationAttemptStatus;
use App\Models\Customer;
use App\Models\Efevoo3dsSession;
use App\Models\EfevooToken;
use App\Models\EfevooTransaction;
use App\Models\PaymentAuthenticationAttempt;
use App\Models\PaymentAuthenticationAttemptEvent;
use App\Models\User;
use App\Support\EfevooPayGatewayMode;
use App\Support\EfevooTokenGatewayOriginPromotion;
use App\Support\MockEfevooPayGatewayCallRecorder;
use App\Support\PaymentAuthenticationLocalPaymentMethodPersistence;
use App\Support\PaymentAuthenticationTokenizedAttemptReconciler;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

beforeEach(function () {
    config([
        'efevoopay.gateway' => 'mock',
        'efevoopay.environment' => 'production',
        'efevoopay.local_real_tests.enabled' => false,
    ]);
    MockEfevooPayGatewayCallRecorder::reset();
});

function reconcileUser(): User
{
    return User::factory()
        ->withCompleteProfile()
        ->withRegularCustomer()
        ->create(['documentation_accepted_at' => now()])
        ->fresh(['customer']);
}

function reconcileLegacyMockToken(Customer $customer, string $lastFour = '2944'): EfevooToken
{
    return EfevooToken::create([
        'customer_id' => $customer->id,
        'card_token' => 'reconcile_token_'.$lastFour,
        'card_last_four' => $lastFour,
        'card_expiration' => '1129',
        'card_holder' => 'RECONCILE USER',
        'alias' => 'reconcile',
        'environment' => 'production',
        'is_active' => true,
        'metadata' => [
            'gateway_origin' => EfevooPayGatewayMode::MOCK,
        ],
    ]);
}

function reconcileSuccessfulAttempt(
    Customer $customer,
    EfevooToken $token,
    array $overrides = []
): PaymentAuthenticationAttempt {
    $attempt = PaymentAuthenticationAttempt::create(array_merge([
        'attempt_uuid' => (string) Str::uuid(),
        'support_reference' => 'AUTH-RECON-'.Str::upper(Str::random(8)),
        'customer_id' => $customer->id,
        'operation_type' => PaymentAuthenticationAttempt::OPERATION_CARD_VERIFICATION_3DS,
        'provider' => PaymentAuthenticationAttempt::PROVIDER_EFEVOOPAY,
        'status' => PaymentAuthenticationAttemptStatus::Completed->value,
        'merchant_reference' => 'EFV3DS-RECON-'.Str::upper(Str::random(8)),
        'attempt_number' => 1,
        'failure_category' => 'success',
        'provider_order_id' => '31210',
        'tokenization_call_count' => 1,
        'started_at' => now()->subMinute(),
        'finished_at' => now(),
    ], $overrides));

    $session = Efevoo3dsSession::create([
        'customer_id' => $customer->id,
        'payment_authentication_attempt_id' => $attempt->id,
        'order_id' => $attempt->provider_order_id,
        'card_last_four' => $token->card_last_four,
        'amount' => 1.5,
        'status' => 'completed',
        'efevoo_token_id' => $token->id,
        'completed_at' => now(),
    ]);

    $attempt->update(['efevoo_3ds_session_id' => $session->id]);

    $baseOccurred = now()->subSeconds(30);

    PaymentAuthenticationAttemptEvent::create([
        'event_uuid' => (string) Str::uuid(),
        'payment_authentication_attempt_id' => $attempt->id,
        'event_type' => PaymentAuthenticationAttemptEventType::AuthenticationSucceeded->value,
        'source' => 'backend',
        'dedupe_key' => 'auth_succeeded:'.$attempt->id,
        'occurred_at' => $baseOccurred,
        'metadata' => ['session_id' => $session->id, 'response_received' => true],
    ]);

    PaymentAuthenticationAttemptEvent::create([
        'event_uuid' => (string) Str::uuid(),
        'payment_authentication_attempt_id' => $attempt->id,
        'event_type' => PaymentAuthenticationAttemptEventType::TokenizationRequestStarted->value,
        'source' => 'backend',
        'dedupe_key' => 'tokenization_request_started:'.$attempt->id,
        'occurred_at' => $baseOccurred->copy()->addSecond(),
        'metadata' => [
            'session_id' => $session->id,
            'operation' => 'getTokenize',
            'provider_order_id' => $session->order_id,
            'external_tokenization_attempted' => true,
        ],
    ]);

    PaymentAuthenticationAttemptEvent::create([
        'event_uuid' => (string) Str::uuid(),
        'payment_authentication_attempt_id' => $attempt->id,
        'event_type' => PaymentAuthenticationAttemptEventType::TokenizationRequestSucceeded->value,
        'source' => 'backend',
        'dedupe_key' => 'tokenization_request_succeeded:'.$attempt->id,
        'occurred_at' => $baseOccurred->copy()->addSeconds(2),
        'metadata' => [
            'session_id' => $session->id,
            'operation' => 'getTokenize',
            'provider_order_id' => $session->order_id,
            'external_tokenization_attempted' => true,
            'response_received' => true,
            'http_status' => 200,
        ],
    ]);

    return $attempt->fresh(['efevoo3dsSession', 'events']);
}

it('dry-run safe candidate makes zero changes', function () {
    config(['efevoopay.gateway' => 'live']);
    $user = reconcileUser();
    $token = reconcileLegacyMockToken($user->customer);
    $attempt = reconcileSuccessfulAttempt($user->customer, $token);

    $originBefore = data_get($token->fresh()->metadata, 'gateway_origin');

    $this->artisan('efevoo:reconcile-tokenized-attempts', [
        '--attempt' => $attempt->id,
        '--target-origin' => 'live',
    ])->assertSuccessful();

    expect(data_get($token->fresh()->metadata, 'gateway_origin'))->toBe($originBefore);
});

it('dry-run accepts explicit dry-run flag', function () {
    config(['efevoopay.gateway' => 'live']);
    $user = reconcileUser();
    $token = reconcileLegacyMockToken($user->customer);
    $attempt = reconcileSuccessfulAttempt($user->customer, $token);

    $this->artisan('efevoo:reconcile-tokenized-attempts', [
        '--attempt' => $attempt->id,
        '--target-origin' => 'live',
        '--dry-run' => true,
    ])->assertSuccessful();
});

it('rejects dry-run and apply together', function () {
    $this->artisan('efevoo:reconcile-tokenized-attempts', [
        '--attempt' => 1,
        '--target-origin' => 'live',
        '--dry-run' => true,
        '--apply' => true,
    ])->assertFailed();
});

it('requires attempt id', function () {
    $this->artisan('efevoo:reconcile-tokenized-attempts', [
        '--target-origin' => 'live',
    ])->assertFailed();
});

it('blocks missing attempt', function () {
    $this->artisan('efevoo:reconcile-tokenized-attempts', [
        '--attempt' => 999999,
        '--target-origin' => 'live',
    ])->assertSuccessful()
        ->expectsOutputToContain('blocked: 1');
});

it('blocks when get status was not approved', function () {
    config(['efevoopay.gateway' => 'live']);
    $user = reconcileUser();
    $token = reconcileLegacyMockToken($user->customer);
    $attempt = reconcileSuccessfulAttempt($user->customer, $token);

    DB::table('payment_authentication_attempt_events')
        ->where('payment_authentication_attempt_id', $attempt->id)
        ->where('event_type', PaymentAuthenticationAttemptEventType::AuthenticationSucceeded->value)
        ->delete();

    $result = app(PaymentAuthenticationTokenizedAttemptReconciler::class)
        ->reconcile($attempt->id, 'live', false);

    expect($result['blocked'])->toBeTrue()
        ->and($result['block_reason'])->toBe('get_status_not_approved');
});

it('blocks when tokencard was not called exactly once', function () {
    config(['efevoopay.gateway' => 'live']);
    $user = reconcileUser();
    $token = reconcileLegacyMockToken($user->customer);
    $attempt = reconcileSuccessfulAttempt($user->customer, $token, [
        'tokenization_call_count' => 2,
    ]);

    $result = app(PaymentAuthenticationTokenizedAttemptReconciler::class)
        ->reconcile($attempt->id, 'live', false);

    expect($result['blocked'])->toBeTrue()
        ->and($result['block_reason'])->toBe('token_card_call_count_invalid');
});

it('blocks when tokenization did not succeed', function () {
    config(['efevoopay.gateway' => 'live']);
    $user = reconcileUser();
    $token = reconcileLegacyMockToken($user->customer);
    $attempt = reconcileSuccessfulAttempt($user->customer, $token);

    DB::table('payment_authentication_attempt_events')
        ->where('payment_authentication_attempt_id', $attempt->id)
        ->where('event_type', PaymentAuthenticationAttemptEventType::TokenizationRequestSucceeded->value)
        ->delete();

    $result = app(PaymentAuthenticationTokenizedAttemptReconciler::class)
        ->reconcile($attempt->id, 'live', false);

    expect($result['blocked'])->toBeTrue()
        ->and($result['block_reason'])->toBe('tokenization_not_succeeded');
});

it('blocks when ambiguous failure exists after tokenization', function () {
    config(['efevoopay.gateway' => 'live']);
    $user = reconcileUser();
    $token = reconcileLegacyMockToken($user->customer);
    $attempt = reconcileSuccessfulAttempt($user->customer, $token);

    PaymentAuthenticationAttemptEvent::create([
        'event_uuid' => (string) Str::uuid(),
        'payment_authentication_attempt_id' => $attempt->id,
        'event_type' => PaymentAuthenticationAttemptEventType::TokenizationRequestTimeout->value,
        'source' => 'backend',
        'dedupe_key' => 'tokenization_request_timeout:'.$attempt->id,
        'occurred_at' => now(),
        'metadata' => ['session_id' => $attempt->efevoo_3ds_session_id],
    ]);

    $result = app(PaymentAuthenticationTokenizedAttemptReconciler::class)
        ->reconcile($attempt->id, 'live', false);

    expect($result['blocked'])->toBeTrue()
        ->and($result['block_reason'])->toBe('ambiguous_or_terminal_after_tokenization');
});

it('blocks when token is missing from session', function () {
    config(['efevoopay.gateway' => 'live']);
    $user = reconcileUser();
    $token = reconcileLegacyMockToken($user->customer);
    $attempt = reconcileSuccessfulAttempt($user->customer, $token);
    $attempt->efevoo3dsSession->update(['efevoo_token_id' => null]);

    $result = app(PaymentAuthenticationTokenizedAttemptReconciler::class)
        ->reconcile($attempt->id, 'live', false);

    expect($result['blocked'])->toBeTrue()
        ->and($result['block_reason'])->toBe('missing_session_token_reference');
});

it('blocks when token belongs to another customer', function () {
    config(['efevoopay.gateway' => 'live']);
    $owner = reconcileUser();
    $other = reconcileUser();
    $token = reconcileLegacyMockToken($owner->customer);
    $token->update(['customer_id' => $other->customer->id]);
    $attempt = reconcileSuccessfulAttempt($owner->customer, $token->fresh());

    $result = app(PaymentAuthenticationTokenizedAttemptReconciler::class)
        ->reconcile($attempt->id, 'live', false);

    expect($result['blocked'])->toBeTrue()
        ->and($result['block_reason'])->toBe('customer_mismatch');
});

it('blocks when ownership conflicts exist for card_token', function () {
    config(['efevoopay.gateway' => 'live']);
    $owner = reconcileUser();
    $other = reconcileUser();
    $token = reconcileLegacyMockToken($owner->customer);
    EfevooToken::create([
        'customer_id' => $other->customer->id,
        'card_token' => $token->card_token,
        'card_last_four' => '9999',
        'card_expiration' => '1129',
        'environment' => 'production',
        'is_active' => true,
        'metadata' => ['gateway_origin' => EfevooPayGatewayMode::MOCK],
    ]);
    $attempt = reconcileSuccessfulAttempt($owner->customer, $token);

    $result = app(PaymentAuthenticationTokenizedAttemptReconciler::class)
        ->reconcile($attempt->id, 'live', false);

    expect($result['blocked'])->toBeTrue()
        ->and($result['block_reason'])->toBe('blocked_requires_manual_review')
        ->and($result['ownership_conflicts'])->toBeGreaterThan(0);
});

it('blocks live to mock promotion at policy level', function () {
    $user = reconcileUser();
    $token = EfevooToken::create([
        'customer_id' => $user->customer->id,
        'card_token' => 'live_token',
        'card_last_four' => '1111',
        'card_expiration' => '1129',
        'environment' => 'production',
        'is_active' => true,
        'metadata' => ['gateway_origin' => EfevooPayGatewayMode::LIVE],
    ]);

    expect(fn () => app(EfevooTokenGatewayOriginPromotion::class)->promote($token, EfevooPayGatewayMode::MOCK, [
        'source' => 'reconcile-tokenized-attempts',
    ]))->toThrow(\DomainException::class);
});

it('does not infer target from current mock gateway configuration', function () {
    config(['efevoopay.gateway' => 'mock']);
    $user = reconcileUser();
    $token = reconcileLegacyMockToken($user->customer);
    $attempt = reconcileSuccessfulAttempt($user->customer, $token);

    $result = app(PaymentAuthenticationTokenizedAttemptReconciler::class)
        ->reconcile($attempt->id, 'live', false);

    expect($result['blocked'])->toBeFalse()
        ->and($result['attempt_origin'])->toBe('live')
        ->and($result['proposed_action'])->toBe('promote_gateway_origin');
});

it('apply promotes token and makes it visible in live gateway', function () {
    config(['efevoopay.gateway' => 'live', 'efevoopay.environment' => 'production']);
    $user = reconcileUser();
    $token = reconcileLegacyMockToken($user->customer);
    $attempt = reconcileSuccessfulAttempt($user->customer, $token);
    $persistence = app(PaymentAuthenticationLocalPaymentMethodPersistence::class);

    expect($persistence->isListableForCustomer($token, $user->customer))->toBeFalse();

    $result = app(PaymentAuthenticationTokenizedAttemptReconciler::class)
        ->reconcile($attempt->id, 'live', true);

    expect($result['changes_applied'])->toBe(1)
        ->and($result['visible_after'] ?? false)->toBeTrue()
        ->and($persistence->isListableForCustomer($token->fresh(), $user->customer))->toBeTrue();
});

it('apply is idempotent on second execution', function () {
    config(['efevoopay.gateway' => 'live', 'efevoopay.environment' => 'production']);
    $user = reconcileUser();
    $token = reconcileLegacyMockToken($user->customer);
    $attempt = reconcileSuccessfulAttempt($user->customer, $token);
    $reconciler = app(PaymentAuthenticationTokenizedAttemptReconciler::class);

    $reconciler->reconcile($attempt->id, 'live', true);
    $second = $reconciler->reconcile($attempt->id, 'live', true);

    expect($second['blocked'])->toBeTrue()
        ->and($second['block_reason'])->toBe('already_visible_in_target')
        ->and($second['changes_applied'])->toBe(0);
});

it('rolls back when visibility is not confirmed after promotion', function () {
    config(['efevoopay.gateway' => 'live', 'efevoopay.environment' => 'production']);
    $user = reconcileUser();
    $token = reconcileLegacyMockToken($user->customer);
    $attempt = reconcileSuccessfulAttempt($user->customer, $token);

    $promotion = Mockery::mock(EfevooTokenGatewayOriginPromotion::class);
    $promotion->shouldReceive('isTransitionAllowed')->andReturn(true);
    $promotion->shouldReceive('promote')->andReturnUsing(function (EfevooToken $token) {
        $token->update([
            'metadata' => array_merge($token->metadata ?? [], ['gateway_origin' => 'live']),
        ]);

        return $token->fresh();
    });

    $persistence = Mockery::mock(PaymentAuthenticationLocalPaymentMethodPersistence::class);
    $persistence->shouldReceive('isListableForCustomerInGateway')
        ->andReturn(false);

    $reconciler = new PaymentAuthenticationTokenizedAttemptReconciler($persistence, $promotion);

    $result = $reconciler->reconcile($attempt->id, 'live', true);

    expect($result['blocked'])->toBeTrue()
        ->and($result['changes_applied'])->toBe(0);
});

it('serializes apply through attempt scoped lock key', function () {
    config(['efevoopay.gateway' => 'live', 'efevoopay.environment' => 'production']);
    $user = reconcileUser();
    $token = reconcileLegacyMockToken($user->customer);
    $attempt = reconcileSuccessfulAttempt($user->customer, $token);
    $lockKey = 'efevoo:reconcile-tokenized-attempt:'.$attempt->id;

    expect(Cache::lock($lockKey, 1)->get())->toBeTrue();
    Cache::lock($lockKey, 1)->release();

    $first = app(PaymentAuthenticationTokenizedAttemptReconciler::class)
        ->reconcile($attempt->id, 'live', true);
    $second = app(PaymentAuthenticationTokenizedAttemptReconciler::class)
        ->reconcile($attempt->id, 'live', true);

    expect($first['changes_applied'])->toBe(1)
        ->and($second['changes_applied'])->toBe(0);
});

it('records audit metadata without secrets on apply', function () {
    config(['efevoopay.gateway' => 'live', 'efevoopay.environment' => 'production']);
    $user = reconcileUser();
    $token = reconcileLegacyMockToken($user->customer);
    $attempt = reconcileSuccessfulAttempt($user->customer, $token);

    app(PaymentAuthenticationTokenizedAttemptReconciler::class)
        ->reconcile($attempt->id, 'live', true);

    $metadata = $token->fresh()->metadata;

    expect($metadata['gateway_origin'])->toBe('live')
        ->and($metadata['gateway_origin_previous'])->toBe('mock')
        ->and($metadata['gateway_origin_promotion_source'])->toBe('reconcile-tokenized-attempts')
        ->and($metadata['gateway_origin_promotion_attempt_id'])->toBe($attempt->id)
        ->and($metadata)->not->toHaveKey('card_token');
});

it('does not perform provider http calls during dry-run or apply', function () {
    config(['efevoopay.gateway' => 'live', 'efevoopay.environment' => 'production']);
    $user = reconcileUser();
    $token = reconcileLegacyMockToken($user->customer);
    $attempt = reconcileSuccessfulAttempt($user->customer, $token);

    app(PaymentAuthenticationTokenizedAttemptReconciler::class)
        ->reconcile($attempt->id, 'live', false);

    app(PaymentAuthenticationTokenizedAttemptReconciler::class)
        ->reconcile($attempt->id, 'live', true);

    expect(MockEfevooPayGatewayCallRecorder::$payloads)->toBe([]);
});

it('legacy classify command behavior remains unchanged for explicit origin tokens', function () {
    $user = reconcileUser();
    $token = EfevooToken::create([
        'customer_id' => $user->customer->id,
        'card_token' => 'explicit_mock',
        'card_last_four' => '2222',
        'card_expiration' => '1129',
        'environment' => 'production',
        'metadata' => ['gateway_origin' => EfevooPayGatewayMode::MOCK],
        'is_active' => true,
    ]);

    $this->artisan('efevoo:tokens:classify-gateway-origin', [
        '--token-id' => $token->id,
    ])->expectsOutputToContain('No hay tokens que coincidan');

    expect(data_get($token->fresh()->metadata, 'gateway_origin'))->toBe('mock');
});

it('promoteToCurrentGateway does not promote under mock gateway', function () {
    config(['efevoopay.gateway' => 'mock', 'efevoopay.environment' => 'production']);
    $user = reconcileUser();
    $token = reconcileLegacyMockToken($user->customer);

    $promoted = app(PaymentAuthenticationLocalPaymentMethodPersistence::class)
        ->promoteToCurrentGateway($token);

    expect(data_get($promoted->metadata, 'gateway_origin'))->toBe('mock');
});

it('live tokencard flow still creates visible method after promotion', function () {
    config(['efevoopay.gateway' => 'live', 'efevoopay.environment' => 'production']);
    $user = reconcileUser();
    $token = reconcileLegacyMockToken($user->customer, '5510');
    $persistence = app(PaymentAuthenticationLocalPaymentMethodPersistence::class);

    $finalized = $persistence->finalizeTokenAfterProviderSuccess(
        $persistence->promoteToCurrentGateway($token),
        $user->customer
    );

    expect($finalized)->not->toBeNull()
        ->and($persistence->isListableForCustomer($finalized, $user->customer))->toBeTrue();
});

it('blocks reference conflicts from foreign sessions', function () {
    config(['efevoopay.gateway' => 'live']);
    $owner = reconcileUser();
    $other = reconcileUser();
    $token = reconcileLegacyMockToken($owner->customer);
    Efevoo3dsSession::create([
        'customer_id' => $other->customer->id,
        'order_id' => 'FOREIGN',
        'card_last_four' => '0000',
        'amount' => 1.5,
        'status' => 'completed',
        'efevoo_token_id' => $token->id,
    ]);
    $attempt = reconcileSuccessfulAttempt($owner->customer, $token);

    $result = app(PaymentAuthenticationTokenizedAttemptReconciler::class)
        ->reconcile($attempt->id, 'live', false);

    expect($result['blocked'])->toBeTrue()
        ->and($result['reference_conflicts'])->toBeGreaterThan(0);
});

it('dry-run reports safe candidate fields for mock-origin live attempt', function () {
    config(['efevoopay.gateway' => 'live']);
    $user = reconcileUser();
    $token = reconcileLegacyMockToken($user->customer, '2944');
    $attempt = reconcileSuccessfulAttempt($user->customer, $token);

    $result = app(PaymentAuthenticationTokenizedAttemptReconciler::class)
        ->reconcile($attempt->id, 'live', false);

    expect($result['blocked'])->toBeFalse()
        ->and($result['attempt_origin'])->toBe('live')
        ->and($result['current_token_origin'])->toBe('mock')
        ->and($result['target_origin'])->toBe('live')
        ->and($result['visible_before'])->toBeFalse()
        ->and($result['proposed_action'])->toBe('promote_gateway_origin')
        ->and($result['token_usuario_present'])->toBeTrue();
});
