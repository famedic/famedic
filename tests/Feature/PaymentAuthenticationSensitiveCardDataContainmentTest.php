<?php

use App\Contracts\EfevooPayGateway;
use App\Enums\PaymentAuthenticationAttemptEventType;
use App\Enums\PaymentAuthenticationAttemptStatus;
use App\Models\Efevoo3dsSession;
use App\Models\PaymentAuthenticationAttempt;
use App\Models\PaymentAuthenticationAttemptEvent;
use App\Models\User;
use App\Support\PaymentAuthenticationSensitiveCardDataStore;
use Illuminate\Support\Facades\Session;
use Illuminate\Support\Str;

beforeEach(function () {
    config([
        'efevoopay.requires_3ds' => true,
        'efevoopay.sensitive_card_data.containment_enabled' => true,
        'efevoopay.sensitive_card_data.ttl_minutes' => 5,
    ]);
});

function containmentUser(): User
{
    return User::factory()
        ->withCompleteProfile()
        ->withRegularCustomer()
        ->create(['documentation_accepted_at' => now()])
        ->fresh(['customer']);
}

function containmentGateway(array &$calls, array $pollPhases = ['authenticated'], ?callable $tokenizeAssert = null): EfevooPayGateway
{
    return new class($calls, $pollPhases, $tokenizeAssert) implements EfevooPayGateway
    {
        private int $pollIndex = 0;

        public function __construct(
            private array &$calls,
            private array $pollPhases,
            private $tokenizeAssert
        ) {}

        public function chargeCard(array $data): array
        {
            return ['success' => true];
        }

        public function tokenizeCard(array $cardData, int $customerId): array
        {
            return ['success' => true, 'token_id' => 99];
        }

        public function initiate3DS(array $cardData, int $customerId): array
        {
            $this->calls['initiate3DS'] = ($this->calls['initiate3DS'] ?? 0) + 1;
            $session = Efevoo3dsSession::create([
                'customer_id' => $customerId,
                'order_id' => 'ORDER-'.Str::upper(Str::random(6)),
                'card_last_four' => '4242',
                'amount' => 1.5,
                'status' => 'redirect_required',
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
            $phase = $this->pollPhases[$this->pollIndex] ?? 'authenticated';
            $this->pollIndex++;

            if ($phase === 'pending') {
                $session->update(['status' => 'pending']);

                return ['phase' => 'pending', 'success' => false, 'error_type' => 'pending'];
            }

            if ($phase === 'declined') {
                $session->update(['status' => 'declined', 'error_message' => 'declined']);

                return ['phase' => 'declined', 'success' => false, 'error_type' => 'bank'];
            }

            $session->update(['status' => 'authenticated']);

            return ['phase' => 'authenticated', 'success' => true];
        }

        public function finalize3DSTokenization(Efevoo3dsSession $session, array $cardData): array
        {
            $this->calls['finalize3DSTokenization'] = ($this->calls['finalize3DSTokenization'] ?? 0) + 1;

            if ($this->tokenizeAssert) {
                ($this->tokenizeAssert)($cardData);
            }

            $session->update(['status' => 'completed', 'completed_at' => now()]);

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
    };
}

function containmentAttempt(User $user, Efevoo3dsSession $session): PaymentAuthenticationAttempt
{
    $attempt = PaymentAuthenticationAttempt::create([
        'attempt_uuid' => (string) Str::uuid(),
        'support_reference' => 'AUTH-'.Str::upper(Str::random(6)),
        'customer_id' => $user->customer->id,
        'operation_type' => PaymentAuthenticationAttempt::OPERATION_CARD_VERIFICATION_3DS,
        'provider' => PaymentAuthenticationAttempt::PROVIDER_EFEVOOPAY,
        'status' => PaymentAuthenticationAttemptStatus::ChallengeRequired->value,
        'merchant_reference' => 'EFV3DS-'.Str::upper(Str::random(6)),
        'efevoo_3ds_session_id' => $session->id,
        'started_at' => now(),
        'expires_at' => now()->addMinutes(5),
    ]);
    $session->update(['payment_authentication_attempt_id' => $attempt->id]);

    return $attempt;
}

function containmentStorePayload(User $user, int $sessionId, array $overrides = []): array
{
    return array_merge([
        'card_number' => '4242424242424242',
        'expiration' => '1229',
        'cvv' => '123',
        'card_holder' => 'TEST',
        'alias' => 'visa-4242',
        'amount' => 1.5,
        'stored_at' => now()->timestamp,
        'expires_at' => now()->addMinutes(5)->timestamp,
        'customer_id' => $user->customer->id,
        'efevoo_3ds_session_id' => $sessionId,
    ], $overrides);
}

it('stores card data with ttl metadata after successful get link', function () {
    $calls = [];
    app()->instance(EfevooPayGateway::class, containmentGateway($calls));
    $user = containmentUser();

    $this->actingAs($user)
        ->post(route('payment-methods.store'), [
            'card_number' => '4242424242424242',
            'exp_month' => '12',
            'exp_year' => '29',
            'cvv' => '123',
            'card_holder' => 'TEST',
            'alias' => 'visa-4242',
            'terms_accepted' => '1',
            'attempt_uuid' => (string) Str::uuid(),
        ])
        ->assertRedirect();

    $session = Efevoo3dsSession::first();
    $payload = Session::get('3ds_card_data_'.$session->id);

    expect($payload)->toHaveKeys(['expires_at', 'customer_id', 'efevoo_3ds_session_id'])
        ->and($payload['expires_at'])->toBeGreaterThan(now()->timestamp);
});

it('does not allow another customer to read stored card data', function () {
    $store = app(PaymentAuthenticationSensitiveCardDataStore::class);
    $owner = containmentUser();
    $other = containmentUser();
    $session = Efevoo3dsSession::create([
        'customer_id' => $owner->customer->id,
        'order_id' => 'ORDER-1',
        'card_last_four' => '4242',
        'amount' => 1.5,
        'status' => 'pending',
    ]);

    $store->store($owner->customer, $session, null, containmentStorePayload($owner, $session->id));

    expect($store->readForCustomer($other->customer, $session->id))->toBeNull();
});

it('keeps card data while pending within ttl', function () {
    $calls = [];
    app()->instance(EfevooPayGateway::class, containmentGateway($calls, ['pending']));
    $user = containmentUser();
    $session = Efevoo3dsSession::create([
        'customer_id' => $user->customer->id,
        'order_id' => 'ORDER-P',
        'card_last_four' => '4242',
        'amount' => 1.5,
        'status' => 'pending',
    ]);
    containmentAttempt($user, $session);

    $this->actingAs($user)->withSession([
        '3ds_card_data_'.$session->id => containmentStorePayload($user, $session->id),
    ])->getJson(route('payment-methods.3ds-status', $session))
        ->assertOk()
        ->assertJsonPath('final', false);

    expect(Session::has('3ds_card_data_'.$session->id))->toBeTrue();
});

it('purges expired card data on read', function () {
    $store = app(PaymentAuthenticationSensitiveCardDataStore::class);
    $user = containmentUser();
    $session = Efevoo3dsSession::create([
        'customer_id' => $user->customer->id,
        'order_id' => 'ORDER-E',
        'card_last_four' => '4242',
        'amount' => 1.5,
        'status' => 'pending',
    ]);
    $attempt = containmentAttempt($user, $session);

    Session::put('3ds_card_data_'.$session->id, containmentStorePayload($user, $session->id, [
        'expires_at' => now()->subMinute()->timestamp,
    ]));

    expect($store->readForCustomer($user->customer, $session->id))->toBeNull()
        ->and(Session::has('3ds_card_data_'.$session->id))->toBeFalse()
        ->and(PaymentAuthenticationAttemptEvent::where('payment_authentication_attempt_id', $attempt->id)
            ->where('event_type', PaymentAuthenticationAttemptEventType::SensitiveCardDataPurged->value)
            ->exists())->toBeFalse();
});

it('purges before tokenization and tokenize payload excludes cvv', function () {
    $calls = [];
    app()->instance(EfevooPayGateway::class, containmentGateway($calls, ['authenticated'], function (array $cardData) {
        expect($cardData)->not->toHaveKey('cvv');
    }));
    $user = containmentUser();
    $session = Efevoo3dsSession::create([
        'customer_id' => $user->customer->id,
        'order_id' => 'ORDER-T',
        'card_last_four' => '4242',
        'amount' => 1.5,
        'status' => 'pending',
    ]);
    containmentAttempt($user, $session);

    $this->actingAs($user)->withSession([
        '3ds_card_data_'.$session->id => containmentStorePayload($user, $session->id),
    ])->getJson(route('payment-methods.3ds-status', $session))->assertOk();

    expect(Session::has('3ds_card_data_'.$session->id))->toBeFalse()
        ->and($calls['finalize3DSTokenization'] ?? 0)->toBe(1);
});

it('purges on declined and completed terminal revisits', function () {
    $calls = [];
    app()->instance(EfevooPayGateway::class, containmentGateway($calls, ['declined']));
    $user = containmentUser();
    $session = Efevoo3dsSession::create([
        'customer_id' => $user->customer->id,
        'order_id' => 'ORDER-D',
        'card_last_four' => '4242',
        'amount' => 1.5,
        'status' => 'pending',
    ]);
    containmentAttempt($user, $session);

    $this->actingAs($user)->withSession([
        '3ds_card_data_'.$session->id => containmentStorePayload($user, $session->id),
    ])->getJson(route('payment-methods.3ds-status', $session))
        ->assertOk()
        ->assertJsonPath('final', true);

    expect(Session::has('3ds_card_data_'.$session->id))->toBeFalse();
});

it('returns controlled response when card data is missing', function () {
    $calls = [];
    app()->instance(EfevooPayGateway::class, containmentGateway($calls));
    $user = containmentUser();
    $session = Efevoo3dsSession::create([
        'customer_id' => $user->customer->id,
        'order_id' => 'ORDER-M',
        'card_last_four' => '4242',
        'amount' => 1.5,
        'status' => 'pending',
    ]);
    containmentAttempt($user, $session);

    $this->actingAs($user)
        ->getJson(route('payment-methods.3ds-status', $session))
        ->assertOk()
        ->assertJsonPath('final', true)
        ->assertJsonPath('message', config('efevoopay.sensitive_card_data.messages.missing_or_expired'));

    expect($calls['poll3DSAuthentication'] ?? 0)->toBe(0);
});

it('does not write legacy unsuffixed session key on store flow', function () {
    $calls = [];
    app()->instance(EfevooPayGateway::class, containmentGateway($calls));
    $user = containmentUser();

    $this->actingAs($user)
        ->post(route('payment-methods.store'), [
            'card_number' => '4242424242424242',
            'exp_month' => '12',
            'exp_year' => '29',
            'cvv' => '123',
            'card_holder' => 'TEST',
            'alias' => 'visa-4242',
            'terms_accepted' => '1',
            'attempt_uuid' => (string) Str::uuid(),
        ]);

    expect(Session::has(PaymentAuthenticationSensitiveCardDataStore::LEGACY_SESSION_KEY))->toBeFalse();
});

it('records sanitized sensitive card data events without pan or cvv metadata', function () {
    $calls = [];
    app()->instance(EfevooPayGateway::class, containmentGateway($calls));
    $user = containmentUser();

    $this->actingAs($user)
        ->post(route('payment-methods.store'), [
            'card_number' => '4242424242424242',
            'exp_month' => '12',
            'exp_year' => '29',
            'cvv' => '123',
            'card_holder' => 'TEST',
            'alias' => 'visa-4242',
            'terms_accepted' => '1',
            'attempt_uuid' => (string) Str::uuid(),
        ]);

    $event = PaymentAuthenticationAttemptEvent::where('event_type', PaymentAuthenticationAttemptEventType::SensitiveCardDataStored->value)->first();

    expect($event)->not->toBeNull();
    $json = json_encode($event->metadata);
    expect($json)->not->toContain('4242')
        ->and($json)->not->toContain('cvv')
        ->and($json)->not->toContain('card_number');
});

it('purge command dry-run reports candidates without modifying sessions', function () {
    config(['session.driver' => 'database']);

    $user = containmentUser();
    $session = Efevoo3dsSession::create([
        'customer_id' => $user->customer->id,
        'order_id' => 'ORDER-CMD',
        'card_last_four' => '4242',
        'amount' => 1.5,
        'status' => 'pending',
    ]);

    $payload = base64_encode(serialize([
        '3ds_card_data_'.$session->id => containmentStorePayload($user, $session->id, [
            'expires_at' => now()->subHour()->timestamp,
        ]),
    ]));

    \Illuminate\Support\Facades\DB::table('sessions')->insert([
        'id' => (string) Str::uuid(),
        'user_id' => $user->id,
        'ip_address' => '127.0.0.1',
        'user_agent' => 'pest',
        'payload' => $payload,
        'last_activity' => now()->subHours(2)->timestamp,
    ]);

    $this->artisan('efevoopay:purge-expired-card-session-data')
        ->expectsOutputToContain('dry-run')
        ->assertExitCode(0);

    $stored = \Illuminate\Support\Facades\DB::table('sessions')->where('payload', $payload)->exists();
    expect($stored)->toBeTrue();
});

it('purge command apply removes expired keys only', function () {
    config(['session.driver' => 'database']);

    $user = containmentUser();
    $session = Efevoo3dsSession::create([
        'customer_id' => $user->customer->id,
        'order_id' => 'ORDER-APPLY',
        'card_last_four' => '4242',
        'amount' => 1.5,
        'status' => 'pending',
    ]);
    $key = '3ds_card_data_'.$session->id;
    $sessionId = (string) Str::uuid();
    $payload = base64_encode(serialize([
        $key => containmentStorePayload($user, $session->id, [
            'expires_at' => now()->subHour()->timestamp,
        ]),
        'other' => 'keep',
    ]));

    \Illuminate\Support\Facades\DB::table('sessions')->insert([
        'id' => $sessionId,
        'user_id' => $user->id,
        'ip_address' => '127.0.0.1',
        'user_agent' => 'pest',
        'payload' => $payload,
        'last_activity' => now()->subHours(2)->timestamp,
    ]);

    $this->artisan('efevoopay:purge-expired-card-session-data --apply --session-id='.$sessionId)
        ->assertExitCode(0);

    $decoded = unserialize(base64_decode((string) \Illuminate\Support\Facades\DB::table('sessions')->where('id', $sessionId)->value('payload')), ['allowed_classes' => false]);
    expect($decoded)->not->toHaveKey($key)
        ->and($decoded['other'])->toBe('keep');
});
