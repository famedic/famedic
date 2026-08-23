<?php

use App\Contracts\EfevooPayGateway;
use App\Enums\PaymentAuthenticationAttemptEventType;
use App\Enums\PaymentAuthenticationAttemptStatus;
use App\Models\Efevoo3dsSession;
use App\Models\PaymentAuthenticationAttempt;
use App\Models\PaymentAuthenticationAttemptEvent;
use App\Models\User;
use App\Support\EfevooPay3dsResultClassifier;
use App\Support\PaymentAuthenticationAttemptRecorder;
use Illuminate\Support\Str;

beforeEach(function () {
    config(['efevoopay.requires_3ds' => true]);
});

function authAttemptTraceabilityUser(): User
{
    return User::factory()
        ->withCompleteProfile()
        ->withRegularCustomer()
        ->create(['documentation_accepted_at' => now()])
        ->fresh(['customer']);
}

function traceabilityPayload(array $overrides = []): array
{
    return array_merge([
        'card_number' => '4242424242424242',
        'exp_month' => '12',
        'exp_year' => '29',
        'cvv' => '123',
        'card_holder' => 'TRACE USER',
        'alias' => 'trace-4242',
        'terms_accepted' => '1',
        'attempt_uuid' => (string) Str::uuid(),
    ], $overrides);
}

function traceabilityGateway(array &$calls, ?callable $initiate = null, ?callable $complete = null, ?callable $finalize = null): EfevooPayGateway
{
    return new class($calls, $initiate, $complete, $finalize) implements EfevooPayGateway
    {
        public function __construct(private array &$calls, private $initiate, private $complete, private $finalize) {}

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

            if ($this->initiate) {
                return ($this->initiate)($cardData, $customerId);
            }

            $session = Efevoo3dsSession::create([
                'customer_id' => $customerId,
                'order_id' => 'ORDER-'.Str::upper(Str::random(8)),
                'card_last_four' => '4242',
                'amount' => 1.5,
                'status' => 'mock_pending',
                'url_3dsecure' => 'https://issuer.example/challenge',
                'token_3dsecure' => 'secret-creq',
            ]);

            return ['success' => true, 'session_id' => $session->id];
        }

        public function complete3DS(Efevoo3dsSession $session, array $cardData): array
        {
            $this->calls['complete3DS'] = ($this->calls['complete3DS'] ?? 0) + 1;

            if ($this->complete) {
                return ($this->complete)($session, $cardData);
            }

            $session->update(['status' => 'completed', 'completed_at' => now()]);

            return ['success' => true, 'message' => 'completed'];
        }

        public function poll3DSAuthentication(Efevoo3dsSession $session, array $cardData): array
        {
            $this->calls['poll3DSAuthentication'] = ($this->calls['poll3DSAuthentication'] ?? 0) + 1;

            if ($this->complete) {
                $result = ($this->complete)($session, $cardData);

                return [
                    'phase' => ($result['success'] ?? false) ? 'authenticated' : 'declined',
                    'success' => (bool) ($result['success'] ?? false),
                    'message' => $result['message'] ?? null,
                ];
            }

            return ['phase' => 'authenticated', 'success' => true];
        }

        public function finalize3DSTokenization(Efevoo3dsSession $session, array $cardData): array
        {
            $this->calls['finalize3DSTokenization'] = ($this->calls['finalize3DSTokenization'] ?? 0) + 1;

            if ($this->finalize) {
                return ($this->finalize)($session, $cardData);
            }

            $session->update(['status' => 'completed', 'completed_at' => now()]);

            return ['success' => true, 'message' => 'completed'];
        }

        public function healthCheck(): array
        {
            return ['success' => true];
        }

        public function getTestCards(): array
        {
            return [];
        }
    };
}

function bindTraceabilityGateway(array &$calls, ?callable $initiate = null, ?callable $complete = null, ?callable $finalize = null): void
{
    app()->instance(EfevooPayGateway::class, traceabilityGateway($calls, $initiate, $complete, $finalize));
}

