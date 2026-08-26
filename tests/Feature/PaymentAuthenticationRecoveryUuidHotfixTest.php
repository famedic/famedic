<?php

use App\Contracts\EfevooPayGateway;
use App\Enums\PaymentAuthenticationAttemptEventType;
use App\Enums\PaymentAuthenticationAttemptStatus;
use App\Enums\PaymentAuthenticationRecoveryContextStatus;
use App\Enums\PaymentAuthenticationRecoveryContextType;
use App\Models\Efevoo3dsSession;
use App\Models\PaymentAuthenticationAttempt;
use App\Models\PaymentAuthenticationAttemptEvent;
use App\Models\PaymentAuthenticationRecoveryContext;
use App\Models\User;
use App\Support\EfevooPay3dsResultClassifier;
use App\Support\PaymentAuthentication3dsResultResource;
use App\Support\PaymentAuthenticationRecoveryPolicy;
use Illuminate\Support\Str;

beforeEach(function () {
    config([
        'efevoopay.gateway' => 'mock',
        'efevoopay.requires_3ds' => true,
        'efevoopay.sensitive_card_data.containment_enabled' => true,
        'efevoopay.recovery.max_attempts_per_context' => 3,
        'efevoopay.local_real_tests.enabled' => false,
    ]);
});

function uuidHotfixUser(): User
{
    return User::factory()
        ->withCompleteProfile()
        ->withRegularCustomer()
        ->create(['documentation_accepted_at' => now()])
        ->fresh(['customer']);
}

function uuidHotfixContext(User $user): PaymentAuthenticationRecoveryContext
{
    return PaymentAuthenticationRecoveryContext::create([
        'context_uuid' => (string) Str::uuid(),
        'customer_id' => $user->customer->id,
        'context_type' => PaymentAuthenticationRecoveryContextType::PaymentMethodSettings->value,
        'status' => PaymentAuthenticationRecoveryContextStatus::RecoveryAvailable->value,
        'return_route_name' => PaymentAuthenticationRecoveryContextType::PaymentMethodSettings->returnRouteName(),
        'context_data' => [],
        'started_at' => now(),
        'expires_at' => now()->addMinutes(30),
    ]);
}

function uuidHotfixDeclinedAttempt(
    User $user,
    PaymentAuthenticationRecoveryContext $context,
    string $attemptUuid,
    int $attemptNumber = 1,
    string $lastFour = '4242'
): PaymentAuthenticationAttempt {
    $attempt = PaymentAuthenticationAttempt::create([
        'attempt_uuid' => $attemptUuid,
        'support_reference' => 'AUTH-'.Str::upper(Str::random(8)),
        'customer_id' => $user->customer->id,
        'recovery_context_id' => $context->id,
        'operation_type' => PaymentAuthenticationAttempt::OPERATION_CARD_VERIFICATION_3DS,
        'provider' => PaymentAuthenticationAttempt::PROVIDER_EFEVOOPAY,
        'status' => PaymentAuthenticationAttemptStatus::Declined->value,
        'merchant_reference' => 'EFV3DS-'.Str::upper(Str::random(6)),
        'attempt_number' => $attemptNumber,
        'failure_category' => EfevooPay3dsResultClassifier::CATEGORY_AUTHENTICATION_FAILED,
        'started_at' => now()->subMinute(),
        'finished_at' => now(),
        'expires_at' => now()->addMinutes(5),
    ]);

    $session = Efevoo3dsSession::create([
        'customer_id' => $user->customer->id,
        'payment_authentication_attempt_id' => $attempt->id,
        'order_id' => 'ORDER-'.Str::upper(Str::random(6)),
        'card_last_four' => $lastFour,
        'amount' => 1.5,
        'status' => 'declined',
    ]);

    $attempt->update(['efevoo_3ds_session_id' => $session->id]);

    return $attempt->fresh(['efevoo3dsSession']);
}

