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
use App\Support\EfevooPayGatewayMode;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;

beforeEach(function () {
    config([
        'efevoopay.requires_3ds' => true,
        'efevoopay.sensitive_card_data.containment_enabled' => true,
        'efevoopay.gateway' => 'mock',
    ]);
});

function hotfixUser(): User
{
    return User::factory()
        ->withCompleteProfile()
        ->withRegularCustomer()
        ->create(['documentation_accepted_at' => now()])
        ->fresh(['customer']);
}

function hotfixAttempt(User $user, array $overrides = []): PaymentAuthenticationAttempt
{
    return PaymentAuthenticationAttempt::create(array_merge([
        'attempt_uuid' => (string) Str::uuid(),
        'support_reference' => 'AUTH-'.Str::upper(Str::random(8)),
        'customer_id' => $user->customer->id,
        'operation_type' => PaymentAuthenticationAttempt::OPERATION_CARD_VERIFICATION_3DS,
        'provider' => PaymentAuthenticationAttempt::PROVIDER_EFEVOOPAY,
        'status' => PaymentAuthenticationAttemptStatus::ChallengeRequired->value,
        'merchant_reference' => 'EFV3DS-'.Str::upper(Str::random(8)),
        'started_at' => now(),
        'expires_at' => now()->addMinutes(5),
    ], $overrides));
}

function hotfixSession(User $user, PaymentAuthenticationAttempt $attempt, array $overrides = []): Efevoo3dsSession
{
    $session = Efevoo3dsSession::create(array_merge([
        'customer_id' => $user->customer->id,
        'payment_authentication_attempt_id' => $attempt->id,
        'order_id' => 'ORDER-'.Str::upper(Str::random(6)),
        'card_last_four' => '4242',
        'amount' => 1.5,
        'status' => 'mock_pending',
    ], $overrides));

    $attempt->update(['efevoo_3ds_session_id' => $session->id]);

    return $session;
}

function hotfixSensitiveSession(User $user, int $sessionId): array
{
    return [
        '3ds_card_data_'.$sessionId => [
            'card_number' => '4242424242424242',
            'expiration' => '1229',
            'cvv' => '123',
            'amount' => 1.5,
            'stored_at' => now()->timestamp,
            'expires_at' => now()->addMinutes(5)->timestamp,
            'customer_id' => $user->customer->id,
            'efevoo_3ds_session_id' => $sessionId,
        ],
    ];
}