function traceabilitySensitiveSession(User $user, int $sessionId, array $cardData): array
{
    return [
        '3ds_card_data_'.$sessionId => array_merge($cardData, [
            'stored_at' => now()->timestamp,
            'expires_at' => now()->addMinutes(5)->timestamp,
            'customer_id' => $user->customer->id,
            'efevoo_3ds_session_id' => $sessionId,
        ]),
    ];
}

function traceabilityAttempt(User $user, array $overrides = []): PaymentAuthenticationAttempt
{
    return PaymentAuthenticationAttempt::create(array_merge([
        'attempt_uuid' => (string) Str::uuid(),
        'support_reference' => 'AUTH-'.Str::upper(Str::random(8)),
        'customer_id' => $user->customer->id,
        'operation_type' => PaymentAuthenticationAttempt::OPERATION_CARD_VERIFICATION_3DS,
        'provider' => PaymentAuthenticationAttempt::PROVIDER_EFEVOOPAY,
        'status' => PaymentAuthenticationAttemptStatus::Created->value,
        'merchant_reference' => 'EFV3DS-'.Str::upper(Str::random(8)),
        'started_at' => now(),
        'expires_at' => now()->addMinutes(5),
    ], $overrides));
}

it('creates an append only timeline for a successful 3ds start', function () {
    $calls = [];
    bindTraceabilityGateway($calls);
    $user = authAttemptTraceabilityUser();

    $this->actingAs($user)->post(route('payment-methods.store'), traceabilityPayload())->assertRedirect();

    $attempt = PaymentAuthenticationAttempt::first();
    $events = $attempt->events()->pluck('event_type')->all();

    expect($events)->toContain(
        PaymentAuthenticationAttemptEventType::AttemptCreated->value,
        PaymentAuthenticationAttemptEventType::ProviderLinkRequestStarted->value,
        PaymentAuthenticationAttemptEventType::ProviderLinkRequestSucceeded->value,
        PaymentAuthenticationAttemptEventType::ThreeDsSessionCreated->value,
        PaymentAuthenticationAttemptEventType::ChallengeReady->value
    )
        ->and($attempt->fresh()->provider_link_call_count)->toBe(1)
        ->and($attempt->fresh()->external_call_count)->toBe(1)
        ->and($calls['initiate3DS'])->toBe(1);
});

it('records same uuid reuse and concurrent tab blocks without additional provider calls', function () {
    $calls = [];
    bindTraceabilityGateway($calls);
    $user = authAttemptTraceabilityUser();
    $uuid = (string) Str::uuid();

    $this->actingAs($user)->post(route('payment-methods.store'), traceabilityPayload(['attempt_uuid' => $uuid]))->assertRedirect();
    $this->actingAs($user)->post(route('payment-methods.store'), traceabilityPayload(['attempt_uuid' => $uuid]))->assertRedirect();
    $this->actingAs($user)->postJson(route('payment-methods.store'), traceabilityPayload())->assertConflict();

    $events = PaymentAuthenticationAttemptEvent::pluck('event_type')->all();

    expect($calls['initiate3DS'])->toBe(1)
        ->and($events)->toContain(PaymentAuthenticationAttemptEventType::AttemptReused->value)
        ->and($events)->toContain(PaymentAuthenticationAttemptEventType::ConcurrentAttemptBlocked->value)
        ->and(PaymentAuthenticationAttempt::count())->toBe(1);
});

it('records timeout as provider confirmation pending and never issuer declined', function () {
    $calls = [];
    bindTraceabilityGateway($calls, fn () => throw new RuntimeException('timeout after send'));
    $user = authAttemptTraceabilityUser();

    $this->actingAs($user)->post(route('payment-methods.store'), traceabilityPayload())->assertSessionHasErrors('error');

    $attempt = PaymentAuthenticationAttempt::first();
    $timeout = $attempt->events()->where('event_type', PaymentAuthenticationAttemptEventType::ProviderLinkRequestTimeout->value)->first();

    expect($attempt->status)->toBe(PaymentAuthenticationAttemptStatus::ProviderConfirmationPending->value)
        ->and($timeout->result_category)->toBe(EfevooPay3dsResultClassifier::CATEGORY_PROVIDER_TIMEOUT)
        ->and($timeout->failure_origin)->toBe(EfevooPay3dsResultClassifier::ORIGIN_NETWORK)
        ->and($timeout->result_category)->not->toBe(EfevooPay3dsResultClassifier::CATEGORY_ISSUER_DECLINED)
        ->and($attempt->provider_link_call_count)->toBe(1);
});