function uuidHotfixStorePayload(array $overrides = []): array
{
    return array_merge([
        'card_number' => '5555555555554444',
        'exp_month' => '12',
        'exp_year' => '29',
        'cvv' => '123',
        'card_holder' => 'RECOVERY USER',
        'alias' => 'mc-4444',
        'terms_accepted' => '1',
        'attempt_uuid' => (string) Str::uuid(),
    ], $overrides);
}

function uuidHotfixBindGateway(array &$calls): void
{
    app()->instance(EfevooPayGateway::class, new class($calls) implements EfevooPayGateway
    {
        public function __construct(private array &$calls) {}

        public function chargeCard(array $data): array
        {
            return ['success' => true];
        }

        public function tokenizeCard(array $cardData, int $customerId): array
        {
            return ['success' => true];
        }

        public function initiate3DS(array $cardData, int $customerId): array
        {
            $this->calls['initiate3DS'] = ($this->calls['initiate3DS'] ?? 0) + 1;
            $this->calls['last_four'][] = substr(preg_replace('/\D/', '', $cardData['card_number']), -4);

            $session = Efevoo3dsSession::create([
                'customer_id' => $customerId,
                'order_id' => 'ORDER-'.Str::upper(Str::random(6)),
                'card_last_four' => substr(preg_replace('/\D/', '', $cardData['card_number']), -4),
                'amount' => 1.5,
                'status' => 'mock_pending',
                'url_3dsecure' => 'https://issuer.example/challenge',
                'token_3dsecure' => 'mock-token',
            ]);

            return ['success' => true, 'session_id' => $session->id];
        }

        public function complete3DS(Efevoo3dsSession $session, array $cardData): array
        {
            return ['success' => true];
        }

        public function poll3DSAuthentication(Efevoo3dsSession $session, array $cardData): array
        {
            return ['phase' => 'pending', 'success' => true];
        }

        public function finalize3DSTokenization(Efevoo3dsSession $session, array $cardData): array
        {
            return ['success' => true];
        }

        public function healthCheck(): array
        {
            return ['success' => true];
        }

        public function getTestCards(): array
        {
            return [];
        }
    });
}

function uuidHotfixStartRecovery(User $user, PaymentAuthenticationAttempt $attempt, string $action = 'different_card'): void
{
    test()->actingAs($user)->post(route('payment-methods.recovery.start'), [
        'session_id' => $attempt->efevoo3dsSession->id,
        'recovery_context_uuid' => $attempt->recoveryContext->context_uuid,
        'recovery_action' => $action,
    ])->assertRedirect();
}

it('double submit of the same in flight uuid reuses one attempt and one getlink', function () {
    $calls = [];
    uuidHotfixBindGateway($calls);
    $user = uuidHotfixUser();
    $uuid = (string) Str::uuid();

    test()->actingAs($user)->post(route('payment-methods.store'), uuidHotfixStorePayload([
        'attempt_uuid' => $uuid,
        'card_number' => '4242424242424242',
    ]))->assertRedirect();
    test()->actingAs($user)->post(route('payment-methods.store'), uuidHotfixStorePayload([
        'attempt_uuid' => $uuid,
        'card_number' => '4242424242424242',
    ]))->assertRedirect();

    expect($calls['initiate3DS'] ?? 0)->toBe(1)
        ->and(PaymentAuthenticationAttempt::where('attempt_uuid', $uuid)->count())->toBe(1)
        ->and(PaymentAuthenticationAttemptEvent::where('event_type', PaymentAuthenticationAttemptEventType::AttemptReused->value)->count())->toBe(1);
});

it('explicit recovery with stale terminal uuid creates a new child attempt and getlink', function () {
    $calls = [];
    uuidHotfixBindGateway($calls);
    $user = uuidHotfixUser();
    $context = uuidHotfixContext($user);
    $terminalUuid = (string) Str::uuid();
    $attempt10 = uuidHotfixDeclinedAttempt($user, $context, $terminalUuid, 1, '7748');

    uuidHotfixStartRecovery($user, $attempt10, PaymentAuthenticationRecoveryPolicy::ACTION_DIFFERENT_CARD);

    test()->actingAs($user)->post(route('payment-methods.store'), uuidHotfixStorePayload([
        'attempt_uuid' => (string) Str::uuid(),
        'recovery_context_uuid' => $context->context_uuid,
        'card_number' => '4111111111111111',
    ]))->assertRedirect();

    $child = PaymentAuthenticationAttempt::where('retry_of_attempt_id', $attempt10->id)->first();

    expect($calls['initiate3DS'] ?? 0)->toBe(1)
        ->and($child)->not->toBeNull()
        ->and($child->attempt_uuid)->not->toBe($terminalUuid)
        ->and($child->attempt_number)->toBe(2);
});

