<?php

use App\Contracts\EfevooPayGateway;
use App\Enums\PaymentAuthenticationAttemptEventType;
use App\Enums\PaymentAuthenticationAttemptStatus;
use App\Models\Efevoo3dsSession;
use App\Models\EfevooToken;
use App\Models\PaymentAuthenticationAttempt;
use App\Models\User;
use App\Services\EfevooPayService;
use App\Support\PaymentAuthentication3dsExternalCallGuard;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;

beforeEach(function () {
    config([
        'app.env' => 'local',
        'efevoopay.environment' => 'production',
        'efevoopay.requires_3ds' => true,
        'efevoopay.sensitive_card_data.containment_enabled' => true,
    ]);
});

function reuseUser(): User
{
    return User::factory()
        ->withCompleteProfile()
        ->withRegularCustomer()
        ->create(['documentation_accepted_at' => now()])
        ->fresh(['customer']);
}

function reusableService(string $providerToken = 'provider-token-new'): EfevooPayService
{
    return new class($providerToken) extends EfevooPayService
    {
        public int $tokenCardRequests = 0;

        public function __construct(private string $providerToken)
        {
            parent::__construct();
        }

        public function getClientToken(string $operation = 'default'): array
        {
            return ['success' => true, 'token' => 'client-token-for-test'];
        }

        protected function request(array $payload, bool $logRawBody = true): array
        {
            $this->tokenCardRequests++;

            return [
                'success' => true,
                'status' => 200,
                'data' => [
                    'token_usuario' => $this->providerToken,
                    'token' => 'client-token-response',
                    'status' => ['code' => '0'],
                ],
            ];
        }
    };
}

function reuseCardPayload(array $overrides = []): array
{
    return array_merge([
        'card_number' => '4000000000004242',
        'expiration' => '1229',
        'amount' => 1.5,
        'card_holder' => 'TOKEN USER',
        'alias' => 'main',
    ], $overrides);
}

function reuseAttempt(User $user, array $overrides = []): PaymentAuthenticationAttempt
{
    return PaymentAuthenticationAttempt::create(array_merge([
        'attempt_uuid' => (string) Str::uuid(),
        'support_reference' => 'AUTH-'.Str::upper(Str::random(8)),
        'customer_id' => $user->customer->id,
        'operation_type' => PaymentAuthenticationAttempt::OPERATION_CARD_VERIFICATION_3DS,
        'provider' => PaymentAuthenticationAttempt::PROVIDER_EFEVOOPAY,
        'status' => PaymentAuthenticationAttemptStatus::Authenticated->value,
        'merchant_reference' => 'EFV3DS-'.Str::upper(Str::random(8)),
        'started_at' => now(),
        'expires_at' => now()->addMinutes(5),
    ], $overrides));
}

function reuseSession(User $user, PaymentAuthenticationAttempt $attempt, array $overrides = []): Efevoo3dsSession
{
    $session = Efevoo3dsSession::create(array_merge([
        'customer_id' => $user->customer->id,
        'payment_authentication_attempt_id' => $attempt->id,
        'order_id' => 'ORDER-REUSE',
        'card_last_four' => '4242',
        'amount' => 1.5,
        'status' => 'authenticated',
    ], $overrides));

    $attempt->update(['efevoo_3ds_session_id' => $session->id]);

    return $session;
}

it('reuses an active visible token only when a secure gateway identity matches', function () {
    $user = reuseUser();
    $token = EfevooToken::factory()->create([
        'customer_id' => $user->customer->id,
        'card_last_four' => '4242',
        'card_expiration' => '1229',
        'environment' => 'production',
        'is_active' => true,
        'expires_at' => now()->addYear(),
        'metadata' => ['gateway_card_id' => 'gw-card-1'],
    ]);
    $service = reusableService();

    $result = $service->tokenizeCard(reuseCardPayload(['gateway_card_id' => 'gw-card-1']), $user->customer->id);

    expect($result['success'])->toBeTrue()
        ->and($result['reused'])->toBeTrue()
        ->and($result['token_id'])->toBe($token->id)
        ->and($result['external_tokenization_attempted'])->toBeFalse()
        ->and($service->tokenCardRequests)->toBe(0);
});