it('classifies provider rejected and unknown statuses without inventing issuer evidence', function () {
    $declined = EfevooPay3dsResultClassifier::providerStatus('rejected', 'R1', 'Rejected by ACS');
    $unknown = EfevooPay3dsResultClassifier::providerStatus('strange_state', 'X9', 'Something odd');

    expect($declined['internal_status'])->toBe(PaymentAuthenticationAttemptStatus::Declined)
        ->and($declined['failure_origin'])->toBe(EfevooPay3dsResultClassifier::ORIGIN_UNKNOWN)
        ->and($unknown['internal_status'])->toBe(PaymentAuthenticationAttemptStatus::Unknown)
        ->and($unknown['provider_status'])->toBe('strange_state')
        ->and($unknown['failure_origin'])->toBe(EfevooPay3dsResultClassifier::ORIGIN_UNKNOWN)
        ->and($unknown['requires_provider_confirmation'])->toBeTrue();
});

it('classifies expiration and explicit cancellation without calling it user cancellation by default', function () {
    $expired = EfevooPay3dsResultClassifier::localExpiration();
    $cancelled = EfevooPay3dsResultClassifier::providerStatus('cancelled');

    expect($expired['result_category'])->toBe(EfevooPay3dsResultClassifier::CATEGORY_CHALLENGE_EXPIRED)
        ->and($expired['failure_origin'])->toBe(EfevooPay3dsResultClassifier::ORIGIN_UNKNOWN)
        ->and($expired['metadata']['detected_by'])->toBe('famedic')
        ->and($expired['result_category'])->not->toBe(EfevooPay3dsResultClassifier::CATEGORY_CANCELLED_BY_USER)
        ->and($cancelled['result_category'])->toBe(EfevooPay3dsResultClassifier::CATEGORY_CANCELLED)
        ->and($cancelled['failure_origin'])->toBe(EfevooPay3dsResultClassifier::ORIGIN_UNKNOWN)
        ->and($cancelled['failure_certainty'])->toBe(EfevooPay3dsResultClassifier::CERTAINTY_UNKNOWN)
        ->and($cancelled['result_category'])->not->toBe(EfevooPay3dsResultClassifier::CATEGORY_CANCELLED_BY_PROVIDER);
});

it('expires stale active attempts before blocking a new uuid', function () {
    $calls = [];
    bindTraceabilityGateway($calls);
    $user = authAttemptTraceabilityUser();
    $old = traceabilityAttempt($user, [
        'status' => PaymentAuthenticationAttemptStatus::Pending->value,
        'expires_at' => now()->subMinute(),
    ]);

    $this->actingAs($user)->post(route('payment-methods.store'), traceabilityPayload())->assertRedirect();

    $expiredEvent = PaymentAuthenticationAttemptEvent::query()
        ->where('event_type', PaymentAuthenticationAttemptEventType::AttemptExpired->value)
        ->first();

    expect($old->fresh()->status)->toBe(PaymentAuthenticationAttemptStatus::Expired->value)
        ->and($old->fresh()->failure_origin)->toBe(EfevooPay3dsResultClassifier::ORIGIN_UNKNOWN)
        ->and(PaymentAuthenticationAttempt::count())->toBe(2)
        ->and($expiredEvent)->not->toBeNull()
        ->and($expiredEvent->allowlistedMetadata()['detected_by'] ?? null)->toBe('famedic')
        ->and($calls['initiate3DS'])->toBe(1);
});