function bindHotfixGateway(array &$calls, ?callable $finalize = null): void
{
    app()->instance(EfevooPayGateway::class, new class($calls, $finalize) implements EfevooPayGateway
    {
        public function __construct(private array &$calls, private $finalize) {}

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

            return ['success' => true, 'session_id' => 1];
        }

        public function complete3DS(Efevoo3dsSession $session, array $cardData): array
        {
            return ['success' => true];
        }

        public function poll3DSAuthentication(Efevoo3dsSession $session, array $cardData): array
        {
            $this->calls['poll3DSAuthentication'] = ($this->calls['poll3DSAuthentication'] ?? 0) + 1;

            return ['phase' => 'authenticated', 'success' => true];
        }

        public function finalize3DSTokenization(Efevoo3dsSession $session, array $cardData): array
        {
            $this->calls['finalize3DSTokenization'] = ($this->calls['finalize3DSTokenization'] ?? 0) + 1;

            if ($this->finalize) {
                return ($this->finalize)($session, $cardData);
            }

            $session->update(['status' => 'completed', 'completed_at' => now()]);

            return ['success' => true, 'message' => 'completed', 'external_tokenization_attempted' => true];
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

it('allows only one external poll cycle while tokenization is slow', function () {
    $calls = [];
    bindHotfixGateway($calls, function (Efevoo3dsSession $session) {
        usleep(150000);

        $session->update(['status' => 'completed', 'completed_at' => now()]);

        return ['success' => true, 'external_tokenization_attempted' => true];
    });

    $user = hotfixUser();
    $attempt = hotfixAttempt($user);
    $session = hotfixSession($user, $attempt);
    $payload = hotfixSensitiveSession($user, $session->id);

    $first = fn () => test()->actingAs($user)->withSession($payload)
        ->getJson(route('payment-methods.3ds-status', $session));
    $second = fn () => test()->actingAs($user)->withSession($payload)
        ->getJson(route('payment-methods.3ds-status', $session));

    [$responseA, $responseB] = [$first(), $second()];

    expect($responseA->status())->toBeIn([200, 409])
        ->and($responseB->status())->toBe(200)
        ->and($calls['poll3DSAuthentication'] ?? 0)->toBe(1)
        ->and($calls['finalize3DSTokenization'] ?? 0)->toBe(1);

    $authSucceeded = PaymentAuthenticationAttemptEvent::query()
        ->where('payment_authentication_attempt_id', $attempt->id)
        ->where('event_type', PaymentAuthenticationAttemptEventType::AuthenticationSucceeded->value)
        ->count();
    $tokenStarted = PaymentAuthenticationAttemptEvent::query()
        ->where('payment_authentication_attempt_id', $attempt->id)
        ->where('event_type', PaymentAuthenticationAttemptEventType::TokenizationStarted->value)
        ->count();

    expect($authSucceeded)->toBeLessThanOrEqual(1)
        ->and($tokenStarted)->toBeLessThanOrEqual(1);
});

it('does not call provider again after completed terminal revisit', function () {
    $calls = [];
    bindHotfixGateway($calls);
    $user = hotfixUser();
    $attempt = hotfixAttempt($user, ['status' => PaymentAuthenticationAttemptStatus::Completed->value]);
    $session = hotfixSession($user, $attempt, ['status' => 'completed', 'completed_at' => now()]);

    test()->actingAs($user)->withSession(hotfixSensitiveSession($user, $session->id))
        ->getJson(route('payment-methods.3ds-status', $session))
        ->assertOk()
        ->assertJsonPath('final', true);

    expect($calls['poll3DSAuthentication'] ?? 0)->toBe(0)
        ->and($calls['finalize3DSTokenization'] ?? 0)->toBe(0);
});

it('skips get status while attempt is tokenizing', function () {
    $calls = [];
    bindHotfixGateway($calls);
    $user = hotfixUser();
    $attempt = hotfixAttempt($user, ['status' => PaymentAuthenticationAttemptStatus::Tokenizing->value]);
    $session = hotfixSession($user, $attempt, ['status' => 'authenticated']);

    test()->actingAs($user)->withSession(hotfixSensitiveSession($user, $session->id))
        ->getJson(route('payment-methods.3ds-status', $session))
        ->assertOk()
        ->assertJsonPath('status', PaymentAuthenticationAttemptStatus::Tokenizing->value);

    expect($calls['poll3DSAuthentication'] ?? 0)->toBe(0)
        ->and($calls['finalize3DSTokenization'] ?? 0)->toBe(0);
});

it('classifies rejected http 200 as authentication failed with unknown origin and no token card', function () {
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
            return ['success' => true];
        }

        public function complete3DS(Efevoo3dsSession $session, array $cardData): array
        {
            return ['success' => false];
        }

        public function poll3DSAuthentication(Efevoo3dsSession $session, array $cardData): array
        {
            $this->calls['poll3DSAuthentication'] = ($this->calls['poll3DSAuthentication'] ?? 0) + 1;
            $session->update(['status' => 'declined', 'error_message' => 'Rejected']);

            return ['phase' => 'rejected', 'success' => false, 'message' => 'Rejected', 'http_status' => 200];
        }

        public function finalize3DSTokenization(Efevoo3dsSession $session, array $cardData): array
        {
            $this->calls['finalize3DSTokenization'] = ($this->calls['finalize3DSTokenization'] ?? 0) + 1;

            return ['success' => false];
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

    $user = hotfixUser();
    $attempt = hotfixAttempt($user);
    $session = hotfixSession($user, $attempt);

    test()->actingAs($user)->withSession(hotfixSensitiveSession($user, $session->id))
        ->getJson(route('payment-methods.3ds-status', $session))
        ->assertOk();

    $attempt->refresh();
    $classification = EfevooPay3dsResultClassifier::providerStatus('rejected', null, 'Rejected');

    expect($calls['finalize3DSTokenization'] ?? 0)->toBe(0)
        ->and($attempt->status)->toBe(PaymentAuthenticationAttemptStatus::Declined->value)
        ->and($classification['result_category'])->toBe(EfevooPay3dsResultClassifier::CATEGORY_AUTHENTICATION_FAILED)
        ->and($classification['failure_origin'])->toBe(EfevooPay3dsResultClassifier::ORIGIN_UNKNOWN);
});

it('does not flag get link plus token card as duplicate verification', function () {
    $attempt = hotfixAttempt(hotfixUser(), [
        'status' => PaymentAuthenticationAttemptStatus::Completed->value,
        'provider_link_call_count' => 1,
        'tokenization_call_count' => 1,
    ]);

    $analysis = app(PaymentAuthenticationEfevooPayOperationAnalyzer::class)->analyze($attempt);

    expect($analysis['possible_duplicate_verification_operation'])->toBeFalse();
});

it('hides mock gateway tokens when live gateway is active', function () {
    config(['efevoopay.gateway' => 'live', 'efevoopay.environment' => 'production']);
    $user = hotfixUser();

    EfevooToken::factory()->create([
        'customer_id' => $user->customer->id,
        'environment' => 'test',
        'metadata' => ['mock' => true, 'gateway_origin' => EfevooPayGatewayMode::MOCK],
        'is_active' => true,
    ]);

    EfevooToken::factory()->create([
        'customer_id' => $user->customer->id,
        'environment' => 'production',
        'metadata' => ['gateway_origin' => EfevooPayGatewayMode::LIVE],
        'is_active' => true,
    ]);

    test()->actingAs($user)
        ->get(route('payment-methods.index'))
        ->assertInertia(fn (Assert $page) => $page
            ->component('PaymentMethods')
            ->has('paymentMethods', 1));
});

it('hides live tokens when mock gateway is active', function () {
    config(['efevoopay.gateway' => 'mock', 'efevoopay.environment' => 'test']);
    $user = hotfixUser();

    EfevooToken::factory()->create([
        'customer_id' => $user->customer->id,
        'environment' => 'test',
        'metadata' => ['mock' => true, 'gateway_origin' => EfevooPayGatewayMode::MOCK],
        'is_active' => true,
    ]);

    EfevooToken::factory()->create([
        'customer_id' => $user->customer->id,
        'environment' => 'production',
        'metadata' => ['gateway_origin' => EfevooPayGatewayMode::LIVE],
        'is_active' => true,
    ]);

    test()->actingAs($user)
        ->get(route('payment-methods.index'))
        ->assertInertia(fn (Assert $page) => $page
            ->component('PaymentMethods')
            ->has('paymentMethods', 1));
});

afterEach(function () {
    Cache::flush();
});
