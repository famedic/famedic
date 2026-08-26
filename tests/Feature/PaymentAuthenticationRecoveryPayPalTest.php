<?php

use App\Enums\LaboratoryBrand;
use App\Enums\PaymentAuthenticationAttemptEventType;
use App\Enums\PaymentAuthenticationAttemptStatus;
use App\Enums\PaymentAuthenticationRecoveryContextStatus;
use App\Enums\PaymentAuthenticationRecoveryContextType;
use App\Models\Efevoo3dsSession;
use App\Models\LaboratoryCartItem;
use App\Models\LaboratoryTest;
use App\Models\PaymentAuthenticationAttempt;
use App\Models\PaymentAuthenticationAttemptEvent;
use App\Models\PaymentAuthenticationRecoveryContext;
use App\Models\Transaction;
use App\Models\User;
use App\Services\PayPalService;
use Illuminate\Support\Str;

beforeEach(function () {
    config(['services.paypal.client_id' => 'test-paypal-client']);
});

function recoveryPayPalUser(): User
{
    return User::factory()
        ->withCompleteProfile()
        ->withRegularCustomer()
        ->create(['documentation_accepted_at' => now()])
        ->fresh(['customer']);
}

function makeRecoveryContext(
    User $user,
    PaymentAuthenticationRecoveryContextType $type = PaymentAuthenticationRecoveryContextType::MedicalAttentionCheckout,
    PaymentAuthenticationRecoveryContextStatus $status = PaymentAuthenticationRecoveryContextStatus::RecoveryAvailable,
): PaymentAuthenticationRecoveryContext {
    return PaymentAuthenticationRecoveryContext::create([
        'context_uuid' => (string) Str::uuid(),
        'customer_id' => $user->customer->id,
        'context_type' => $type,
        'status' => $status,
        'return_route_name' => $type->returnRouteName(),
        'context_data' => $type === PaymentAuthenticationRecoveryContextType::LaboratoryCheckout
            ? ['step' => 'payment', 'laboratory_brand' => LaboratoryBrand::OLAB->value]
            : ['step' => 'payment'],
        'started_at' => now(),
        'expires_at' => now()->addMinutes(30),
    ]);
}

function makeDeclinedRecoveryAttempt(
    User $user,
    PaymentAuthenticationRecoveryContext $context,
    PaymentAuthenticationAttemptStatus $status = PaymentAuthenticationAttemptStatus::Declined,
    string $sessionStatus = 'declined',
): array {
    $session = Efevoo3dsSession::create([
        'customer_id' => $user->customer->id,
        'order_id' => 'ORDER-'.Str::upper(Str::random(8)),
        'card_last_four' => '4242',
        'amount' => 1.5,
        'status' => $sessionStatus,
    ]);

    $attempt = PaymentAuthenticationAttempt::create([
        'attempt_uuid' => (string) Str::uuid(),
        'customer_id' => $user->customer->id,
        'efevoo_3ds_session_id' => $session->id,
        'recovery_context_id' => $context->id,
        'operation_type' => PaymentAuthenticationAttempt::OPERATION_CARD_VERIFICATION_3DS,
        'provider' => PaymentAuthenticationAttempt::PROVIDER_EFEVOOPAY,
        'status' => $status->value,
        'merchant_reference' => 'EFV3DS-'.Str::upper(Str::random(8)),
        'attempt_number' => 1,
        'support_reference' => 'SUP-'.Str::upper(Str::random(6)),
        'started_at' => now()->subMinute(),
        'finished_at' => in_array($status, [
            PaymentAuthenticationAttemptStatus::Declined,
            PaymentAuthenticationAttemptStatus::Cancelled,
            PaymentAuthenticationAttemptStatus::Expired,
            PaymentAuthenticationAttemptStatus::TechnicalError,
        ], true) ? now() : null,
        'expires_at' => now()->addMinutes(5),
    ]);

    $context->update([
        'root_authentication_attempt_id' => $attempt->id,
    ]);

    $session->update(['payment_authentication_attempt_id' => $attempt->id]);

    return [$session, $attempt];
}