it('does not reuse inactive expired soft deleted or other environment tokens', function (array $overrides) {
    $user = reuseUser();
    EfevooToken::factory()->create(array_merge([
        'customer_id' => $user->customer->id,
        'card_last_four' => '4242',
        'card_expiration' => '1229',
        'environment' => 'production',
        'is_active' => true,
        'expires_at' => now()->addYear(),
        'metadata' => ['gateway_card_id' => 'gw-card-1'],
    ], $overrides));
    $service = reusableService('provider-token-'.Str::random(8));

    $result = $service->tokenizeCard(reuseCardPayload(['gateway_card_id' => 'gw-card-1']), $user->customer->id);

    expect($result['success'])->toBeTrue()
        ->and($result['reused'] ?? false)->toBeFalse()
        ->and($result['external_tokenization_attempted'])->toBeTrue()
        ->and($service->tokenCardRequests)->toBe(1);
})->with([
    'inactive' => [['is_active' => false]],
    'expired' => [['expires_at' => now()->subDay()]],
    'soft deleted' => [['deleted_at' => now()]],
    'other environment' => [['environment' => 'test']],
]);

it('does not treat two different cards with the same last four as equal', function () {
    $user = reuseUser();
    $existing = EfevooToken::factory()->create([
        'customer_id' => $user->customer->id,
        'card_last_four' => '4242',
        'card_expiration' => '1229',
        'environment' => 'production',
        'is_active' => true,
        'expires_at' => now()->addYear(),
    ]);
    $service = reusableService('provider-token-distinct');

    $result = $service->tokenizeCard(reuseCardPayload(), $user->customer->id);

    expect($result['success'])->toBeTrue()
        ->and($result['token_id'])->not->toBe($existing->id)
        ->and($result['external_tokenization_attempted'])->toBeTrue()
        ->and($service->tokenCardRequests)->toBe(1);
});

it('deduplicates by provider token only after an external TokenCard response', function () {
    $user = reuseUser();
    $existing = EfevooToken::factory()->create([
        'customer_id' => $user->customer->id,
        'card_token' => 'provider-token-stable',
        'card_last_four' => '4242',
        'card_expiration' => '1229',
        'environment' => 'production',
        'is_active' => true,
        'expires_at' => now()->addYear(),
    ]);
    $service = reusableService('provider-token-stable');

    $result = $service->tokenizeCard(reuseCardPayload(), $user->customer->id);

    expect($result['success'])->toBeTrue()
        ->and($result['reused'])->toBeTrue()
        ->and($result['token_id'])->toBe($existing->id)
        ->and($result['external_tokenization_attempted'])->toBeTrue()
        ->and($service->tokenCardRequests)->toBe(1);
});

it('records existing token reuse without marking TokenCard as externally succeeded', function () {
    $user = reuseUser();
    $attempt = reuseAttempt($user);
    $session = reuseSession($user, $attempt);

    app(PaymentAuthentication3dsExternalCallGuard::class)->withTokenizationClaim(
        $session,
        $attempt,
        fn () => [
            'success' => true,
            'token_id' => 527,
            'reused' => true,
            'external_tokenization_attempted' => false,
        ]
    );

    $events = $attempt->fresh()->events()->pluck('event_type')->all();

    expect($events)->toContain(PaymentAuthenticationAttemptEventType::TokenizationRequestStarted->value)
        ->and($events)->toContain(PaymentAuthenticationAttemptEventType::ExistingTokenReused->value)
        ->and($events)->not->toContain(PaymentAuthenticationAttemptEventType::TokenizationRequestSucceeded->value)
        ->and($attempt->fresh()->tokenization_call_count)->toBe(0);
});