it('second recovery after another declined attempt does not reuse the first recovery uuid', function () {
    $calls = [];
    uuidHotfixBindGateway($calls);
    $user = uuidHotfixUser();
    $context = uuidHotfixContext($user);
    $firstUuid = (string) Str::uuid();
    $attempt10 = uuidHotfixDeclinedAttempt($user, $context, $firstUuid, 1, '7748');

    uuidHotfixStartRecovery($user, $attempt10, PaymentAuthenticationRecoveryPolicy::ACTION_DIFFERENT_CARD);
    $recoveryUuid = (string) Str::uuid();

    test()->actingAs($user)->post(route('payment-methods.store'), uuidHotfixStorePayload([
        'attempt_uuid' => $recoveryUuid,
        'recovery_context_uuid' => $context->context_uuid,
        'card_number' => '4111111111111111',
    ]))->assertRedirect();

    $attempt11 = PaymentAuthenticationAttempt::where('attempt_uuid', $recoveryUuid)->first();
    $attempt11->update([
        'status' => PaymentAuthenticationAttemptStatus::Declined->value,
        'finished_at' => now(),
        'failure_category' => EfevooPay3dsResultClassifier::CATEGORY_AUTHENTICATION_FAILED,
    ]);
    $attempt11->efevoo3dsSession->update(['status' => 'declined', 'card_last_four' => '2767']);

    uuidHotfixStartRecovery($user, $attempt11->fresh(), PaymentAuthenticationRecoveryPolicy::ACTION_DIFFERENT_CARD);

    test()->actingAs($user)->post(route('payment-methods.store'), uuidHotfixStorePayload([
        'attempt_uuid' => $firstUuid,
        'recovery_context_uuid' => $context->context_uuid,
        'card_number' => '378282246310005',
    ]))->assertRedirect();

    $attempt12 = PaymentAuthenticationAttempt::orderByDesc('id')->first();

    expect($calls['initiate3DS'] ?? 0)->toBe(2)
        ->and($attempt12->id)->not->toBe($attempt10->id)
        ->and($attempt12->id)->not->toBe($attempt11->id)
        ->and($attempt12->attempt_uuid)->not->toBe($firstUuid)
        ->and($attempt12->attempt_uuid)->not->toBe($recoveryUuid)
        ->and($attempt12->attempt_number)->toBe(3);
});

it('duplicate recovery store requests converge on one child attempt', function () {
    $calls = [];
    uuidHotfixBindGateway($calls);
    $user = uuidHotfixUser();
    $context = uuidHotfixContext($user);
    $terminalUuid = (string) Str::uuid();
    $attempt = uuidHotfixDeclinedAttempt($user, $context, $terminalUuid);

    uuidHotfixStartRecovery($user, $attempt, PaymentAuthenticationRecoveryPolicy::ACTION_RETRY);

    $staleUuid = $terminalUuid;
    $payload = uuidHotfixStorePayload([
        'attempt_uuid' => $staleUuid,
        'recovery_context_uuid' => $context->context_uuid,
    ]);

    test()->actingAs($user)->post(route('payment-methods.store'), $payload)->assertRedirect();
    test()->actingAs($user)->post(route('payment-methods.store'), $payload)->assertRedirect();

    expect($calls['initiate3DS'] ?? 0)->toBe(1)
        ->and(PaymentAuthenticationAttempt::where('retry_of_attempt_id', $attempt->id)->count())->toBe(1)
        ->and(PaymentAuthenticationAttemptEvent::where('event_type', PaymentAuthenticationAttemptEventType::AttemptReused->value)->count())->toBe(1);
});