it('get status is read-only and does not record refresh events', function () {
    $user = recoveryPayPalUser();
    $context = makeRecoveryContext($user);
    [$session] = makeDeclinedRecoveryAttempt($user, $context);

    $this->actingAs($user)
        ->getJson(route('payment-methods.3ds-result-status', ['sessionId' => $session->id]))
        ->assertOk();

    expect(PaymentAuthenticationAttemptEvent::query()
        ->where('event_type', PaymentAuthenticationAttemptEventType::RecoveryStatusRefreshed->value)
        ->count())->toBe(0);
});

it('post sync deduplicates recovery_status_refreshed within window', function () {
    $user = recoveryPayPalUser();
    $context = makeRecoveryContext($user);
    [$session] = makeDeclinedRecoveryAttempt($user, $context);

    $this->actingAs($user)
        ->postJson(route('payment-methods.3ds-result-sync', ['sessionId' => $session->id]))
        ->assertOk();

    $this->actingAs($user)
        ->postJson(route('payment-methods.3ds-result-sync', ['sessionId' => $session->id]))
        ->assertOk();

    expect(PaymentAuthenticationAttemptEvent::query()
        ->where('event_type', PaymentAuthenticationAttemptEventType::RecoveryStatusRefreshed->value)
        ->count())->toBe(1);
});

it('settings context cannot start paypal recovery', function () {
    $user = recoveryPayPalUser();
    $context = makeRecoveryContext($user, PaymentAuthenticationRecoveryContextType::PaymentMethodSettings);
    [$session] = makeDeclinedRecoveryAttempt($user, $context);

    $this->actingAs($user)
        ->postJson(route('payment-methods.recovery.paypal.start'), [
            'session_id' => $session->id,
            'recovery_context_uuid' => $context->context_uuid,
        ])
        ->assertStatus(409);
});

it('starts paypal recovery for medical attention checkout', function () {
    $user = recoveryPayPalUser();
    $context = makeRecoveryContext($user);
    [$session] = makeDeclinedRecoveryAttempt($user, $context);

    $response = $this->actingAs($user)
        ->postJson(route('payment-methods.recovery.paypal.start'), [
            'session_id' => $session->id,
            'recovery_context_uuid' => $context->context_uuid,
        ])
        ->assertOk()
        ->json();

    expect($response['redirect_url'] ?? null)->toContain('recovery_payment=paypal');
    expect($context->fresh()->recovery_method)->toBe('paypal');

    expect(PaymentAuthenticationAttemptEvent::query()
        ->where('event_type', PaymentAuthenticationAttemptEventType::ChangedToPaypal->value)
        ->exists())->toBeTrue();
});

it('reuses pending paypal order for recovery context', function () {
    $user = recoveryPayPalUser();
    $context = makeRecoveryContext($user);
    [$session, $attempt] = makeDeclinedRecoveryAttempt($user, $context);

    $transaction = Transaction::create([
        'transaction_amount_cents' => (int) config('famedic.medical_attention_subscription_price_cents', 30000),
        'payment_method' => 'paypal',
        'payment_provider' => 'paypal',
        'gateway' => 'paypal',
        'reference_id' => 'PP-ORDER-123',
        'provider_order_id' => 'PP-ORDER-123',
        'payment_status' => 'pending',
        'details' => [
            'purpose' => 'medical_attention_subscription',
            'customer_id' => $user->customer->id,
            'amount_cents' => (int) config('famedic.medical_attention_subscription_price_cents', 30000),
            'recovery_context_uuid' => $context->context_uuid,
        ],
    ]);

    $context->update([
        'recovery_transaction_id' => $transaction->id,
        'recovery_method' => 'paypal',
        'status' => PaymentAuthenticationRecoveryContextStatus::PaymentInProgress,
    ]);

    $paypal = Mockery::mock(PayPalService::class);
    $paypal->shouldNotReceive('createOrder');
    app()->instance(PayPalService::class, $paypal);

    $response = $this->actingAs($user)
        ->postJson(route('medical-attention.paypal.create-order'), [
            'recovery_context_uuid' => $context->context_uuid,
        ])
        ->assertOk()
        ->json();

    expect($response['order_id'])->toBe('PP-ORDER-123');
    expect($response['transaction_id'])->toBe($transaction->id);

    expect(PaymentAuthenticationAttemptEvent::query()
        ->where('event_type', PaymentAuthenticationAttemptEventType::PaypalOrderReused->value)
        ->exists())->toBeTrue();
});