it('records TokenCard succeeded only after an external response', function () {
    $user = reuseUser();
    $attempt = reuseAttempt($user);
    $session = reuseSession($user, $attempt);

    app(PaymentAuthentication3dsExternalCallGuard::class)->withTokenizationClaim(
        $session,
        $attempt,
        fn () => [
            'success' => true,
            'token_id' => 99,
            'external_tokenization_attempted' => true,
        ]
    );

    expect($attempt->fresh()->events()->where('event_type', PaymentAuthenticationAttemptEventType::TokenizationRequestSucceeded->value)->exists())->toBeTrue()
        ->and($attempt->fresh()->tokenization_call_count)->toBe(1);
});

it('shows a reused visible token in payment methods', function () {
    config(['efevoopay.gateway' => 'live', 'efevoopay.environment' => 'production']);
    $user = reuseUser();
    $token = EfevooToken::factory()->create([
        'customer_id' => $user->customer->id,
        'card_last_four' => '4242',
        'card_expiration' => '1229',
        'environment' => 'production',
        'is_active' => true,
        'expires_at' => now()->addYear(),
        'metadata' => [
            'gateway_origin' => 'live',
            'gateway_card_id' => 'gw-card-visible',
        ],
    ]);

    $this->actingAs($user)
        ->get(route('payment-methods.index'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('PaymentMethods')
            ->where('paymentMethods.0.id', $token->id)
            ->where('paymentMethods.0.card_last_four', '4242')
        );
});

it('does not tokenize or repeat completed transitions on later polls', function () {
    $calls = [];
    app()->instance(EfevooPayGateway::class, new class($calls) implements EfevooPayGateway
    {
        public function __construct(private array &$calls) {}
        public function chargeCard(array $data): array { return ['success' => true]; }
        public function tokenizeCard(array $cardData, int $customerId): array { return ['success' => true, 'token_id' => 1]; }
        public function initiate3DS(array $cardData, int $customerId): array { return ['success' => false]; }
        public function complete3DS(Efevoo3dsSession $session, array $cardData): array { return ['success' => true]; }
        public function poll3DSAuthentication(Efevoo3dsSession $session, array $cardData): array
        {
            $this->calls['poll'] = ($this->calls['poll'] ?? 0) + 1;
            $session->update(['status' => 'authenticated']);

            return ['phase' => 'authenticated', 'success' => true];
        }
        public function finalize3DSTokenization(Efevoo3dsSession $session, array $cardData): array
        {
            $this->calls['finalize'] = ($this->calls['finalize'] ?? 0) + 1;
            $session->update(['status' => 'completed', 'completed_at' => now()]);

            return ['success' => true, 'message' => 'completed', 'external_tokenization_attempted' => true];
        }
        public function healthCheck(): array { return ['success' => true]; }
        public function getTestCards(): array { return []; }
    });

    $user = reuseUser();
    $attempt = reuseAttempt($user, ['status' => PaymentAuthenticationAttemptStatus::ChallengeRequired->value]);
    $session = reuseSession($user, $attempt, ['status' => 'mock_pending']);
    $cardData = [
        'card_number' => '4000000000004242',
        'expiration' => '1229',
        'cvv' => '123',
        'amount' => 1.5,
    ];
    $stored = [
        '3ds_card_data_'.$session->id => array_merge($cardData, [
            'stored_at' => now()->timestamp,
            'expires_at' => now()->addMinutes(5)->timestamp,
            'customer_id' => $user->customer->id,
            'efevoo_3ds_session_id' => $session->id,
        ]),
    ];

    $this->actingAs($user)->withSession($stored)->getJson(route('payment-methods.3ds-status', $session))->assertOk();
    $this->actingAs($user)->withSession($stored)->getJson(route('payment-methods.3ds-status', $session))->assertOk();

    expect($calls['finalize'])->toBe(1)
        ->and($attempt->fresh()->events()->where('event_type', PaymentAuthenticationAttemptEventType::AttemptCompleted->value)->count())->toBe(1);
});
