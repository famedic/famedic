<?php

use App\Contracts\EfevooPayGateway;
use App\Enums\PaymentAuthenticationAttemptEventType;
use App\Enums\PaymentAuthenticationAttemptStatus;
use App\Enums\PaymentAuthenticationRecoveryContextStatus;
use App\Enums\PaymentAuthenticationRecoveryContextType;
use App\Models\Administrator;
use App\Models\Efevoo3dsSession;
use App\Models\PaymentAuthenticationAttempt;
use App\Models\PaymentAuthenticationAttemptEvent;
use App\Models\PaymentAuthenticationRecoveryContext;
use App\Models\Transaction;
use App\Models\User;
use App\Services\EfevooPayService;
use App\Support\LaravelDatabaseSessionPayloadCodec;
use App\Support\PaymentAuthenticationAttemptEventAdminResource;
use App\Support\PaymentAuthenticationRecoveryPayPalNavigator;
use App\Support\PaymentAuthenticationRecoveryPolicy;
use App\Support\PaymentAuthenticationSensitiveCardDataMetrics;
use App\Support\PaymentAuthenticationSensitiveCardDataStore;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Session;
use Illuminate\Support\Str;
use Tests\Feature\Support\RealDatabaseSessionFactory;

beforeEach(function () {
    config([
        'session.driver' => 'database',
        'session.encrypt' => false,
        'efevoopay.requires_3ds' => true,
        'efevoopay.sensitive_card_data.containment_enabled' => true,
        'efevoopay.sensitive_card_data.ttl_minutes' => 5,
    ]);
});

function hardeningUser(): User
{
    return User::factory()
        ->withCompleteProfile()
        ->withRegularCustomer()
        ->create(['documentation_accepted_at' => now()])
        ->fresh(['customer']);
}

function hardeningCardPayload(User $user, int $sessionId, array $overrides = []): array
{
    return array_merge([
        'card_number' => '4111111111111111',
        'expiration' => '1229',
        'cvv' => '999',
        'card_holder' => 'TEST USER',
        'alias' => 'visa-test',
        'amount' => 1.5,
        'stored_at' => now()->timestamp,
        'expires_at' => now()->addMinutes(5)->timestamp,
        'customer_id' => $user->customer->id,
        'efevoo_3ds_session_id' => $sessionId,
    ], $overrides);
}

function hardeningDeclinedRecoverySetup(User $user): array
{
    $efevooSession = Efevoo3dsSession::create([
        'customer_id' => $user->customer->id,
        'order_id' => 'ORDER-'.Str::upper(Str::random(6)),
        'card_last_four' => '1111',
        'amount' => 1.5,
        'status' => 'declined',
    ]);

    $context = PaymentAuthenticationRecoveryContext::create([
        'context_uuid' => (string) Str::uuid(),
        'customer_id' => $user->customer->id,
        'context_type' => PaymentAuthenticationRecoveryContextType::PaymentMethodSettings,
        'status' => PaymentAuthenticationRecoveryContextStatus::RecoveryAvailable,
        'return_route_name' => PaymentAuthenticationRecoveryContextType::PaymentMethodSettings->returnRouteName(),
        'context_data' => [],
        'started_at' => now()->subHour(),
        'expires_at' => now()->addMinutes(30),
    ]);

    $attempt = PaymentAuthenticationAttempt::create([
        'attempt_uuid' => (string) Str::uuid(),
        'support_reference' => 'AUTH-'.Str::upper(Str::random(6)),
        'customer_id' => $user->customer->id,
        'recovery_context_id' => $context->id,
        'operation_type' => PaymentAuthenticationAttempt::OPERATION_CARD_VERIFICATION_3DS,
        'provider' => PaymentAuthenticationAttempt::PROVIDER_EFEVOOPAY,
        'status' => PaymentAuthenticationAttemptStatus::Declined->value,
        'merchant_reference' => 'EFV3DS-'.Str::upper(Str::random(6)),
        'efevoo_3ds_session_id' => $efevooSession->id,
        'attempt_number' => 1,
        'started_at' => now()->subHour(),
        'finished_at' => now()->subHour(),
        'expires_at' => now()->addMinutes(5),
    ]);

    $efevooSession->update(['payment_authentication_attempt_id' => $attempt->id]);
    $context->update(['root_authentication_attempt_id' => $attempt->id]);

    return compact('efevooSession', 'context', 'attempt');
}

