<?php

use App\Contracts\EfevooPayGateway;
use App\Enums\PaymentAuthenticationAttemptEventType;
use App\Enums\PaymentAuthenticationAttemptStatus;
use App\Models\Efevoo3dsSession;
use App\Models\EfevooToken;
use App\Models\PaymentAuthenticationAttempt;
use App\Models\PaymentAuthenticationAttemptEvent;
use App\Models\User;
use App\Services\PaymentAuthenticationAttempts\PaymentAuthenticationEfevooPayOperationAnalyzer;
use App\Support\EfevooPay3dsResultClassifier;
use App\Support\EfevooPayTokenizeContract;
use App\Support\PaymentAuthentication3dsExternalCallGuard;
use App\Support\PaymentAuthentication3dsProviderCallException;
use App\Support\PaymentAuthentication3dsResultResource;
use App\Support\PaymentAuthenticationAttemptAdminResource;
use App\Support\PaymentAuthenticationEfevooPayAmounts;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;

beforeEach(function () {
    config([
        'efevoopay.gateway' => 'mock',
        'efevoopay.requires_3ds' => true,
        'efevoopay.sensitive_card_data.containment_enabled' => true,
        'efevoopay.three_ds_verification_amount_cents' => 150,
        'efevoopay.tokenization_verification_amount_cents' => 150,
        'efevoopay.local_real_tests.enabled' => false,
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
                $result = ($this->finalize)($session, $cardData);

                if (! ($result['success'] ?? false) && ! ($result['confirmation_pending'] ?? false)) {
                    $session->update([
                        'status' => 'tokenization_failed',
                        'error_message' => $result['message'] ?? 'Tokenización fallida',
                    ]);
                }

                return $result;
            }

            $token = EfevooToken::create([
                'customer_id' => $session->customer_id,
                'card_token' => 'mock_tok_flow_'.Str::random(8),
                'card_last_four' => substr(preg_replace('/\D/', '', $cardData['card_number'] ?? '4242'), -4),
                'card_expiration' => $cardData['expiration'] ?? '1229',
                'card_holder' => $cardData['card_holder'] ?? 'Flow Test',
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

            return ['success' => true, 'token_id' => $token->id, 'transaction_id' => 'TOK-TX-1'];
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

function completeFlowThrough(User $user, Efevoo3dsSession $session, array $cardData = []): void
{
    $response = completeFlowPoll($user, $session, $cardData)->assertOk();

    if (! ($response->json('final') ?? false)) {
        completeFlowPoll($user, $session, $cardData)->assertOk();
    }
}

it('normal flow uses one GetLink one GetStatus and one TokenCard with configured amounts', function () {
    $calls = [];
    bindFlowGateway($calls);
    $user = flowUser();

    $this->actingAs($user)->post(route('payment-methods.store'), flowPayload())->assertRedirect();

    $session = Efevoo3dsSession::first();
    completeFlowThrough($user, $session);

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

    Cache::lock('efevoo_3ds_poll_cycle_'.$session->id, 30)->get();

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
    completeFlowThrough($user, Efevoo3dsSession::first());

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
    completeFlowThrough($user, Efevoo3dsSession::first());

    expect($calls['finalize3DSTokenization'] ?? 0)->toBe(0)
        ->and(PaymentAuthenticationAttempt::first()->tokenization_call_count)->toBe(0);
});

it('exception before GetStatus dispatch is not recorded as network timeout', function () {
    $calls = [];
    bindFlowGateway($calls, poll: function () {
        throw new PaymentAuthentication3dsProviderCallException(
            'encrypt_payload',
            'technical_error_before_dispatch',
            false,
            false
        );
    });
    $user = flowUser();
    $this->actingAs($user)->post(route('payment-methods.store'), flowPayload())->assertRedirect();

    completeFlowThrough($user, Efevoo3dsSession::first());

    $attempt = PaymentAuthenticationAttempt::first();
    $events = $attempt->events()->pluck('event_type')->all();
    $failed = $attempt->events()
        ->where('event_type', PaymentAuthenticationAttemptEventType::ProviderStatusRequestFailed->value)
        ->first();

    expect($events)->toContain(PaymentAuthenticationAttemptEventType::ProviderStatusRequestFailed->value)
        ->and($events)->not->toContain(PaymentAuthenticationAttemptEventType::ProviderStatusRequestTimeout->value)
        ->and($failed->allowlistedMetadata()['failure_stage'] ?? null)->toBe('encrypt_payload')
        ->and($failed->allowlistedMetadata()['exception_category'] ?? null)->toBe('technical_error_before_dispatch')
        ->and($failed->allowlistedMetadata()['request_dispatched'] ?? null)->toBeFalse()
        ->and($attempt->fresh()->status)->toBe(PaymentAuthenticationAttemptStatus::TechnicalError->value);
});

it('timeout after GetStatus dispatch stays confirmation pending without success events', function () {
    $calls = [];
    bindFlowGateway($calls, poll: function () {
        throw new PaymentAuthentication3dsProviderCallException(
            'request_get_status',
            'network_timeout_after_dispatch',
            true,
            false,
            null,
            30000
        );
    });
    $user = flowUser();
    $this->actingAs($user)->post(route('payment-methods.store'), flowPayload())->assertRedirect();

    completeFlowThrough($user, Efevoo3dsSession::first());

    $attempt = PaymentAuthenticationAttempt::first();
    $events = $attempt->events()->pluck('event_type')->all();

    expect($attempt->fresh()->status)->toBe(PaymentAuthenticationAttemptStatus::ProviderConfirmationPending->value)
        ->and($events)->toContain(PaymentAuthenticationAttemptEventType::ProviderStatusRequestTimeout->value)
        ->and($events)->toContain(PaymentAuthenticationAttemptEventType::StatusPollFailed->value)
        ->and($events)->not->toContain(PaymentAuthenticationAttemptEventType::StatusPollSucceeded->value)
        ->and($events)->not->toContain(PaymentAuthenticationAttemptEventType::ProviderStatusReceived->value)
        ->and($events)->toContain(PaymentAuthenticationAttemptEventType::SensitiveCardDataPurged->value);
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
    completeFlowThrough($user, Efevoo3dsSession::first());

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
        ->and($analysis['disclaimer'])->toContain('no demuestra');
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

it('network GetStatus error without timed_out is not recorded as timeout', function () {
    $calls = [];
    bindFlowGateway($calls, poll: fn () => [
        'phase' => 'error',
        'success' => false,
        'error_type' => 'network',
        'timed_out' => false,
        'failure_stage' => 'get_client_token',
        'exception_category' => 'technical_error_before_dispatch',
        'request_dispatched' => false,
        'response_received' => false,
        'duration_ms' => 707,
    ]);
    $user = flowUser();
    $this->actingAs($user)->post(route('payment-methods.store'), flowPayload())->assertRedirect();

    completeFlowPoll($user, Efevoo3dsSession::first())
        ->assertOk()
        ->assertJsonPath('status', PaymentAuthenticationAttemptStatus::TechnicalError->value)
        ->assertJsonPath('final', true);

    $attempt = PaymentAuthenticationAttempt::first();
    $events = $attempt->events()->pluck('event_type')->all();

    expect($events)->toContain(PaymentAuthenticationAttemptEventType::ProviderStatusRequestFailed->value)
        ->and($events)->not->toContain(PaymentAuthenticationAttemptEventType::ProviderStatusRequestTimeout->value)
        ->and($attempt->fresh()->status)->toBe(PaymentAuthenticationAttemptStatus::TechnicalError->value);
});

it('status endpoint keeps provider_confirmation_pending as string with clock fields', function () {
    $calls = [];
    bindFlowGateway($calls, poll: function () {
        throw new PaymentAuthentication3dsProviderCallException(
            'request_get_status',
            'network_timeout_after_dispatch',
            true,
            false,
            null,
            30000
        );
    });
    $user = flowUser();
    $this->actingAs($user)->post(route('payment-methods.store'), flowPayload())->assertRedirect();

    completeFlowPoll($user, Efevoo3dsSession::first())
        ->assertOk()
        ->assertJsonPath('status', 'provider_confirmation_pending')
        ->assertJsonPath('final', true)
        ->assertJsonStructure(['server_now', 'expires_at', 'started_at', 'support_reference']);
});

it('executes tokencard in the same poll cycle when getstatus returns approved', function () {
    $calls = [];
    bindFlowGateway($calls);
    $user = flowUser();

    $this->actingAs($user)->post(route('payment-methods.store'), flowPayload())->assertRedirect();
    $session = Efevoo3dsSession::first();

    completeFlowPoll($user, $session)
        ->assertOk()
        ->assertJsonPath('final', true)
        ->assertJsonPath('status', 'completed');

    $attempt = PaymentAuthenticationAttempt::first();

    expect($calls['poll3DSAuthentication'] ?? 0)->toBe(1)
        ->and($calls['finalize3DSTokenization'] ?? 0)->toBe(1)
        ->and($attempt->events()->where('event_type', PaymentAuthenticationAttemptEventType::AuthenticationSucceeded->value)->count())->toBe(1)
        ->and($attempt->events()->where('event_type', PaymentAuthenticationAttemptEventType::TokenizationStarted->value)->count())->toBe(1)
        ->and($attempt->fresh()->status)->toBe(PaymentAuthenticationAttemptStatus::Completed->value);
});

it('classifies getstatus approved plus tokencard business failure http 200', function () {
    $calls = [];
    bindFlowGateway($calls, finalize: fn () => [
        'success' => false,
        'message' => 'No aprobada por el procesador',
        'error_type' => 'gateway',
        'error_code' => '00',
        'provider_code' => '00',
        'provider_message' => 'No aprobada por el procesador',
        'http_status' => 200,
        'response_received' => true,
        'duration_ms' => 120,
        'failure_stage' => 'tokenize_response',
        'exception_category' => 'tokenization_business_failure',
        'external_tokenization_attempted' => true,
    ]);
    $user = flowUser();
    $this->actingAs($user)->post(route('payment-methods.store'), flowPayload())->assertRedirect();
    $session = Efevoo3dsSession::first();

    completeFlowThrough($user, $session);

    $attempt = PaymentAuthenticationAttempt::first()->fresh();
    $events = $attempt->events()->pluck('event_type')->all();

    expect($calls['finalize3DSTokenization'])->toBe(1)
        ->and($attempt->failure_category)->toBe(EfevooPay3dsResultClassifier::CATEGORY_TOKENIZATION_FAILED)
        ->and($attempt->provider_message)->toContain('procesador')
        ->and($events)->toContain(PaymentAuthenticationAttemptEventType::AuthenticationSucceeded->value)
        ->and($events)->toContain(PaymentAuthenticationAttemptEventType::TokenizationRequestFailed->value)
        ->and($events)->toContain(PaymentAuthenticationAttemptEventType::TokenizationFailed->value)
        ->and($events)->not->toContain(PaymentAuthenticationAttemptEventType::CardVerified->value);

    $failedEvent = $attempt->events()
        ->where('event_type', PaymentAuthenticationAttemptEventType::TokenizationRequestFailed->value)
        ->first();
    $meta = $failedEvent->allowlistedMetadata();

    expect($meta['response_received'] ?? null)->toBeTrue()
        ->and($meta['http_status'] ?? null)->toBe(200)
        ->and($meta['failure_stage'] ?? null)->toBe('tokenize_response')
        ->and($meta['exception_category'] ?? null)->toBe('tokenization_business_failure')
        ->and(array_key_exists('card_number', $meta))->toBeFalse()
        ->and(array_intersect(array_keys($meta), EfevooPayTokenizeContract::TOKENIZE_FORBIDDEN_METADATA_KEYS))->toBe([]);
});

it('revisit after tokenization failure is read only without extra tokencard', function () {
    $calls = [];
    bindFlowGateway($calls, finalize: fn () => [
        'success' => false,
        'message' => 'Tokenización no aprobada',
        'error_type' => 'gateway',
        'provider_code' => '05',
        'provider_message' => 'Tokenización no aprobada',
        'http_status' => 200,
        'response_received' => true,
        'failure_stage' => 'tokenize_response',
        'exception_category' => 'tokenization_business_failure',
        'external_tokenization_attempted' => true,
    ]);
    $user = flowUser();
    $this->actingAs($user)->post(route('payment-methods.store'), flowPayload())->assertRedirect();
    $session = Efevoo3dsSession::first();
    completeFlowThrough($user, $session);

    $tokenCalls = PaymentAuthenticationAttempt::first()->tokenization_call_count;

    $this->actingAs($user)->get(route('payment-methods.3ds-result', $session))->assertOk();
    $this->actingAs($user)->postJson(route('payment-methods.3ds-result-sync', ['sessionId' => $session->id]))->assertOk();
    $this->actingAs($user)->postJson(route('payment-methods.3ds-result-sync', ['sessionId' => $session->id]))->assertOk();

    expect($calls['finalize3DSTokenization'])->toBe(1)
        ->and(PaymentAuthenticationAttempt::first()->fresh()->tokenization_call_count)->toBe($tokenCalls);
});

it('result resource exposes tokenization_failed presentation without bank rejection copy', function () {
    $user = flowUser();
    $attempt = PaymentAuthenticationAttempt::factory()->create([
        'customer_id' => $user->customer->id,
        'status' => PaymentAuthenticationAttemptStatus::TechnicalError->value,
        'failure_category' => EfevooPay3dsResultClassifier::CATEGORY_TOKENIZATION_FAILED,
        'failure_origin' => EfevooPay3dsResultClassifier::ORIGIN_EFEVOOPAY,
        'provider_message' => 'Reservado para uso privado o Bad Track Data',
    ]);
    $session = Efevoo3dsSession::create([
        'customer_id' => $user->customer->id,
        'payment_authentication_attempt_id' => $attempt->id,
        'order_id' => '31189',
        'card_last_four' => '2313',
        'amount' => PaymentAuthenticationEfevooPayAmounts::threeDsVerificationAmount(),
        'status' => 'tokenization_failed',
        'error_message' => 'Reservado para uso privado o Bad Track Data',
    ]);
    $attempt->update(['efevoo_3ds_session_id' => $session->id]);

    $result = app(PaymentAuthentication3dsResultResource::class)->make(
        $session,
        $user->customer,
        $attempt->fresh()
    );

    expect($result['presentation'])->toBe('tokenization_failed')
        ->and($result['copy']['title'])->toContain('no pudimos guardar la tarjeta')
        ->and($result['copy']['message'])->toBe('La verificación con tu banco se completó, pero no pudimos guardar la tarjeta. Puedes volver a intentarlo o usar otra tarjeta.')
        ->and($result['copy']['hint'])->toContain('No necesitas contactar al banco');
});

it('admin analyzer separates approved authentication from failed card storage', function () {
    $user = flowUser();
    $attempt = PaymentAuthenticationAttempt::factory()->create([
        'customer_id' => $user->customer->id,
        'status' => PaymentAuthenticationAttemptStatus::TechnicalError->value,
        'failure_category' => EfevooPay3dsResultClassifier::CATEGORY_TOKENIZATION_FAILED,
        'provider_code' => '00',
        'provider_message' => 'Reservado para uso privado o Bad Track Data',
        'tokenization_call_count' => 1,
        'status_poll_call_count' => 4,
        'provider_link_call_count' => 1,
    ]);
    $session = Efevoo3dsSession::create([
        'customer_id' => $user->customer->id,
        'payment_authentication_attempt_id' => $attempt->id,
        'order_id' => '31189',
        'card_last_four' => '2313',
        'amount' => 1.5,
        'status' => 'tokenization_failed',
    ]);
    $attempt->update(['efevoo_3ds_session_id' => $session->id]);

    PaymentAuthenticationAttemptEvent::create([
        'event_uuid' => (string) Str::uuid(),
        'payment_authentication_attempt_id' => $attempt->id,
        'event_type' => PaymentAuthenticationAttemptEventType::AuthenticationSucceeded->value,
        'source' => 'efevoopay',
        'dedupe_key' => 'authentication_succeeded:test',
        'occurred_at' => now(),
        'created_at' => now(),
    ]);

    $operations = app(PaymentAuthenticationEfevooPayOperationAnalyzer::class)->analyze($attempt->fresh(), $session);

    expect($operations['authentication_3ds']['result'])->toBe('approved')
        ->and($operations['card_storage']['result'])->toBe('failed')
        ->and($operations['token_card']['result'])->toBe('business_failed')
        ->and($operations['token_card']['http_business_outcome'])->toBe('http_200_business_failed')
        ->and($operations['overall_result_label'])->toBe('Autenticación aprobada; tokenización fallida');
});