it('releases recovery context after paypal cancel', function () {
    $user = recoveryPayPalUser();
    $context = makeRecoveryContext($user, PaymentAuthenticationRecoveryContextType::MedicalAttentionCheckout, PaymentAuthenticationRecoveryContextStatus::PaymentInProgress);
    [$session, $attempt] = makeDeclinedRecoveryAttempt($user, $context);

    $transaction = Transaction::create([
        'transaction_amount_cents' => 30000,
        'payment_method' => 'paypal',
        'payment_provider' => 'paypal',
        'gateway' => 'paypal',
        'reference_id' => 'PAYPAL-CANCEL-1',
        'provider_order_id' => 'PAYPAL-CANCEL-1',
        'payment_status' => 'pending',
        'details' => [
            'purpose' => 'medical_attention_subscription',
            'customer_id' => $user->customer->id,
            'recovery_context_uuid' => $context->context_uuid,
        ],
    ]);

    $context->update([
        'recovery_transaction_id' => $transaction->id,
        'recovery_method' => 'paypal',
    ]);

    $this->actingAs($user)
        ->postJson(route('payment-methods.recovery.paypal.cancel'), [
            'recovery_context_uuid' => $context->context_uuid,
            'transaction_id' => $transaction->id,
        ])
        ->assertOk()
        ->assertJson(['status' => 'released']);

    expect($context->fresh()->status)->toBe(PaymentAuthenticationRecoveryContextStatus::RecoveryAvailable);
    expect($context->fresh()->recovery_transaction_id)->toBeNull();

    expect(PaymentAuthenticationAttemptEvent::query()
        ->where('event_type', PaymentAuthenticationAttemptEventType::PaypalCancelled->value)
        ->exists())->toBeTrue();
});

it('returns 404 for foreign recovery context on paypal start', function () {
    $owner = recoveryPayPalUser();
    $intruder = recoveryPayPalUser();
    $context = makeRecoveryContext($owner);
    [$session] = makeDeclinedRecoveryAttempt($owner, $context);

    $this->actingAs($intruder)
        ->postJson(route('payment-methods.recovery.paypal.start'), [
            'session_id' => $session->id,
            'recovery_context_uuid' => $context->context_uuid,
        ])
        ->assertNotFound();
});

it('3ds result exposes supports_paypal for eligible checkout recovery', function () {
    $user = recoveryPayPalUser();
    $context = makeRecoveryContext($user);
    [$session] = makeDeclinedRecoveryAttempt($user, $context);

    $this->actingAs($user)
        ->get(route('payment-methods.3ds-result', ['sessionId' => $session->id]))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('result.recovery.supports_paypal', true)
            ->where('result.recovery.actions.paypal', true));
});

it('settings ambiguous confirmation pending does not expose paypal', function () {
    $user = recoveryPayPalUser();
    $context = makeRecoveryContext(
        $user,
        PaymentAuthenticationRecoveryContextType::PaymentMethodSettings,
        PaymentAuthenticationRecoveryContextStatus::AuthenticationInProgress
    );
    [$session] = makeDeclinedRecoveryAttempt(
        $user,
        $context,
        PaymentAuthenticationAttemptStatus::ProviderConfirmationPending,
        'pending'
    );

    $this->actingAs($user)
        ->get(route('payment-methods.3ds-result', ['sessionId' => $session->id]))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('result.recovery.actions.paypal', false)
            ->where('result.recovery.actions.refresh_status', false));
});