it('stores real laravel database session payload as base64 serialized data', function () {
    $user = hardeningUser();
    $sessionId = RealDatabaseSessionFactory::create([
        '3ds_card_data_99' => hardeningCardPayload($user, 99, [
            'expires_at' => now()->subHour()->timestamp,
        ]),
    ], $user);

    $structure = RealDatabaseSessionFactory::describeStoredPayload($sessionId);

    expect($structure['encoding'])->toBe('base64')
        ->and($structure['encrypted'])->toBeFalse()
        ->and($structure['serialized'])->toBeTrue();

    $loaded = RealDatabaseSessionFactory::load($sessionId);

    expect($loaded->get('normal_key'))->toBe('keep-me')
        ->and($loaded->get('status'))->toBe('flash-ok')
        ->and($loaded->get(Auth::getName()))->toBe($user->getAuthIdentifier())
        ->and($loaded->has('3ds_card_data_99'))->toBeTrue();
});

it('purge command detects and purges real laravel session preserving other keys', function () {
    $user = hardeningUser();
    $sensitiveKey = '3ds_card_data_42';
    $laravelSessionId = RealDatabaseSessionFactory::create([
        $sensitiveKey => hardeningCardPayload($user, 42, [
            'expires_at' => now()->subHour()->timestamp,
        ]),
        '3ds_card_data' => hardeningCardPayload($user, 0, [
            'expires_at' => now()->subHour()->timestamp,
        ]),
    ], $user);

    DB::table('sessions')->where('id', $laravelSessionId)->update([
        'last_activity' => now()->subHours(2)->timestamp,
    ]);

    $this->artisan('efevoopay:purge-expired-card-session-data')
        ->expectsOutputToContain('Expired keys found: 2')
        ->assertExitCode(0);

    $this->artisan('efevoopay:purge-expired-card-session-data --apply --session-id='.$laravelSessionId)
        ->assertExitCode(0);

    $loaded = RealDatabaseSessionFactory::load($laravelSessionId);

    expect($loaded->has($sensitiveKey))->toBeFalse()
        ->and($loaded->has(PaymentAuthenticationSensitiveCardDataStore::LEGACY_SESSION_KEY))->toBeFalse()
        ->and($loaded->get('normal_key'))->toBe('keep-me')
        ->and($loaded->get('status'))->toBe('flash-ok')
        ->and($loaded->get(Auth::getName()))->toBe($user->getAuthIdentifier());
});

it('supports encrypted session payloads when session encrypt is enabled', function () {
    config(['session.encrypt' => true]);
    $user = hardeningUser();

    $laravelSessionId = RealDatabaseSessionFactory::create([
        '3ds_card_data_7' => hardeningCardPayload($user, 7, [
            'expires_at' => now()->subHour()->timestamp,
        ]),
    ], $user);

    DB::table('sessions')->where('id', $laravelSessionId)->update([
        'last_activity' => now()->subHours(2)->timestamp,
    ]);

    expect(RealDatabaseSessionFactory::describeStoredPayload($laravelSessionId)['encrypted'])->toBeTrue();

    $this->artisan('efevoopay:purge-expired-card-session-data --apply --session-id='.$laravelSessionId)
        ->assertExitCode(0);

    expect(RealDatabaseSessionFactory::load($laravelSessionId)->has('3ds_card_data_7'))->toBeFalse();
});

it('rejects unsupported session drivers', function () {
    config(['session.driver' => 'array']);

    $this->artisan('efevoopay:purge-expired-card-session-data')
        ->expectsOutputToContain('not supported')
        ->assertExitCode(1);
});

