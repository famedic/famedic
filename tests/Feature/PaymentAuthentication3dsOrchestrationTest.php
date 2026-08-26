<?php

use App\Contracts\EfevooPayGateway;
use App\Enums\PaymentAuthenticationAttemptEventType;
use App\Enums\PaymentAuthenticationAttemptStatus;
use App\Models\Efevoo3dsSession;
use App\Models\EfevooToken;
use App\Models\PaymentAuthenticationAttempt;
use App\Models\PaymentAuthenticationAttemptEvent;
use App\Models\User;
use App\Support\PaymentAuthentication3dsResultResource;
use App\Support\PaymentAuthenticationEfevooPayAmounts;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;

beforeEach(function () {
    config([
        'efevoopay.gateway' => 'mock',
        'efevoopay.requires_3ds' => true,
        'efevoopay.sensitive_card_data.containment_enabled' => true,
        'efevoopay.local_real_tests.enabled' => false,
    ]);
});

function orchestrationUser(): User
{
    return User::factory()
        ->withCompleteProfile()
        ->withRegularCustomer()
        ->create(['documentation_accepted_at' => now()])
        ->fresh(['customer']);
}

function orchestrationSessionPayload(array $overrides = []): array
{
    return array_merge([
        'card_number' => '4242424242424242',
        'exp_month' => '12',
        'exp_year' => '29',
        'cvv' => '123',
        'card_holder' => 'ORCH USER',
        'alias' => 'orch',
        'terms_accepted' => '1',
        'attempt_uuid' => (string) Str::uuid(),
    ], $overrides);
}

function orchestrationSensitiveSession(User $user, int $sessionId, array $cardData = []): array
{
    $cardData = array_merge([
        'card_number' => '4242424242424242',
        'expiration' => '1229',
        'cvv' => '123',
        'amount' => PaymentAuthenticationEfevooPayAmounts::threeDsVerificationAmount(),
    ], $cardData);

    return [
        '3ds_card_data_'.$sessionId => array_merge($cardData, [
            'stored_at' => now()->timestamp,
            'expires_at' => now()->addMinutes(5)->timestamp,
            'customer_id' => $user->customer->id,
            'efevoo_3ds_session_id' => $sessionId,
        ]),
    ];
}