it('laboratory ambiguous confirmation pending exposes paypal without changing auth result', function () {
    $user = recoveryPayPalUser();
    $test = LaboratoryTest::factory()->create(['brand' => LaboratoryBrand::OLAB->value]);
    LaboratoryCartItem::factory()->create([
        'customer_id' => $user->customer->id,
        'laboratory_test_id' => $test->id,
    ]);
    $context = makeRecoveryContext(
        $user,
        PaymentAuthenticationRecoveryContextType::LaboratoryCheckout,
        PaymentAuthenticationRecoveryContextStatus::AuthenticationInProgress
    );
    [$session, $attempt] = makeDeclinedRecoveryAttempt(
        $user,
        $context,
        PaymentAuthenticationAttemptStatus::ProviderConfirmationPending,
        'pending'
    );

    $this->actingAs($user)
        ->get(route('payment-methods.3ds-result', ['sessionId' => $session->id]))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('result.recovery.actions.paypal', true)
            ->where('result.recovery.actions.retry', false)
            ->where('result.recovery.actions.different_card', false));

    $this->actingAs($user)
        ->postJson(route('payment-methods.recovery.paypal.start'), [
            'session_id' => $session->id,
            'recovery_context_uuid' => $context->context_uuid,
        ])
        ->assertOk();

    expect($attempt->fresh()->status)->toBe(PaymentAuthenticationAttemptStatus::ProviderConfirmationPending->value)
        ->and(PaymentAuthenticationAttemptEvent::query()
            ->where('event_type', PaymentAuthenticationAttemptEventType::ChangedToPaypal->value)
            ->count())->toBe(1);
});

it('membership ambiguous confirmation pending exposes paypal', function () {
    $user = recoveryPayPalUser();
    $context = makeRecoveryContext(
        $user,
        PaymentAuthenticationRecoveryContextType::MedicalAttentionCheckout,
        PaymentAuthenticationRecoveryContextStatus::AuthenticationInProgress
    );
    [$session] = makeDeclinedRecoveryAttempt(
        $user,
        $context,
        PaymentAuthenticationAttemptStatus::ProviderConfirmationPending,
        'pending'
    );

    $this->actingAs($user)
        ->get(route('payment-methods.3ds-result', ['sessionId' => $session->id]))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('result.recovery.actions.paypal', true));
});

it('ambiguous paypal is blocked when capture already exists', function () {
    $user = recoveryPayPalUser();
    $context = makeRecoveryContext(
        $user,
        PaymentAuthenticationRecoveryContextType::MedicalAttentionCheckout,
        PaymentAuthenticationRecoveryContextStatus::AuthenticationInProgress
    );
    [$session] = makeDeclinedRecoveryAttempt(
        $user,
        $context,
        PaymentAuthenticationAttemptStatus::ProviderConfirmationPending,
        'pending'
    );

    Transaction::create([
        'transaction_amount_cents' => 30000,
        'payment_method' => 'paypal',
        'payment_provider' => 'paypal',
        'gateway' => 'paypal',
        'reference_id' => 'PP-CAPTURED-BLOCK',
        'provider_order_id' => 'PP-CAPTURED-BLOCK',
        'payment_status' => 'captured',
        'details' => [
            'customer_id' => $user->customer->id,
            'recovery_context_uuid' => $context->context_uuid,
        ],
    ]);

    $this->actingAs($user)
        ->postJson(route('payment-methods.recovery.paypal.start'), [
            'session_id' => $session->id,
            'recovery_context_uuid' => $context->context_uuid,
        ])
        ->assertStatus(409);
});