it('skips active pending challenge within ttl during purge', function () {
    $user = hardeningUser();
    $laravelSessionId = RealDatabaseSessionFactory::create([
        '3ds_card_data_55' => hardeningCardPayload($user, 55, [
            'expires_at' => now()->addMinutes(3)->timestamp,
        ]),
    ], $user);

    DB::table('sessions')->where('id', $laravelSessionId)->update([
        'last_activity' => now()->timestamp,
    ]);

    $this->artisan('efevoopay:purge-expired-card-session-data --apply --session-id='.$laravelSessionId)
        ->expectsOutputToContain('Active within TTL')
        ->assertExitCode(0);

    expect(RealDatabaseSessionFactory::load($laravelSessionId)->has('3ds_card_data_55'))->toBeTrue();
});

it('does not overwrite session payload changed concurrently during purge apply', function () {
    $user = hardeningUser();
    $laravelSessionId = RealDatabaseSessionFactory::create([
        '3ds_card_data_88' => hardeningCardPayload($user, 88, [
            'expires_at' => now()->subHour()->timestamp,
        ]),
    ], $user);

    DB::table('sessions')->where('id', $laravelSessionId)->update([
        'last_activity' => now()->subHours(2)->timestamp,
    ]);

    $row = RealDatabaseSessionFactory::rawRow($laravelSessionId);
    $codec = app(LaravelDatabaseSessionPayloadCodec::class);
    $payload = $codec->decode((string) $row->payload);
    $payload['concurrent_marker'] = 'new-user-data';
    unset($payload['3ds_card_data_88']);

    DB::table('sessions')->where('id', $laravelSessionId)->update([
        'payload' => $codec->encode($payload),
        'last_activity' => now()->timestamp,
    ]);

    $this->artisan('efevoopay:purge-expired-card-session-data --apply --session-id='.$laravelSessionId)
        ->expectsOutputToContain('Stale concurrent updates')
        ->assertExitCode(0);

    expect(RealDatabaseSessionFactory::load($laravelSessionId)->get('concurrent_marker'))->toBe('new-user-data');
});

it('abandoned 3ds session is purged by command and increments abandonment metric', function () {
    Cache::flush();
    $user = hardeningUser();
    $laravelSessionId = RealDatabaseSessionFactory::create([
        '3ds_card_data_101' => hardeningCardPayload($user, 101, [
            'expires_at' => now()->subMinutes(10)->timestamp,
        ]),
    ], $user);

    DB::table('sessions')->where('id', $laravelSessionId)->update([
        'last_activity' => now()->subMinutes(10)->timestamp,
    ]);

    $activeSessionId = RealDatabaseSessionFactory::create([
        '3ds_card_data_202' => hardeningCardPayload($user, 202, [
            'expires_at' => now()->addMinutes(4)->timestamp,
        ]),
    ], $user);

    DB::table('sessions')->where('id', $activeSessionId)->update([
        'last_activity' => now()->timestamp,
    ]);

    $this->artisan('efevoopay:purge-expired-card-session-data --apply')->assertExitCode(0);

    expect(RealDatabaseSessionFactory::load($laravelSessionId)->has('3ds_card_data_101'))->toBeFalse()
        ->and(RealDatabaseSessionFactory::load($activeSessionId)->has('3ds_card_data_202'))->toBeTrue()
        ->and(app(PaymentAuthenticationSensitiveCardDataMetrics::class)->snapshot()['abandoned_candidate'])->toBeGreaterThan(0);
});

it('blocks new 3ds verification when containment feature flag is disabled', function () {
    config(['efevoopay.sensitive_card_data.containment_enabled' => false, 'app.env' => 'production']);
    $calls = [];
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
            $this->calls['initiate3DS'] = 1;

            return ['success' => true, 'session_id' => 1];
        }

        public function complete3DS(Efevoo3dsSession $session, array $cardData): array
        {
            return ['success' => true];
        }

        public function poll3DSAuthentication(Efevoo3dsSession $session, array $cardData): array
        {
            return ['phase' => 'pending'];
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

    $user = hardeningUser();

    $this->actingAs($user)
        ->post(route('payment-methods.store'), [
            'card_number' => '4111111111111111',
            'exp_month' => '12',
            'exp_year' => '29',
            'cvv' => '999',
            'card_holder' => 'TEST',
            'alias' => 'visa-test',
            'terms_accepted' => '1',
            'attempt_uuid' => (string) Str::uuid(),
        ])
        ->assertSessionHasErrors('error');

    expect($calls['initiate3DS'] ?? 0)->toBe(0);
});