function bindOrchestrationGateway(array &$calls, ?callable $poll = null, ?callable $finalize = null): void
{
    app()->instance(EfevooPayGateway::class, new class($calls, $poll, $finalize) implements EfevooPayGateway
    {
        public function __construct(
            private array &$calls,
            private $poll,
            private $finalize,
        ) {}

        public function chargeCard(array $data): array
        {
            return ['success' => true];
        }

        public function tokenizeCard(array $cardData, int $customerId): array
        {
            return ['success' => true, 'token_id' => 1];
        }

        public function initiate3DS(array $cardData, int $customerId): array
        {
            $this->calls['initiate3DS'] = ($this->calls['initiate3DS'] ?? 0) + 1;
            $session = Efevoo3dsSession::create([
                'customer_id' => $customerId,
                'order_id' => 'ORDER-ORCH',
                'card_last_four' => '4242',
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
            $this->calls['poll3DSAuthentication'] = ($this->calls['poll3DSAuthentication'] ?? 0) + 1;

            if ($this->poll) {
                return ($this->poll)($session, $cardData);
            }

            $session->update(['status' => 'authenticated']);

            return ['phase' => 'authenticated', 'success' => true];
        }

        public function finalize3DSTokenization(Efevoo3dsSession $session, array $cardData): array
        {
            $this->calls['finalize3DSTokenization'] = ($this->calls['finalize3DSTokenization'] ?? 0) + 1;
            $this->calls['finalize_has_cvv'] = array_key_exists('cvv', $cardData) && $cardData['cvv'] !== '';

            if ($this->finalize) {
                return ($this->finalize)($session, $cardData);
            }

            $token = EfevooToken::create([
                'customer_id' => $session->customer_id,
                'card_token' => 'mock_tok_orch_'.Str::random(8),
                'card_last_four' => '4242',
                'card_expiration' => '1229',
                'card_holder' => 'Orch Test',
                'environment' => config('efevoopay.environment', 'test'),
                'is_active' => true,
                'metadata' => [
                    'gateway_origin' => \App\Support\EfevooPayGatewayMode::current(),
                    'mock' => \App\Support\EfevooPayGatewayMode::usesMock(),
                ],
            ]);

            $session->update([
                'status' => 'completed',
                'efevoo_token_id' => $token->id,
                'completed_at' => now(),
            ]);

            return ['success' => true, 'token_id' => $token->id, 'external_tokenization_attempted' => true];
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

function orchestrationPoll(User $user, Efevoo3dsSession $session, array $cardData = []): \Illuminate\Testing\TestResponse
{
    return test()->actingAs($user)->withSession(
        orchestrationSensitiveSession($user, $session->id, $cardData)
    )->getJson(route('payment-methods.3ds-status', $session));
}

it('pending polls then approved executes tokencard once in the approving poll cycle', function () {
    $calls = [];
    $pollCount = 0;
    bindOrchestrationGateway($calls, poll: function (Efevoo3dsSession $session) use (&$calls, &$pollCount) {
        $pollCount++;
        if ($pollCount < 3) {
            $calls['poll_phases'][] = 'pending';

            return ['phase' => 'pending', 'success' => true];
        }

        $calls['poll_phases'][] = 'authenticated';
        $session->update(['status' => 'authenticated']);

        return ['phase' => 'authenticated', 'success' => true];
    }, finalize: function (Efevoo3dsSession $session) {
        $session->update(['status' => 'completed', 'completed_at' => now()]);

        return ['success' => true, 'external_tokenization_attempted' => true];
    });

    $user = orchestrationUser();
    test()->actingAs($user)->post(route('payment-methods.store'), orchestrationSessionPayload())->assertRedirect();
    $session = Efevoo3dsSession::first();

    orchestrationPoll($user, $session)->assertOk()->assertJsonPath('final', false)->assertJsonPath('status', 'pending');
    orchestrationPoll($user, $session)->assertOk()->assertJsonPath('final', false)->assertJsonPath('status', 'pending');

    orchestrationPoll($user, $session->fresh())
        ->assertOk()
        ->assertJsonPath('final', true)
        ->assertJsonPath('status', 'completed');

    $attempt = PaymentAuthenticationAttempt::first();

    expect($calls['poll3DSAuthentication'] ?? 0)->toBe(3)
        ->and($calls['finalize3DSTokenization'] ?? 0)->toBe(1)
        ->and($attempt->events()->where('event_type', PaymentAuthenticationAttemptEventType::AuthenticationSucceeded->value)->count())->toBe(1)
        ->and($attempt->fresh()->status)->toBe(PaymentAuthenticationAttemptStatus::Completed->value);
});

it('approved poll with tokencard failure finishes tokenization_failed in same cycle', function () {
    $calls = [];
    bindOrchestrationGateway($calls, finalize: function (Efevoo3dsSession $session) {
        $session->update(['status' => 'tokenization_failed', 'error_message' => 'Sin token']);

        return [
            'success' => false,
            'message' => 'La verificación con tu banco se completó, pero no pudimos guardar la tarjeta. Puedes volver a intentarlo o usar otra tarjeta.',
            'error_type' => 'gateway',
            'external_tokenization_attempted' => true,
        ];
    });

    $user = orchestrationUser();
    test()->actingAs($user)->post(route('payment-methods.store'), orchestrationSessionPayload())->assertRedirect();
    $session = Efevoo3dsSession::first();

    orchestrationPoll($user, $session)
        ->assertOk()
        ->assertJsonPath('final', true)
        ->assertJsonPath('status', 'tokenization_failed');

    expect($calls['finalize3DSTokenization'] ?? 0)->toBe(1);
});

it('approved poll with ambiguous tokencard timeout returns provider confirmation pending', function () {
    $calls = [];
    bindOrchestrationGateway($calls, finalize: fn () => [
        'success' => false,
        'confirmation_pending' => true,
        'message' => config('efevoopay.sensitive_card_data.messages.confirmation_pending'),
        'error_type' => 'system',
    ]);

    $user = orchestrationUser();
    test()->actingAs($user)->post(route('payment-methods.store'), orchestrationSessionPayload())->assertRedirect();
    $session = Efevoo3dsSession::first();

    orchestrationPoll($user, $session)
        ->assertOk()
        ->assertJsonPath('final', true)
        ->assertJsonPath('status', PaymentAuthenticationAttemptStatus::TokenizationConfirmationPending->value);

    expect($calls['finalize3DSTokenization'] ?? 0)->toBe(1);
});

it('get result resource does not append recovery blocked events', function () {
    $calls = [];
    bindOrchestrationGateway($calls);
    $user = orchestrationUser();
    test()->actingAs($user)->post(route('payment-methods.store'), orchestrationSessionPayload())->assertRedirect();
    $session = Efevoo3dsSession::first();
    orchestrationPoll($user, $session)->assertOk();

    $attempt = PaymentAuthenticationAttempt::first();
    $before = PaymentAuthenticationAttemptEvent::count();

    app(PaymentAuthentication3dsResultResource::class)->make(
        $session->fresh(),
        $user->customer,
        $attempt->fresh()
    );

    expect(PaymentAuthenticationAttemptEvent::count())->toBe($before)
        ->and(PaymentAuthenticationAttemptEvent::where('event_type', PaymentAuthenticationAttemptEventType::RecoveryBlocked->value)->count())->toBe(0);
});

it('strips cvv before tokencard dispatch', function () {
    $calls = [];
    bindOrchestrationGateway($calls);
    $user = orchestrationUser();
    test()->actingAs($user)->post(route('payment-methods.store'), orchestrationSessionPayload())->assertRedirect();
    $session = Efevoo3dsSession::first();

    orchestrationPoll($user, $session)->assertOk();

    expect($calls['finalize_has_cvv'] ?? true)->toBeFalse();
});

it('terminal revisit does not call getstatus or tokencard again', function () {
    $calls = [];
    bindOrchestrationGateway($calls);
    $user = orchestrationUser();
    test()->actingAs($user)->post(route('payment-methods.store'), orchestrationSessionPayload())->assertRedirect();
    $session = Efevoo3dsSession::first();
    orchestrationPoll($user, $session)->assertOk();

    $polls = $calls['poll3DSAuthentication'] ?? 0;
    $tokens = $calls['finalize3DSTokenization'] ?? 0;

    orchestrationPoll($user, $session->fresh())->assertOk();
    test()->actingAs($user)->get(route('payment-methods.3ds-result', $session))->assertOk();

    expect($calls['poll3DSAuthentication'] ?? 0)->toBe($polls)
        ->and($calls['finalize3DSTokenization'] ?? 0)->toBe($tokens);
});

it('concurrent poll cycles during tokenization do not duplicate tokencard', function () {
    $calls = [];
    bindOrchestrationGateway($calls, finalize: function () {
        usleep(150000);

        return ['success' => true, 'external_tokenization_attempted' => true];
    });

    $user = orchestrationUser();
    test()->actingAs($user)->post(route('payment-methods.store'), orchestrationSessionPayload())->assertRedirect();
    $session = Efevoo3dsSession::first();

    Cache::lock('efevoo_3ds_poll_cycle_'.$session->id, 30)->get();

    orchestrationPoll($user, $session)->assertOk();

    expect($calls['finalize3DSTokenization'] ?? 0)->toBeLessThanOrEqual(1);
});
