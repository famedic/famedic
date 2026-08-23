<?php

use App\Contracts\EfevooPayGateway;
use App\Enums\PaymentAuthenticationAttemptEventType;
use App\Enums\PaymentAuthenticationAttemptStatus;
use App\Models\Efevoo3dsSession;
use App\Models\PaymentAuthenticationAttempt;
use App\Models\PaymentAuthenticationAttemptEvent;
use App\Models\User;
use App\Services\PaymentAuthenticationAttempts\PaymentAuthenticationEfevooPayOperationAnalyzer;
use App\Support\PaymentAuthentication3dsExternalCallGuard;
use App\Support\PaymentAuthenticationAttemptAdminResource;
use App\Support\PaymentAuthenticationEfevooPayAmounts;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;

beforeEach(function () {
    config([
        'efevoopay.requires_3ds' => true,
        'efevoopay.sensitive_card_data.containment_enabled' => true,
        'efevoopay.three_ds_verification_amount_cents' => 150,
        'efevoopay.tokenization_verification_amount_cents' => 150,
    ]);
});

function flowUser(): User
{
    return User::factory()
        ->withCompleteProfile()
        ->withRegularCustomer()
        ->create(['documentation_accepted_at' => now()])
        ->fresh(['customer']);
}

function flowPayload(array $overrides = []): array
{
    return array_merge([
        'card_number' => '4242424242424242',
        'exp_month' => '12',
        'exp_year' => '29',
        'cvv' => '123',
        'card_holder' => 'TEST USER',
        'alias' => 'visa-4242',
        'terms_accepted' => '1',
        'attempt_uuid' => (string) Str::uuid(),
    ], $overrides);
}

function flowSensitiveSession(User $user, int $sessionId, array $cardData): array
{
    return [
        '3ds_card_data_'.$sessionId => array_merge($cardData, [
            'stored_at' => now()->timestamp,
            'expires_at' => now()->addMinutes(5)->timestamp,
            'customer_id' => $user->customer->id,
            'authentication_attempt_id' => null,
            'efevoo_3ds_session_id' => $sessionId,
        ]),
    ];
}