it('does not reuse an expired uuid or deliver its previous challenge', function () {
    $calls = [];
    bindTraceabilityGateway($calls);
    $user = authAttemptTraceabilityUser();
    $session = Efevoo3dsSession::create([
        'customer_id' => $user->customer->id,
        'order_id' => 'ORDER-EXPIRED',
        'card_last_four' => '4242',
        'amount' => 1.5,
        'status' => 'redirect_required',
        'url_3dsecure' => 'https://issuer.example/old',
        'token_3dsecure' => 'old-secret',
    ]);
    $attempt = traceabilityAttempt($user, [
        'status' => PaymentAuthenticationAttemptStatus::ChallengeRequired->value,
        'efevoo_3ds_session_id' => $session->id,
        'expires_at' => now()->subMinute(),
    ]);
    $session->update(['payment_authentication_attempt_id' => $attempt->id]);

    $this->actingAs($user)
        ->postJson(route('payment-methods.store'), traceabilityPayload(['attempt_uuid' => $attempt->attempt_uuid]))
        ->assertConflict()
        ->assertJsonMissing(['session_id' => $session->id]);

    expect($attempt->fresh()->status)->toBe(PaymentAuthenticationAttemptStatus::Expired->value)
        ->and($calls['initiate3DS'] ?? 0)->toBe(0);
});

it('records poll, provider status and tokenization events with call counts', function () {
    $calls = [];
    bindTraceabilityGateway($calls);
    $user = authAttemptTraceabilityUser();
    $attempt = traceabilityAttempt($user, [
        'status' => PaymentAuthenticationAttemptStatus::ChallengeRequired->value,
    ]);
    $session = Efevoo3dsSession::create([
        'customer_id' => $user->customer->id,
        'payment_authentication_attempt_id' => $attempt->id,
        'order_id' => 'ORDER-POLL',
        'card_last_four' => '4242',
        'amount' => 1.5,
        'status' => 'mock_pending',
    ]);
    $attempt->update(['efevoo_3ds_session_id' => $session->id]);

    $this->actingAs($user)->withSession(
        traceabilitySensitiveSession($user, $session->id, ['card_number' => '4242424242424242', 'expiration' => '1229', 'cvv' => '123', 'amount' => 1.5])
    )->getJson(route('payment-methods.3ds-status', $session))->assertOk();

    $events = $attempt->fresh()->events()->pluck('event_type')->all();

    expect($events)->toContain(
        PaymentAuthenticationAttemptEventType::StatusPollStarted->value,
        PaymentAuthenticationAttemptEventType::StatusPollSucceeded->value,
        PaymentAuthenticationAttemptEventType::ProviderStatusReceived->value,
        PaymentAuthenticationAttemptEventType::AuthenticationSucceeded->value,
        PaymentAuthenticationAttemptEventType::TokenizationStarted->value,
        PaymentAuthenticationAttemptEventType::TokenizationSucceeded->value,
        PaymentAuthenticationAttemptEventType::AttemptCompleted->value
    )
        ->and($attempt->fresh()->status_poll_call_count)->toBe(1)
        ->and($attempt->fresh()->tokenization_call_count)->toBe(1)
        ->and($attempt->fresh()->status)->toBe(PaymentAuthenticationAttemptStatus::Completed->value);

    $pollIndex = array_search(PaymentAuthenticationAttemptEventType::StatusPollSucceeded->value, $events, true);
    $authIndex = array_search(PaymentAuthenticationAttemptEventType::AuthenticationSucceeded->value, $events, true);
    $tokenIndex = array_search(PaymentAuthenticationAttemptEventType::TokenizationStarted->value, $events, true);

    expect($pollIndex)->toBeLessThan($authIndex)
        ->and($authIndex)->toBeLessThan($tokenIndex);
});