it('paypal recovery start purges parent sensitive card data and records changed_to_paypal event', function () {
    $user = hardeningUser();
    ['efevooSession' => $efevooSession, 'context' => $context, 'attempt' => $attempt] = hardeningDeclinedRecoverySetup($user);

    $context->update([
        'context_type' => PaymentAuthenticationRecoveryContextType::MedicalAttentionCheckout,
        'return_route_name' => PaymentAuthenticationRecoveryContextType::MedicalAttentionCheckout->returnRouteName(),
        'context_data' => ['step' => 'payment'],
    ]);

    $policy = Mockery::mock(\App\Support\PaymentAuthenticationRecoveryPayPalPolicy::class);
    $policy->shouldReceive('evaluate')->once()->andReturn([
        'allowed' => true,
        'block_reason' => null,
        'checkout_ready' => true,
    ]);
    app()->instance(\App\Support\PaymentAuthenticationRecoveryPayPalPolicy::class, $policy);

    Session::put('3ds_card_data_'.$efevooSession->id, hardeningCardPayload($user, $efevooSession->id));

    app(PaymentAuthenticationRecoveryPayPalNavigator::class)->start($user->customer, $efevooSession, $context->fresh());

    expect(Session::has('3ds_card_data_'.$efevooSession->id))->toBeFalse()
        ->and(PaymentAuthenticationAttemptEvent::where('payment_authentication_attempt_id', $attempt->id)
            ->where('event_type', PaymentAuthenticationAttemptEventType::SensitiveCardDataPurged->value)
            ->where('metadata->reason', 'changed_to_paypal')
            ->exists())->toBeTrue()
        ->and(PaymentAuthenticationAttemptEvent::where('payment_authentication_attempt_id', $attempt->id)
            ->where('event_type', PaymentAuthenticationAttemptEventType::ChangedToPaypal->value)
            ->exists())->toBeTrue();
});

it('retry recovery start purges parent sensitive card data', function () {
    $user = hardeningUser();
    ['efevooSession' => $efevooSession, 'context' => $context] = hardeningDeclinedRecoverySetup($user);

    Session::put('3ds_card_data_'.$efevooSession->id, hardeningCardPayload($user, $efevooSession->id));

    app(\App\Support\PaymentAuthenticationRecoveryNavigator::class)->start(
        $user->customer,
        $efevooSession,
        $context,
        PaymentAuthenticationRecoveryPolicy::ACTION_RETRY
    );

    expect(Session::has('3ds_card_data_'.$efevooSession->id))->toBeFalse()
        ->and(PaymentAuthenticationAttemptEvent::where('event_type', PaymentAuthenticationAttemptEventType::SensitiveCardDataPurged->value)
            ->where('metadata->reason', 'retry')
            ->exists())->toBeTrue();
});

it('different card recovery start purges parent sensitive card data', function () {
    $user = hardeningUser();
    ['efevooSession' => $efevooSession, 'context' => $context] = hardeningDeclinedRecoverySetup($user);

    Session::put('3ds_card_data_'.$efevooSession->id, hardeningCardPayload($user, $efevooSession->id));

    app(\App\Support\PaymentAuthenticationRecoveryNavigator::class)->start(
        $user->customer,
        $efevooSession,
        $context,
        PaymentAuthenticationRecoveryPolicy::ACTION_DIFFERENT_CARD
    );

    expect(Session::has('3ds_card_data_'.$efevooSession->id))->toBeFalse()
        ->and(PaymentAuthenticationAttemptEvent::where('event_type', PaymentAuthenticationAttemptEventType::SensitiveCardDataPurged->value)
            ->where('metadata->reason', 'different_card')
            ->exists())->toBeTrue();
});