function bindFlowGateway(array &$calls, ?callable $initiate = null, ?callable $poll = null, ?callable $finalize = null): void
{
    app()->instance(EfevooPayGateway::class, new class($calls, $initiate, $poll, $finalize) implements EfevooPayGateway
    {
        public function __construct(
            private array &$calls,
            private $initiate,
            private $poll,
            private $finalize,
        ) {}

        public function chargeCard(array $data): array
        {
            $this->calls['chargeCard'] = ($this->calls['chargeCard'] ?? 0) + 1;
            $this->calls['chargeCard_has_cvv'] = array_key_exists('cvv', $data) && $data['cvv'] !== null && $data['cvv'] !== '';

            return ['success' => true, 'transaction_id' => 'TX-1'];
        }

        public function tokenizeCard(array $cardData, int $customerId): array
        {
            return ['success' => true, 'token_id' => 1];
        }

        public function initiate3DS(array $cardData, int $customerId): array
        {
            $this->calls['initiate3DS'] = ($this->calls['initiate3DS'] ?? 0) + 1;
            $this->calls['initiate_amount'] = $cardData['amount'] ?? null;
            $this->calls['initiate_has_cvv'] = array_key_exists('cvv', $cardData) && $cardData['cvv'] !== '';

            if ($this->initiate) {
                return ($this->initiate)($cardData, $customerId);
            }

            $session = Efevoo3dsSession::create([
                'customer_id' => $customerId,
                'order_id' => 'ORDER-'.Str::upper(Str::random(6)),
                'card_last_four' => '4242',
                'amount' => $cardData['amount'] ?? 1.5,
                'status' => 'mock_pending',
                'url_3dsecure' => 'https://issuer.example/challenge',
                'token_3dsecure' => 'mock-token',
            ]);

            return [
                'success' => true,
                'session_id' => $session->id,
                'url_3dsecure' => $session->url_3dsecure,
                'token_3dsecure' => $session->token_3dsecure,
            ];
        }

        public function complete3DS(Efevoo3dsSession $session, array $cardData): array
        {
            return ['success' => true];
        }

        public function poll3DSAuthentication(Efevoo3dsSession $session, array $cardData): array
        {
            $this->calls['poll3DSAuthentication'] = ($this->calls['poll3DSAuthentication'] ?? 0) + 1;
            $this->calls['poll_has_cvv'] = array_key_exists('cvv', $cardData) && $cardData['cvv'] !== '';

            if ($this->poll) {
                return ($this->poll)($session, $cardData);
            }

            $session->update(['status' => 'authenticated']);

            return ['phase' => 'authenticated', 'success' => true];
        }

        public function finalize3DSTokenization(Efevoo3dsSession $session, array $cardData): array
        {
            $this->calls['finalize3DSTokenization'] = ($this->calls['finalize3DSTokenization'] ?? 0) + 1;
            $this->calls['finalize_has_cvv'] = array_key_exists('cvv', $cardData) && $cardData['cvv'] !== null && $cardData['cvv'] !== '';
            $this->calls['finalize_amount'] = $cardData['amount'] ?? null;

            if ($this->finalize) {
                return ($this->finalize)($session, $cardData);
            }

            $session->update(['status' => 'completed', 'completed_at' => now()]);

            return ['success' => true, 'transaction_id' => 'TOK-TX-1'];
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

function completeFlowPoll(User $user, Efevoo3dsSession $session, array $cardData = []): \Illuminate\Testing\TestResponse
{
    $cardData = array_merge([
        'card_number' => '4242424242424242',
        'expiration' => '1229',
        'cvv' => '123',
        'amount' => PaymentAuthenticationEfevooPayAmounts::threeDsVerificationAmount(),
    ], $cardData);

    return test()->actingAs($user)->withSession(
        flowSensitiveSession($user, $session->id, $cardData)
    )->getJson(route('payment-methods.3ds-status', $session));
}

it('normal flow uses one GetLink one GetStatus and one TokenCard with configured amounts', function () {
    $calls = [];
    bindFlowGateway($calls);
    $user = flowUser();

    $this->actingAs($user)->post(route('payment-methods.store'), flowPayload())->assertRedirect();

    $session = Efevoo3dsSession::first();
    completeFlowPoll($user, $session)->assertOk();

    $attempt = PaymentAuthenticationAttempt::first();

    expect($calls['initiate3DS'])->toBe(1)
        ->and($calls['poll3DSAuthentication'])->toBe(1)
        ->and($calls['finalize3DSTokenization'])->toBe(1)
        ->and($calls['initiate_amount'])->toBe(1.5)
        ->and($calls['finalize_amount'])->toBe(1.5)
        ->and($attempt->provider_link_call_count)->toBe(1)
        ->and($attempt->status_poll_call_count)->toBe(1)
        ->and($attempt->tokenization_call_count)->toBe(1);
});

it('double submit keeps GetLink at one', function () {
    $calls = [];
    bindFlowGateway($calls);
    $user = flowUser();
    $uuid = (string) Str::uuid();

    $this->actingAs($user)->post(route('payment-methods.store'), flowPayload(['attempt_uuid' => $uuid]))->assertRedirect();
    $this->actingAs($user)->post(route('payment-methods.store'), flowPayload(['attempt_uuid' => $uuid]))->assertRedirect();

    expect($calls['initiate3DS'])->toBe(1);
});

it('second tab uuid is blocked with one GetLink', function () {
    $calls = [];
    bindFlowGateway($calls);
    $user = flowUser();

    $this->actingAs($user)->post(route('payment-methods.store'), flowPayload())->assertRedirect();
    $this->actingAs($user)->postJson(route('payment-methods.store'), flowPayload())->assertConflict();

    expect($calls['initiate3DS'])->toBe(1);
});

it('blocks concurrent external GetStatus polls with cache lock', function () {
    $calls = [];
    bindFlowGateway($calls);
    $user = flowUser();
    $this->actingAs($user)->post(route('payment-methods.store'), flowPayload())->assertRedirect();
    $session = Efevoo3dsSession::first();
    $attempt = PaymentAuthenticationAttempt::first();

    Cache::lock('efevoo_3ds_getstatus_'.$session->id, 30)->get();

    completeFlowPoll($user, $session)->assertOk();

    expect($calls['poll3DSAuthentication'] ?? 0)->toBe(0)
        ->and($attempt->fresh()->events()->where('event_type', PaymentAuthenticationAttemptEventType::DuplicateExternalCallBlocked->value)->exists())->toBeTrue();
});

it('refreshing redirect does not add GetLink or TokenCard calls', function () {
    $calls = [];
    bindFlowGateway($calls);
    $user = flowUser();

    $this->actingAs($user)->post(route('payment-methods.store'), flowPayload())->assertRedirect();
    $session = Efevoo3dsSession::first();

    $this->actingAs($user)->get(route('payment-methods.3ds-redirect', $session))->assertOk();
    $this->actingAs($user)->get(route('payment-methods.3ds-redirect', $session))->assertOk();

    expect($calls['initiate3DS'])->toBe(1)
        ->and($calls['finalize3DSTokenization'] ?? 0)->toBe(0);
});

it('tokenizes without cvv after authentication', function () {
    $calls = [];
    bindFlowGateway($calls);
    $user = flowUser();
    $this->actingAs($user)->post(route('payment-methods.store'), flowPayload())->assertRedirect();
    completeFlowPoll($user, Efevoo3dsSession::first())->assertOk();

    expect($calls['finalize_has_cvv'])->toBeFalse();
});

it('tokenization timeout leaves confirmation pending without automatic retry', function () {
    $calls = [];
    bindFlowGateway($calls, finalize: fn () => [
        'success' => false,
        'confirmation_pending' => true,
        'message' => 'pending',
        'error_type' => 'system',
    ]);
    $user = flowUser();
    $this->actingAs($user)->post(route('payment-methods.store'), flowPayload())->assertRedirect();
    $session = Efevoo3dsSession::first();

    completeFlowPoll($user, $session)->assertOk();
    completeFlowPoll($user, $session)->assertOk();

    expect($calls['finalize3DSTokenization'])->toBe(1)
        ->and(PaymentAuthenticationAttempt::first()->status)->toBe(PaymentAuthenticationAttemptStatus::TokenizationConfirmationPending->value);
});

it('allows only one external tokenization call for duplicate finalize requests', function () {
    $calls = [];
    bindFlowGateway($calls);
    $user = flowUser();
    $this->actingAs($user)->post(route('payment-methods.store'), flowPayload())->assertRedirect();
    $session = Efevoo3dsSession::first();
    $attempt = PaymentAuthenticationAttempt::first();
    $attempt->update(['status' => PaymentAuthenticationAttemptStatus::Authenticated->value]);

    $guard = app(PaymentAuthentication3dsExternalCallGuard::class);
    $first = $guard->withTokenizationClaim($session, $attempt, fn () => ['success' => true, 'transaction_id' => 'A']);
    $second = $guard->withTokenizationClaim($session->fresh(), $attempt->fresh(), fn () => ['success' => true, 'transaction_id' => 'B']);

    expect($first['duplicate'] ?? false)->toBeFalse()
        ->and($second['duplicate'] ?? true)->toBeTrue()
        ->and($attempt->fresh()->tokenization_call_count)->toBe(1);
});

it('charges saved token without cvv in payload', function () {
    $calls = [];
    bindFlowGateway($calls);

    app(EfevooPayGateway::class)->chargeCard([
        'card_token' => 'mock_tok_abc',
        'amount' => 10,
        'reference' => 'REF-1',
    ]);

    expect($calls['chargeCard_has_cvv'])->toBeFalse();
});

it('does not tokenize on declined terminal poll', function () {
    $calls = [];
    bindFlowGateway($calls, poll: function (Efevoo3dsSession $session) {
        $session->update(['status' => 'declined']);

        return ['phase' => 'declined', 'success' => false];
    });
    $user = flowUser();
    $this->actingAs($user)->post(route('payment-methods.store'), flowPayload())->assertRedirect();
    completeFlowPoll($user, Efevoo3dsSession::first())->assertOk();

    expect($calls['finalize3DSTokenization'] ?? 0)->toBe(0)
        ->and(PaymentAuthenticationAttempt::first()->tokenization_call_count)->toBe(0);
});

it('skips GetStatus and TokenCard when sensitive card data ttl expired', function () {
    $calls = [];
    bindFlowGateway($calls);
    $user = flowUser();
    $this->actingAs($user)->post(route('payment-methods.store'), flowPayload())->assertRedirect();
    $session = Efevoo3dsSession::first();

    test()->actingAs($user)->withSession([
        '3ds_card_data_'.$session->id => [
            'card_number' => '4242424242424242',
            'expiration' => '1229',
            'cvv' => '123',
            'amount' => 1.5,
            'stored_at' => now()->subMinutes(10)->timestamp,
            'expires_at' => now()->subMinute()->timestamp,
            'customer_id' => $user->customer->id,
            'efevoo_3ds_session_id' => $session->id,
        ],
    ])->getJson(route('payment-methods.3ds-status', $session))->assertOk();

    expect($calls['poll3DSAuthentication'] ?? 0)->toBe(0)
        ->and($calls['finalize3DSTokenization'] ?? 0)->toBe(0);
});

it('does not store pan cvv token or payload in monetary trace events', function () {
    $calls = [];
    bindFlowGateway($calls);
    $user = flowUser();
    $this->actingAs($user)->post(route('payment-methods.store'), flowPayload())->assertRedirect();
    completeFlowPoll($user, Efevoo3dsSession::first());

    $forbiddenKeys = [
        'cvv', 'pan', 'card_number', 'track', 'track2', 'payload', 'card_token',
        'client_token', 'authorization', 'encrypt', 'card_last4', 'last4',
    ];

    PaymentAuthenticationAttemptEvent::all()->each(function (PaymentAuthenticationAttemptEvent $event) use ($forbiddenKeys) {
        $metadata = $event->allowlistedMetadata();
        foreach (array_keys($metadata) as $key) {
            expect($forbiddenKeys)->not->toContain($key);
        }

        $blob = json_encode($metadata);
        expect($blob)->not->toContain('4242424242424242')
            ->not->toMatch('/"cvv"\s*:/');
    });
});

it('analyzer flags possible duplicate verification conservatively not confirmed double charge', function () {
    $attempt = PaymentAuthenticationAttempt::create([
        'attempt_uuid' => (string) Str::uuid(),
        'support_reference' => 'AUTH-DUP',
        'customer_id' => flowUser()->customer->id,
        'operation_type' => PaymentAuthenticationAttempt::OPERATION_CARD_VERIFICATION_3DS,
        'provider' => PaymentAuthenticationAttempt::PROVIDER_EFEVOOPAY,
        'status' => PaymentAuthenticationAttemptStatus::Completed->value,
        'merchant_reference' => 'EFV3DS-DUP',
        'provider_link_call_count' => 2,
        'tokenization_call_count' => 1,
        'started_at' => now(),
    ]);

    $analysis = app(PaymentAuthenticationEfevooPayOperationAnalyzer::class)->analyze($attempt);

    expect($analysis['possible_duplicate_verification_operation'])->toBeTrue()
        ->and($analysis['disclaimer'])->toContain('no prueban');
});

it('export row excludes sensitive card fields', function () {
    $attempt = PaymentAuthenticationAttempt::create([
        'attempt_uuid' => (string) Str::uuid(),
        'support_reference' => 'AUTH-EXP',
        'customer_id' => flowUser()->customer->id,
        'operation_type' => PaymentAuthenticationAttempt::OPERATION_CARD_VERIFICATION_3DS,
        'provider' => PaymentAuthenticationAttempt::PROVIDER_EFEVOOPAY,
        'status' => PaymentAuthenticationAttemptStatus::Completed->value,
        'merchant_reference' => 'EFV3DS-EXP',
        'provider_link_call_count' => 1,
        'status_poll_call_count' => 1,
        'tokenization_call_count' => 1,
        'started_at' => now(),
    ]);

    $row = PaymentAuthenticationAttemptAdminResource::exportRow($attempt);
    $blob = json_encode($row);

    expect($blob)->not->toContain('4242')
        ->not->toContain('cvv')
        ->not->toContain('payload');
});

it('enforces per-attempt GetStatus ceiling with simulated clock and containment', function () {
    $startedAt = now()->startOfSecond();
    \Illuminate\Support\Carbon::setTestNow($startedAt);

    config([
        'efevoopay.polling.max_external_status_polls' => 60,
        'efevoopay.polling.interval_seconds' => 5,
        'efevoopay.sensitive_card_data.ttl_minutes' => 5,
    ]);

    $calls = [];
    bindFlowGateway($calls, poll: fn () => ['phase' => 'pending', 'success' => true]);

    $userA = flowUser();
    $this->actingAs($userA)->post(route('payment-methods.store'), flowPayload(['attempt_uuid' => (string) Str::uuid()]))->assertRedirect();
    $sessionA = Efevoo3dsSession::where('customer_id', $userA->customer->id)->first();
    $attemptA = PaymentAuthenticationAttempt::where('customer_id', $userA->customer->id)->first();

    $userB = flowUser();
    $this->actingAs($userB)->post(route('payment-methods.store'), flowPayload(['attempt_uuid' => (string) Str::uuid()]))->assertRedirect();
    $sessionB = Efevoo3dsSession::where('customer_id', $userB->customer->id)->first();
    $attemptB = PaymentAuthenticationAttempt::where('customer_id', $userB->customer->id)->first();

    $pollCallsBeforeCeiling = (int) ($calls['poll3DSAuthentication'] ?? 0);

    $attemptA->update(['status_poll_call_count' => 59]);
    $attemptB->update(['status_poll_call_count' => 0]);

    completeFlowPoll($userA, $sessionA)->assertOk();
    \Illuminate\Support\Carbon::setTestNow($startedAt->copy()->addSeconds(5));

    expect(($calls['poll3DSAuthentication'] ?? 0) - $pollCallsBeforeCeiling)->toBe(1)
        ->and($attemptA->fresh()->status_poll_call_count)->toBe(60);

    $pollCallsAtLimit = (int) ($calls['poll3DSAuthentication'] ?? 0);

    completeFlowPoll($userA, $sessionA)->assertOk();
    \Illuminate\Support\Carbon::setTestNow($startedAt->copy()->addSeconds(10));

    expect($calls['poll3DSAuthentication'] ?? 0)->toBe($pollCallsAtLimit);

    completeFlowPoll($userB, $sessionB)->assertOk();
    expect(($calls['poll3DSAuthentication'] ?? 0) - $pollCallsAtLimit)->toBe(1)
        ->and($attemptB->fresh()->status_poll_call_count)->toBe(1);

    $sessionA->update(['status' => 'completed']);
    $pollCallsBeforeTerminal = (int) ($calls['poll3DSAuthentication'] ?? 0);
    completeFlowPoll($userA, $sessionA->fresh())->assertOk();
    expect($calls['poll3DSAuthentication'] ?? 0)->toBe($pollCallsBeforeTerminal);

    $pollCallsBeforeExpired = (int) ($calls['poll3DSAuthentication'] ?? 0);
    $this->actingAs($userB)->withSession([
        '3ds_card_data_'.$sessionB->id => [
            'card_number' => '4242424242424242',
            'expiration' => '1229',
            'cvv' => '123',
            'amount' => 1.5,
            'stored_at' => $startedAt->copy()->subMinutes(10)->timestamp,
            'expires_at' => $startedAt->copy()->subMinute()->timestamp,
            'customer_id' => $userB->customer->id,
            'efevoo_3ds_session_id' => $sessionB->id,
        ],
    ])->getJson(route('payment-methods.3ds-status', $sessionB))->assertOk();
    expect($calls['poll3DSAuthentication'] ?? 0)->toBe($pollCallsBeforeExpired);

    Cache::lock('efevoo_3ds_getstatus_'.$sessionB->id, 30)->get();
    $pollCallsBeforeConcurrent = (int) ($calls['poll3DSAuthentication'] ?? 0);
    $sessionB->update(['status' => 'mock_pending']);
    $attemptB->update(['status_poll_call_count' => 1, 'status' => PaymentAuthenticationAttemptStatus::Pending->value]);
    completeFlowPoll($userB, $sessionB->fresh())->assertOk();
    expect($calls['poll3DSAuthentication'] ?? 0)->toBe($pollCallsBeforeConcurrent);

    $forbiddenKeys = ['cvv', 'pan', 'card_number', 'track', 'track2', 'payload', 'card_token', 'client_token'];
    PaymentAuthenticationAttemptEvent::query()
        ->whereIn('payment_authentication_attempt_id', [$attemptA->id, $attemptB->id])
        ->get()
        ->each(function (PaymentAuthenticationAttemptEvent $event) use ($forbiddenKeys) {
            foreach (array_keys($event->allowlistedMetadata()) as $key) {
                expect($forbiddenKeys)->not->toContain($key);
            }
        });

    \Illuminate\Support\Carbon::setTestNow();
});

it('amount helpers expose separate getlink and tokenize values from config', function () {
    config([
        'efevoopay.three_ds_verification_amount_cents' => 150,
        'efevoopay.tokenization_verification_amount_cents' => 150,
    ]);

    $link = PaymentAuthenticationEfevooPayAmounts::forGetLink(['cvv' => '123', 'amount' => 99]);
    $token = PaymentAuthenticationEfevooPayAmounts::forTokenization(['cvv' => '123', 'amount' => 99]);

    expect($link['amount'])->toBe(1.5)
        ->and($link['cvv'])->toBe('123')
        ->and($token['amount'])->toBe(1.5)
        ->and(array_key_exists('cvv', $token))->toBeFalse();
});