it('records provider status and authentication declined for a rejected poll', function () {
    $calls = [];
    bindTraceabilityGateway($calls, complete: function (Efevoo3dsSession $session) {
        $session->update(['status' => 'declined', 'error_message' => 'Rejected by ACS']);

        return ['success' => false, 'message' => 'declined'];
    });
    $user = authAttemptTraceabilityUser();
    $attempt = traceabilityAttempt($user, [
        'status' => PaymentAuthenticationAttemptStatus::ChallengeRequired->value,
    ]);
    $session = Efevoo3dsSession::create([
        'customer_id' => $user->customer->id,
        'payment_authentication_attempt_id' => $attempt->id,
        'order_id' => 'ORDER-DECLINED',
        'card_last_four' => '4242',
        'amount' => 1.5,
        'status' => 'mock_pending',
    ]);
    $attempt->update(['efevoo_3ds_session_id' => $session->id]);

    $this->actingAs($user)->withSession(
        traceabilitySensitiveSession($user, $session->id, ['card_number' => '4242424242424242', 'expiration' => '1229', 'cvv' => '123', 'amount' => 1.5])
    )->getJson(route('payment-methods.3ds-status', $session))->assertOk();

    $events = $attempt->fresh()->events()->pluck('event_type')->all();

    expect($events)->toContain(
        PaymentAuthenticationAttemptEventType::StatusPollSucceeded->value,
        PaymentAuthenticationAttemptEventType::ProviderStatusReceived->value,
        PaymentAuthenticationAttemptEventType::AuthenticationDeclined->value
    )
        ->and($attempt->fresh()->status)->toBe(PaymentAuthenticationAttemptStatus::Declined->value)
        ->and($attempt->fresh()->failure_origin)->toBe(EfevooPay3dsResultClassifier::ORIGIN_UNKNOWN);
});

it('records tokenization failure with sanitized reason', function () {
    $calls = [];
    bindTraceabilityGateway($calls, finalize: function (Efevoo3dsSession $session) {
        $session->update(['status' => 'tokenization_failed', 'error_message' => 'token failed card_number 4111111111111111']);

        return ['success' => false, 'message' => 'token failed'];
    });
    $user = authAttemptTraceabilityUser();
    $attempt = traceabilityAttempt($user, ['status' => PaymentAuthenticationAttemptStatus::ChallengeRequired->value]);
    $session = Efevoo3dsSession::create([
        'customer_id' => $user->customer->id,
        'payment_authentication_attempt_id' => $attempt->id,
        'order_id' => 'ORDER-TOKFAIL',
        'card_last_four' => '4242',
        'amount' => 1.5,
        'status' => 'mock_pending',
    ]);
    $attempt->update(['efevoo_3ds_session_id' => $session->id]);

    $this->actingAs($user)->withSession(
        traceabilitySensitiveSession($user, $session->id, ['card_number' => '4242424242424242', 'expiration' => '1229', 'cvv' => '123', 'amount' => 1.5])
    )->getJson(route('payment-methods.3ds-status', $session))->assertOk();

    $event = $attempt->fresh()->events()->where('event_type', PaymentAuthenticationAttemptEventType::TokenizationFailed->value)->first();

    expect($event)->not->toBeNull()
        ->and($event->result_category)->toBe(EfevooPay3dsResultClassifier::CATEGORY_TOKENIZATION_FAILED)
        ->and($event->provider_message)->not->toContain('4111111111111111');
});

it('links manual retries and records both sides of the chain', function () {
    $calls = [];
    bindTraceabilityGateway($calls);
    $user = authAttemptTraceabilityUser();
    $previous = traceabilityAttempt($user, [
        'status' => PaymentAuthenticationAttemptStatus::Declined->value,
        'attempt_number' => 1,
        'finished_at' => now(),
    ]);

    $this->actingAs($user)
        ->post(route('payment-methods.store'), traceabilityPayload(['retry_of_attempt_id' => $previous->id]))
        ->assertRedirect();

    $retry = PaymentAuthenticationAttempt::where('retry_of_attempt_id', $previous->id)->first();

    expect($retry)->not->toBeNull()
        ->and($retry->attempt_number)->toBe(2)
        ->and($previous->events()->where('event_type', PaymentAuthenticationAttemptEventType::ManualRetryCreated->value)->exists())->toBeTrue()
        ->and($retry->events()->where('event_type', PaymentAuthenticationAttemptEventType::AttemptCreated->value)->exists())->toBeTrue();
});

