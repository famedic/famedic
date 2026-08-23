<?php

use App\Contracts\EfevooPayGateway;
use App\Enums\PaymentAuthenticationAttemptStatus;
use App\Models\Efevoo3dsSession;
use App\Models\PaymentAuthenticationAttempt;
use App\Models\User;
use App\Support\PaymentAuthentication3dsStartResource;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

beforeEach(function () {
    config(['efevoopay.requires_3ds' => true]);
});

function cardAuthUser(): User
{
    return User::factory()
        ->withCompleteProfile()
        ->withRegularCustomer()
        ->create([
            'documentation_accepted_at' => now(),
        ])
        ->fresh(['customer']);
}

function cardAuthPayload(array $overrides = []): array
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

function fake3dsGateway(array &$calls, ?callable $initiate = null, ?callable $complete = null): EfevooPayGateway
{
    return new class($calls, $initiate, $complete) implements EfevooPayGateway
    {
        public function __construct(
            private array &$calls,
            private $initiate,
            private $complete
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
            $this->calls['transaction_levels'][] = DB::transactionLevel();

            if ($this->initiate) {
                return ($this->initiate)($cardData, $customerId);
            }

            $session = Efevoo3dsSession::create([
                'customer_id' => $customerId,
                'order_id' => 'ORDER-'.Str::upper(Str::random(8)),
                'card_last_four' => substr(preg_replace('/\D/', '', $cardData['card_number']), -4),
                'amount' => $cardData['amount'] ?? 1.5,
                'status' => 'mock_pending',
                'url_3dsecure' => 'https://issuer.example/challenge',
                'token_3dsecure' => 'secret-creq-token',
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
            $this->calls['complete3DS'] = ($this->calls['complete3DS'] ?? 0) + 1;

            if ($this->complete) {
                return ($this->complete)($session, $cardData);
            }

            $session->update([
                'status' => 'completed',
                'efevoo_token_id' => null,
                'completed_at' => now(),
            ]);

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

            $session->update([
                'status' => 'completed',
                'completed_at' => now(),
            ]);

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

function sensitiveCardSessionPayload(User $user, int $sessionId, array $cardData): array
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

function bindFake3dsGateway(array &$calls, ?callable $initiate = null, ?callable $complete = null): void
{
    app()->instance(EfevooPayGateway::class, fake3dsGateway($calls, $initiate, $complete));
}

it('normal submit creates one provider 3ds link attempt outside a db transaction', function () {
    $calls = [];
    bindFake3dsGateway($calls);
    $user = cardAuthUser();
    $baselineTransactionLevel = DB::transactionLevel();

    $this->actingAs($user)
        ->post(route('payment-methods.store'), cardAuthPayload())
        ->assertRedirect();

    expect($calls['initiate3DS'])->toBe(1)
        ->and($calls['transaction_levels'])->toBe([$baselineTransactionLevel])
        ->and(PaymentAuthenticationAttempt::first()->status)->toBe(PaymentAuthenticationAttemptStatus::ChallengeRequired->value);
});

it('same uuid submitted twice reuses the existing attempt and calls provider once', function () {
    $calls = [];
    bindFake3dsGateway($calls);
    $user = cardAuthUser();
    $uuid = (string) Str::uuid();

    $this->actingAs($user)->post(route('payment-methods.store'), cardAuthPayload(['attempt_uuid' => $uuid]))->assertRedirect();
    $this->actingAs($user)->post(route('payment-methods.store'), cardAuthPayload(['attempt_uuid' => $uuid]))->assertRedirect();

    $attempt = PaymentAuthenticationAttempt::where('attempt_uuid', $uuid)->first();

    expect($calls['initiate3DS'])->toBe(1)
        ->and($attempt->duplicate_request_count)->toBe(1);
});

it('different uuid in another tab is blocked while an active attempt exists', function () {
    $calls = [];
    bindFake3dsGateway($calls);
    $user = cardAuthUser();

    $this->actingAs($user)->post(route('payment-methods.store'), cardAuthPayload())->assertRedirect();

    $this->actingAs($user)
        ->postJson(route('payment-methods.store'), cardAuthPayload())
        ->assertConflict()
        ->assertJsonPath('message', 'Ya tienes una verificacion de tarjeta en proceso.');

    expect($calls['initiate3DS'])->toBe(1);
});

it('refreshing the redirect page does not call provider again', function () {
    $calls = [];
    bindFake3dsGateway($calls);
    $user = cardAuthUser();

    $this->actingAs($user)->post(route('payment-methods.store'), cardAuthPayload())->assertRedirect();
    $session = Efevoo3dsSession::first();

    $this->actingAs($user)->get(route('payment-methods.3ds-redirect', $session))->assertOk();

    expect($calls['initiate3DS'])->toBe(1);
});

it('a repeated request during initiating does not call provider', function () {
    $calls = [];
    bindFake3dsGateway($calls);
    $user = cardAuthUser();
    $uuid = (string) Str::uuid();

    PaymentAuthenticationAttempt::create([
        'attempt_uuid' => $uuid,
        'support_reference' => 'AUTH-INIT',
        'customer_id' => $user->customer->id,
        'operation_type' => PaymentAuthenticationAttempt::OPERATION_CARD_VERIFICATION_3DS,
        'provider' => PaymentAuthenticationAttempt::PROVIDER_EFEVOOPAY,
        'status' => PaymentAuthenticationAttemptStatus::Initiating->value,
        'merchant_reference' => 'EFV3DS-INIT',
        'started_at' => now(),
        'expires_at' => now()->addMinutes(5),
    ]);

    $this->actingAs($user)
        ->from(route('payment-methods.create'))
        ->post(route('payment-methods.store'), cardAuthPayload(['attempt_uuid' => $uuid]))
        ->assertRedirect(route('payment-methods.create'));

    expect($calls['initiate3DS'] ?? 0)->toBe(0);
});

it('ambiguous timeout leaves provider confirmation pending and blocks immediate retry', function () {
    $calls = [];
    bindFake3dsGateway($calls, fn () => throw new RuntimeException('curl timeout after send'));
    $user = cardAuthUser();

    $this->actingAs($user)->post(route('payment-methods.store'), cardAuthPayload())->assertSessionHasErrors('error');

    $attempt = PaymentAuthenticationAttempt::first();

    expect($calls['initiate3DS'])->toBe(1)
        ->and($attempt->status)->toBe(PaymentAuthenticationAttemptStatus::ProviderConfirmationPending->value);

    $this->actingAs($user)->postJson(route('payment-methods.store'), cardAuthPayload())->assertConflict();

    expect($calls['initiate3DS'])->toBe(1);
});

it('validation errors before provider call create no attempt', function () {
    $calls = [];
    bindFake3dsGateway($calls);
    $user = cardAuthUser();

    $this->actingAs($user)
        ->post(route('payment-methods.store'), cardAuthPayload(['exp_month' => '13']))
        ->assertSessionHasErrors('exp_month');

    expect($calls['initiate3DS'] ?? 0)->toBe(0)
        ->and(PaymentAuthenticationAttempt::count())->toBe(0);
});

it('provider rejection marks the attempt declined', function () {
    $calls = [];
    bindFake3dsGateway($calls, fn () => [
        'success' => false,
        'message' => 'Rejected',
        'error_type' => 'gateway',
        'raw' => ['data' => ['status' => ['code' => '05']]],
    ]);
    $user = cardAuthUser();

    $this->actingAs($user)->post(route('payment-methods.store'), cardAuthPayload())->assertSessionHasErrors('error');

    expect($calls['initiate3DS'])->toBe(1)
        ->and(PaymentAuthenticationAttempt::first()->status)->toBe(PaymentAuthenticationAttemptStatus::Declined->value);
});

it('manual retry is allowed only from a recoverable terminal state', function () {
    $calls = [];
    bindFake3dsGateway($calls);
    $user = cardAuthUser();

    $previous = PaymentAuthenticationAttempt::create([
        'attempt_uuid' => (string) Str::uuid(),
        'support_reference' => 'AUTH-DECLINED',
        'customer_id' => $user->customer->id,
        'operation_type' => PaymentAuthenticationAttempt::OPERATION_CARD_VERIFICATION_3DS,
        'provider' => PaymentAuthenticationAttempt::PROVIDER_EFEVOOPAY,
        'status' => PaymentAuthenticationAttemptStatus::Declined->value,
        'merchant_reference' => 'EFV3DS-DECLINED',
        'attempt_number' => 1,
        'started_at' => now()->subMinute(),
        'finished_at' => now(),
        'expires_at' => now()->addMinutes(5),
    ]);

    $this->actingAs($user)
        ->post(route('payment-methods.store'), cardAuthPayload(['retry_of_attempt_id' => $previous->id]))
        ->assertRedirect();

    $retry = PaymentAuthenticationAttempt::where('retry_of_attempt_id', $previous->id)->first();

    expect($calls['initiate3DS'])->toBe(1)
        ->and($retry->attempt_number)->toBe(2);
});

it('manual retry from active state is blocked', function () {
    $calls = [];
    bindFake3dsGateway($calls);
    $user = cardAuthUser();

    $active = PaymentAuthenticationAttempt::create([
        'attempt_uuid' => (string) Str::uuid(),
        'support_reference' => 'AUTH-PENDING',
        'customer_id' => $user->customer->id,
        'operation_type' => PaymentAuthenticationAttempt::OPERATION_CARD_VERIFICATION_3DS,
        'provider' => PaymentAuthenticationAttempt::PROVIDER_EFEVOOPAY,
        'status' => PaymentAuthenticationAttemptStatus::Pending->value,
        'merchant_reference' => 'EFV3DS-PENDING',
        'started_at' => now(),
        'expires_at' => now()->addMinutes(5),
    ]);

    $this->actingAs($user)
        ->postJson(route('payment-methods.store'), cardAuthPayload(['retry_of_attempt_id' => $active->id]))
        ->assertConflict();

    expect($calls['initiate3DS'] ?? 0)->toBe(0);
});

it('users cannot see another customers redirect challenge', function () {
    $owner = cardAuthUser();
    $other = cardAuthUser();
    $session = Efevoo3dsSession::create([
        'customer_id' => $owner->customer->id,
        'order_id' => 'ORDER-OWNED',
        'card_last_four' => '4242',
        'amount' => 1.5,
        'status' => 'redirect_required',
        'url_3dsecure' => 'https://issuer.example/challenge',
        'token_3dsecure' => 'secret-creq-token',
    ]);

    $this->actingAs($other)->get(route('payment-methods.3ds-redirect', $session))->assertNotFound();
});

it('uuid collision from another user does not reveal attempt data', function () {
    $calls = [];
    bindFake3dsGateway($calls);
    $owner = cardAuthUser();
    $other = cardAuthUser();
    $uuid = (string) Str::uuid();

    PaymentAuthenticationAttempt::create([
        'attempt_uuid' => $uuid,
        'support_reference' => 'AUTH-SECRET',
        'customer_id' => $owner->customer->id,
        'operation_type' => PaymentAuthenticationAttempt::OPERATION_CARD_VERIFICATION_3DS,
        'provider' => PaymentAuthenticationAttempt::PROVIDER_EFEVOOPAY,
        'status' => PaymentAuthenticationAttemptStatus::Pending->value,
        'merchant_reference' => 'EFV3DS-SECRET',
        'started_at' => now(),
        'expires_at' => now()->addMinutes(5),
    ]);

    $response = $this->actingAs($other)
        ->postJson(route('payment-methods.store'), cardAuthPayload(['attempt_uuid' => $uuid]))
        ->assertUnprocessable()
        ->getContent();

    expect($response)->not->toContain('AUTH-SECRET')
        ->and($calls['initiate3DS'] ?? 0)->toBe(0);
});

it('polling never initiates a new get link and does not tokenize twice', function () {
    $calls = [];
    bindFake3dsGateway($calls);
    $user = cardAuthUser();
    $attempt = PaymentAuthenticationAttempt::create([
        'attempt_uuid' => (string) Str::uuid(),
        'support_reference' => 'AUTH-POLL',
        'customer_id' => $user->customer->id,
        'operation_type' => PaymentAuthenticationAttempt::OPERATION_CARD_VERIFICATION_3DS,
        'provider' => PaymentAuthenticationAttempt::PROVIDER_EFEVOOPAY,
        'status' => PaymentAuthenticationAttemptStatus::ChallengeRequired->value,
        'merchant_reference' => 'EFV3DS-POLL',
        'started_at' => now(),
        'expires_at' => now()->addMinutes(5),
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

    $cardData = ['card_number' => '4242424242424242', 'expiration' => '1229', 'cvv' => '123', 'amount' => 1.5];

    $this->actingAs($user)->withSession(sensitiveCardSessionPayload($user, $session->id, $cardData))
        ->getJson(route('payment-methods.3ds-status', $session))
        ->assertOk()
        ->assertJsonPath('final', true);
    $this->actingAs($user)->withSession(sensitiveCardSessionPayload($user, $session->id, $cardData))
        ->getJson(route('payment-methods.3ds-status', $session))
        ->assertOk()
        ->assertJsonPath('final', true);

    expect($calls['initiate3DS'] ?? 0)->toBe(0)
        ->and($calls['poll3DSAuthentication'] ?? 0)->toBe(1)
        ->and($calls['finalize3DSTokenization'] ?? 0)->toBe(1)
        ->and($attempt->fresh()->status)->toBe(PaymentAuthenticationAttemptStatus::Completed->value);
});

it('legacy 3ds session without authentication attempt can still complete', function () {
    $calls = [];
    bindFake3dsGateway($calls);
    $user = cardAuthUser();
    $session = Efevoo3dsSession::create([
        'customer_id' => $user->customer->id,
        'order_id' => 'ORDER-LEGACY',
        'card_last_four' => '4242',
        'amount' => 1.5,
        'status' => 'mock_pending',
    ]);

    $this->actingAs($user)->withSession(sensitiveCardSessionPayload($user, $session->id, [
        'card_number' => '4242424242424242',
        'expiration' => '1229',
        'cvv' => '123',
        'amount' => 1.5,
    ]))->getJson(route('payment-methods.3ds-status', $session))->assertOk();

    expect($calls['initiate3DS'] ?? 0)->toBe(0)
        ->and($calls['poll3DSAuthentication'] ?? 0)->toBe(1)
        ->and(PaymentAuthenticationAttempt::count())->toBe(0);
});

it('start dto does not expose sensitive challenge fields by default', function () {
    $user = cardAuthUser();
    $session = Efevoo3dsSession::create([
        'customer_id' => $user->customer->id,
        'order_id' => 'ORDER-DTO',
        'card_last_four' => '4242',
        'amount' => 1.5,
        'status' => 'redirect_required',
        'url_3dsecure' => 'https://issuer.example/challenge',
        'token_3dsecure' => 'secret-creq-token',
    ]);
    $attempt = PaymentAuthenticationAttempt::create([
        'attempt_uuid' => (string) Str::uuid(),
        'support_reference' => 'AUTH-DTO',
        'customer_id' => $user->customer->id,
        'operation_type' => PaymentAuthenticationAttempt::OPERATION_CARD_VERIFICATION_3DS,
        'provider' => PaymentAuthenticationAttempt::PROVIDER_EFEVOOPAY,
        'status' => PaymentAuthenticationAttemptStatus::ChallengeRequired->value,
        'merchant_reference' => 'EFV3DS-DTO',
        'efevoo_3ds_session_id' => $session->id,
        'started_at' => now(),
        'expires_at' => now()->addMinutes(5),
    ]);

    $json = json_encode(PaymentAuthentication3dsStartResource::make($attempt, $session));

    expect($json)->toContain('AUTH-DTO')
        ->not->toContain('secret-creq-token')
        ->not->toContain('url3ds')
        ->not->toContain('token3ds');
});