it('terminal revisit does not call provider again', function () {
    $calls = [];
    uuidHotfixBindGateway($calls);
    $user = uuidHotfixUser();
    $uuid = (string) Str::uuid();

    test()->actingAs($user)->post(route('payment-methods.store'), uuidHotfixStorePayload([
        'attempt_uuid' => $uuid,
    ]))->assertRedirect();

    $attempt = PaymentAuthenticationAttempt::where('attempt_uuid', $uuid)->first();
    $attempt->update([
        'status' => PaymentAuthenticationAttemptStatus::Declined->value,
        'finished_at' => now(),
    ]);
    $attempt->efevoo3dsSession->update(['status' => 'declined']);

    test()->actingAs($user)->post(route('payment-methods.store'), uuidHotfixStorePayload([
        'attempt_uuid' => $uuid,
    ]))->assertRedirect();

    expect($calls['initiate3DS'] ?? 0)->toBe(1);
});

it('authentication failed in payment method settings is not presented as no pending purchase', function () {
    $user = uuidHotfixUser();
    $context = uuidHotfixContext($user);
    $attempt = uuidHotfixDeclinedAttempt($user, $context, (string) Str::uuid());

    $result = app(PaymentAuthentication3dsResultResource::class)->make(
        $attempt->efevoo3dsSession->fresh(),
        $user->customer,
        $attempt->fresh()
    );

    expect($result['copy']['message'])->toContain('El banco no aprobó')
        ->and($result['copy']['message'])->not->toContain('compra pendiente');
});

it('checkout without saved cart can show no pending purchase copy', function () {
    $user = uuidHotfixUser();
    $context = PaymentAuthenticationRecoveryContext::create([
        'context_uuid' => (string) Str::uuid(),
        'customer_id' => $user->customer->id,
        'context_type' => PaymentAuthenticationRecoveryContextType::LaboratoryCheckout->value,
        'status' => PaymentAuthenticationRecoveryContextStatus::RecoveryAvailable->value,
        'return_route_name' => PaymentAuthenticationRecoveryContextType::LaboratoryCheckout->returnRouteName(),
        'context_data' => ['laboratory_brand' => 'olab'],
        'started_at' => now(),
        'expires_at' => now()->addMinutes(30),
    ]);
    $attempt = uuidHotfixDeclinedAttempt($user, $context, (string) Str::uuid());

    $result = app(PaymentAuthentication3dsResultResource::class)->make(
        $attempt->efevoo3dsSession->fresh(),
        $user->customer,
        $attempt->fresh()
    );

    expect($result['copy']['message'])->toContain('compra pendiente');
});

it('recovery start emits recovery_started and changed_card once with stable dedupe keys', function () {
    $user = uuidHotfixUser();
    $context = uuidHotfixContext($user);
    $attempt = uuidHotfixDeclinedAttempt($user, $context, (string) Str::uuid());

    uuidHotfixStartRecovery($user, $attempt, PaymentAuthenticationRecoveryPolicy::ACTION_DIFFERENT_CARD);

    expect(PaymentAuthenticationAttemptEvent::where('event_type', PaymentAuthenticationAttemptEventType::RecoveryStarted->value)->count())->toBe(1)
        ->and(PaymentAuthenticationAttemptEvent::where('event_type', PaymentAuthenticationAttemptEventType::ChangedCard->value)->count())->toBe(1);
});

it('create recovery form exposes a fresh submission identity', function () {
    $user = uuidHotfixUser();
    $context = uuidHotfixContext($user);
    $attempt = uuidHotfixDeclinedAttempt($user, $context, (string) Str::uuid());

    uuidHotfixStartRecovery($user, $attempt, PaymentAuthenticationRecoveryPolicy::ACTION_DIFFERENT_CARD);

    test()->actingAs($user)
        ->get(route('payment-methods.create', ['recovery_context_uuid' => $context->context_uuid]))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('isRecoveryForm', true)
            ->where('recoveryForm.recovery_submission_identity', fn ($value) => filled($value))
        );
});