it('dedupe key prevents repeated events and events are append only', function () {
    $user = authAttemptTraceabilityUser();
    $attempt = traceabilityAttempt($user);
    $recorder = app(PaymentAuthenticationAttemptRecorder::class);

    $recorder->record($attempt, PaymentAuthenticationAttemptEventType::ChallengeReady, [
        'source' => 'backend',
        'dedupe_key' => 'challenge_ready:1',
        'metadata' => [
            'session_id' => 10,
            'card_number' => '4111111111111111',
            'cvv' => '123',
            'client_token' => 'secret',
        ],
    ]);
    $recorder->record($attempt, PaymentAuthenticationAttemptEventType::ChallengeReady, [
        'source' => 'backend',
        'dedupe_key' => 'challenge_ready:1',
    ]);

    $event = $attempt->events()->first();
    $json = json_encode($event->metadata);

    expect($attempt->events()->count())->toBe(1)
        ->and($json)->toContain('session_id')
        ->and($json)->not->toContain('4111111111111111')
        ->and($json)->not->toContain('secret');

    $event->provider_message = 'changed';
    $event->save();
})->throws(LogicException::class);

it('blocks application updates and deletes on authentication events', function () {
    $user = authAttemptTraceabilityUser();
    $attempt = traceabilityAttempt($user);
    $recorder = app(PaymentAuthenticationAttemptRecorder::class);
    $event = $recorder->record($attempt, PaymentAuthenticationAttemptEventType::ChallengeReady, [
        'source' => 'backend',
        'dedupe_key' => 'challenge_ready:immutability',
    ]);

    expect(fn () => $event->update(['provider_message' => 'changed']))
        ->toThrow(LogicException::class)
        ->and(fn () => $event->delete())
        ->toThrow(LogicException::class)
        ->and(fn () => $event->forceDelete())
        ->toThrow(LogicException::class)
        ->and(fn () => PaymentAuthenticationAttemptEvent::query()->whereKey($event->id)->update(['provider_message' => 'changed']))
        ->toThrow(LogicException::class)
        ->and(fn () => $attempt->events()->delete())
        ->toThrow(LogicException::class);

    $serialized = json_encode($attempt->fresh('events')->events->first()->toArray());

    expect($serialized)->not->toContain('metadata');
});

it('rejects invalid transitions atomically and records state conflict', function () {
    $user = authAttemptTraceabilityUser();
    $attempt = traceabilityAttempt($user, [
        'status' => PaymentAuthenticationAttemptStatus::Completed->value,
        'finished_at' => now(),
    ]);

    expect(fn () => app(PaymentAuthenticationAttemptRecorder::class)->transition(
        $attempt,
        PaymentAuthenticationAttemptStatus::Pending,
        PaymentAuthenticationAttemptEventType::ProviderStatusReceived,
        ['source' => 'system']
    ))->toThrow(DomainException::class);

    expect($attempt->fresh()->status)->toBe(PaymentAuthenticationAttemptStatus::Completed->value)
        ->and($attempt->events()->where('event_type', PaymentAuthenticationAttemptEventType::StateConflictDetected->value)->exists())->toBeTrue();
});

it('legacy sessions continue and do not create arbitrary frontend approval events', function () {
    $calls = [];
    bindTraceabilityGateway($calls);
    $user = authAttemptTraceabilityUser();
    $session = Efevoo3dsSession::create([
        'customer_id' => $user->customer->id,
        'order_id' => 'ORDER-LEGACY',
        'card_last_four' => '4242',
        'amount' => 1.5,
        'status' => 'mock_pending',
    ]);

    $this->actingAs($user)->withSession(
        traceabilitySensitiveSession($user, $session->id, ['card_number' => '4242424242424242', 'expiration' => '1229', 'cvv' => '123', 'amount' => 1.5])
    )->getJson(route('payment-methods.3ds-status', $session))->assertOk();

    expect(PaymentAuthenticationAttempt::count())->toBe(0)
        ->and(PaymentAuthenticationAttemptEvent::count())->toBe(0)
        ->and($calls['poll3DSAuthentication'] ?? 0)->toBe(1);
});