it('provider confirmation pending poll purges sensitive card data', function () {
    $calls = [];
    app()->instance(EfevooPayGateway::class, new class($calls) implements EfevooPayGateway
    {
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
            return ['success' => true, 'session_id' => 1];
        }

        public function complete3DS(Efevoo3dsSession $session, array $cardData): array
        {
            return ['success' => true];
        }

        public function poll3DSAuthentication(Efevoo3dsSession $session, array $cardData): array
        {
            throw new RuntimeException('network timeout');
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

    $user = hardeningUser();
    $session = Efevoo3dsSession::create([
        'customer_id' => $user->customer->id,
        'order_id' => 'ORDER-PENDING',
        'card_last_four' => '1111',
        'amount' => 1.5,
        'status' => 'pending',
    ]);
    $attempt = PaymentAuthenticationAttempt::create([
        'attempt_uuid' => (string) Str::uuid(),
        'support_reference' => 'AUTH-PEND',
        'customer_id' => $user->customer->id,
        'operation_type' => PaymentAuthenticationAttempt::OPERATION_CARD_VERIFICATION_3DS,
        'provider' => PaymentAuthenticationAttempt::PROVIDER_EFEVOOPAY,
        'status' => PaymentAuthenticationAttemptStatus::Pending->value,
        'merchant_reference' => 'EFV3DS-PEND',
        'efevoo_3ds_session_id' => $session->id,
        'attempt_number' => 1,
        'started_at' => now(),
        'expires_at' => now()->addMinutes(5),
    ]);
    $session->update(['payment_authentication_attempt_id' => $attempt->id]);
    $session->update(['payment_authentication_attempt_id' => $attempt->id]);

    $this->actingAs($user)->withSession([
        '3ds_card_data_'.$session->id => hardeningCardPayload($user, $session->id),
    ])->getJson(route('payment-methods.3ds-status', $session))
        ->assertOk()
        ->assertJsonPath('status', 'provider_confirmation_pending');

    expect(Session::has('3ds_card_data_'.$session->id))->toBeFalse();
});

it('getstatus network error phase purges sensitive card data', function () {
    $calls = [];
    app()->instance(EfevooPayGateway::class, new class($calls) implements EfevooPayGateway
    {
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
            return ['success' => true, 'session_id' => 1];
        }

        public function complete3DS(Efevoo3dsSession $session, array $cardData): array
        {
            return ['success' => true];
        }

        public function poll3DSAuthentication(Efevoo3dsSession $session, array $cardData): array
        {
            return [
                'phase' => 'error',
                'success' => false,
                'error_type' => EfevooPayService::ERROR_NETWORK,
            ];
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

    $user = hardeningUser();
    $session = Efevoo3dsSession::create([
        'customer_id' => $user->customer->id,
        'order_id' => 'ORDER-NET',
        'card_last_four' => '1111',
        'amount' => 1.5,
        'status' => 'pending',
    ]);
    PaymentAuthenticationAttempt::create([
        'attempt_uuid' => (string) Str::uuid(),
        'support_reference' => 'AUTH-NET',
        'customer_id' => $user->customer->id,
        'operation_type' => PaymentAuthenticationAttempt::OPERATION_CARD_VERIFICATION_3DS,
        'provider' => PaymentAuthenticationAttempt::PROVIDER_EFEVOOPAY,
        'status' => PaymentAuthenticationAttemptStatus::Pending->value,
        'merchant_reference' => 'EFV3DS-NET',
        'efevoo_3ds_session_id' => $session->id,
        'attempt_number' => 1,
        'started_at' => now(),
        'expires_at' => now()->addMinutes(5),
    ]);

    $this->actingAs($user)->withSession([
        '3ds_card_data_'.$session->id => hardeningCardPayload($user, $session->id),
    ])->getJson(route('payment-methods.3ds-status', $session))->assertOk();

    expect(Session::has('3ds_card_data_'.$session->id))->toBeFalse();
});

it('context expired purges sensitive card data for all attempts in context', function () {
    $user = hardeningUser();
    ['efevooSession' => $efevooSession, 'context' => $context] = hardeningDeclinedRecoverySetup($user);

    Session::put('3ds_card_data_'.$efevooSession->id, hardeningCardPayload($user, $efevooSession->id));
    $context->update(['expires_at' => now()->subMinute()]);

    app(\App\Support\PaymentAuthenticationRecoveryContextManager::class)->expireIfNeeded($context->fresh());

    expect(Session::has('3ds_card_data_'.$efevooSession->id))->toBeFalse();
});

it('context recovered purges sensitive card data', function () {
    $user = hardeningUser();
    ['efevooSession' => $efevooSession, 'context' => $context, 'attempt' => $attempt] = hardeningDeclinedRecoverySetup($user);

    Session::put('3ds_card_data_'.$efevooSession->id, hardeningCardPayload($user, $efevooSession->id));

    $transaction = Transaction::create([
        'transaction_amount_cents' => 50000,
        'payment_method' => 'paypal',
        'payment_provider' => 'paypal',
        'gateway' => 'paypal',
        'reference_id' => 'PP-REC-1',
        'provider_order_id' => 'PP-REC-1',
        'payment_status' => 'captured',
        'details' => ['customer_id' => $user->customer->id],
    ]);

    app(\App\Support\PaymentAuthenticationRecoveryPaymentCoordinator::class)->markRecovered(
        $context,
        $transaction,
        $attempt
    );

    expect(Session::has('3ds_card_data_'.$efevooSession->id))->toBeFalse();
});

it('recovery limit reached purges when no active attempt exists', function () {
    $user = hardeningUser();
    ['efevooSession' => $efevooSession, 'context' => $context, 'attempt' => $attempt] = hardeningDeclinedRecoverySetup($user);

    config(['efevoopay.recovery.max_attempts_per_context' => 1]);

    PaymentAuthenticationAttempt::create([
        'attempt_uuid' => (string) Str::uuid(),
        'support_reference' => 'AUTH-2',
        'customer_id' => $user->customer->id,
        'recovery_context_id' => $context->id,
        'retry_of_attempt_id' => $attempt->id,
        'operation_type' => PaymentAuthenticationAttempt::OPERATION_CARD_VERIFICATION_3DS,
        'provider' => PaymentAuthenticationAttempt::PROVIDER_EFEVOOPAY,
        'status' => PaymentAuthenticationAttemptStatus::Declined->value,
        'merchant_reference' => 'EFV3DS-2',
        'efevoo_3ds_session_id' => $efevooSession->id,
        'attempt_number' => 2,
        'started_at' => now(),
        'finished_at' => now(),
        'expires_at' => now()->addMinutes(5),
    ]);

    Session::put('3ds_card_data_'.$efevooSession->id, hardeningCardPayload($user, $efevooSession->id));

    expect(fn () => app(\App\Support\PaymentAuthenticationRecoveryNavigator::class)->start(
        $user->customer,
        $efevooSession,
        $context->fresh(),
        PaymentAuthenticationRecoveryPolicy::ACTION_RETRY
    ))->toThrow(\App\Support\PaymentAuthenticationRecoveryStartException::class);

    expect(Session::has('3ds_card_data_'.$efevooSession->id))->toBeFalse();
});

it('repeated purge is idempotent', function () {
    $store = app(PaymentAuthenticationSensitiveCardDataStore::class);
    $user = hardeningUser();
    $session = Efevoo3dsSession::create([
        'customer_id' => $user->customer->id,
        'order_id' => 'ORDER-IDEM',
        'card_last_four' => '1111',
        'amount' => 1.5,
        'status' => 'pending',
    ]);
    $attempt = PaymentAuthenticationAttempt::create([
        'attempt_uuid' => (string) Str::uuid(),
        'support_reference' => 'AUTH-IDEM',
        'customer_id' => $user->customer->id,
        'operation_type' => PaymentAuthenticationAttempt::OPERATION_CARD_VERIFICATION_3DS,
        'provider' => PaymentAuthenticationAttempt::PROVIDER_EFEVOOPAY,
        'status' => PaymentAuthenticationAttemptStatus::Pending->value,
        'merchant_reference' => 'EFV3DS-IDEM',
        'efevoo_3ds_session_id' => $session->id,
        'attempt_number' => 1,
        'started_at' => now(),
        'expires_at' => now()->addMinutes(5),
    ]);

    Session::put('3ds_card_data_'.$session->id, hardeningCardPayload($user, $session->id));

    expect($store->purge($session->id, 'test', $attempt))->toBeTrue()
        ->and($store->purge($session->id, 'test', $attempt))->toBeFalse()
        ->and(Session::has('3ds_card_data_'.$session->id))->toBeFalse();
});

it('admin event resource exposes purge metadata without sensitive card fields', function () {
    $user = hardeningUser();
    $attempt = PaymentAuthenticationAttempt::create([
        'attempt_uuid' => (string) Str::uuid(),
        'support_reference' => 'AUTH-ADM',
        'customer_id' => $user->customer->id,
        'operation_type' => PaymentAuthenticationAttempt::OPERATION_CARD_VERIFICATION_3DS,
        'provider' => PaymentAuthenticationAttempt::PROVIDER_EFEVOOPAY,
        'status' => PaymentAuthenticationAttemptStatus::Declined->value,
        'merchant_reference' => 'EFV3DS-ADM',
        'attempt_number' => 1,
        'started_at' => now(),
        'finished_at' => now(),
        'expires_at' => now()->addMinutes(5),
    ]);

    $event = PaymentAuthenticationAttemptEvent::create([
        'event_uuid' => (string) Str::uuid(),
        'payment_authentication_attempt_id' => $attempt->id,
        'event_type' => PaymentAuthenticationAttemptEventType::SensitiveCardDataPurged->value,
        'source' => 'backend',
        'metadata' => [
            'reason' => 'changed_to_paypal',
            'stage' => 'paypal_recovery_start',
            'session_id' => 42,
            'detected_by' => 'famedic',
            'card_number' => 'should-not-leak',
            'cvv' => '999',
        ],
        'occurred_at' => now(),
    ]);

    $dto = PaymentAuthenticationAttemptEventAdminResource::make($event);
    $json = json_encode($dto);

    expect($dto['label'])->toBe('Datos sensibles purgados')
        ->and($dto['metadata']['reason'] ?? null)->toBe('changed_to_paypal')
        ->and($json)->not->toContain('should-not-leak')
        ->and($json)->not->toContain('999')
        ->and($json)->not->toContain('card_number');
});

it('codec rejects invalid base64 and corrupt serialized payloads without throwing', function () {
    $codec = app(LaravelDatabaseSessionPayloadCodec::class);

    expect($codec->decode('not-valid-base64!!!'))->toBeNull()
        ->and($codec->decode(base64_encode('not-serialized')))->toBeNull();
});

it('tokenization receives pan and expiration but not cvv and does not re-store session data', function () {
    $finalizePayload = null;
    app()->instance(EfevooPayGateway::class, new class($finalizePayload) implements EfevooPayGateway
    {
        public function __construct(private mixed &$finalizePayload) {}

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
            return ['success' => true, 'session_id' => 1];
        }

        public function complete3DS(Efevoo3dsSession $session, array $cardData): array
        {
            return ['success' => true];
        }

        public function poll3DSAuthentication(Efevoo3dsSession $session, array $cardData): array
        {
            $session->update(['status' => 'authenticated']);

            return ['phase' => 'authenticated', 'success' => true];
        }

        public function finalize3DSTokenization(Efevoo3dsSession $session, array $cardData): array
        {
            $this->finalizePayload = $cardData;
            $session->update(['status' => 'completed']);

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

    $user = hardeningUser();
    $session = Efevoo3dsSession::create([
        'customer_id' => $user->customer->id,
        'order_id' => 'ORDER-TOK',
        'card_last_four' => '1111',
        'amount' => 1.5,
        'status' => 'pending',
    ]);

    $this->actingAs($user)->withSession([
        '3ds_card_data_'.$session->id => hardeningCardPayload($user, $session->id),
    ])->getJson(route('payment-methods.3ds-status', $session))->assertOk();

    expect($finalizePayload)->not->toBeNull()
        ->and($finalizePayload)->not->toHaveKey('cvv')
        ->and($finalizePayload)->toHaveKeys(['card_number', 'expiration'])
        ->and(Session::has('3ds_card_data_'.$session->id))->toBeFalse();
});
