<?php

use App\Contracts\Otp\OtpCodeGenerator;
use App\Enums\P0aOtpPurpose;
use App\Models\OtpChallenge;
use App\Models\OtpDeliveryOperation;
use App\Models\OtpStepUpGrant;
use App\Models\User;
use App\Services\Otp\Delivery\FakeOtpDeliveryProvider;
use App\Services\Otp\StepUp\OtpStepUpGrantService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Hash;
use Tests\Support\Otp\FakeOtpCodeGenerator;

function enableAkubicaStepUpResultsFlags(): void
{
    config()->set('otp.p0a.flags.step_up_results_enabled', true);
    config()->set('otp.p0a.flags.anti_abuse_enabled', true);
    config()->set('otp.p0a.flags.sms_delivery_enabled', true);
    config()->set('otp.p0a.flags.email_fallback_enabled', false);
    config()->set('otp.p0a.delivery.driver', 'fake');
    config()->set('otp.p0a.policy.require_verified_phone', true);
    config()->set('otp.p0a.step_up.grant_ttl_minutes', 10);
    config()->set('otp.p0a.step_up.bind_to_sanctum_token', true);
    config()->set('otp.p0a.step_up.bind_to_purpose', true);
    config()->set('otp.p0a.step_up.bind_to_resource', true);
    app(FakeOtpDeliveryProvider::class)->alwaysAccept();
    app(FakeOtpDeliveryProvider::class)->sent = [];
}

function disableAkubicaStepUpResultsFlags(): void
{
    config()->set('otp.p0a.flags.step_up_results_enabled', false);
    config()->set('otp.p0a.flags.anti_abuse_enabled', false);
    config()->set('otp.p0a.flags.sms_delivery_enabled', false);
    config()->set('otp.p0a.delivery.driver', 'null');
}

/**
 * @return array{0: User, 1: string, 2: int}
 */
function stepUpCustomerToken(array $userAttrs = []): array
{
    $user = User::factory()->withRegularCustomer()->create(array_merge([
        'phone' => '5512345678',
        'phone_country' => 'MX',
        'phone_verified_at' => now(),
    ], $userAttrs));

    $newToken = $user->createToken('akubica-test');

    return [$user, $newToken->plainTextToken, (int) $newToken->accessToken->id];
}

beforeEach(function () {
    Carbon::setTestNow(Carbon::parse('2026-07-30 12:00:00'));
    disableAkubicaStepUpResultsFlags();
    config()->set('otp.p0a.policy.max_attempts', 5);
    config()->set('otp.p0a.policy.ttl_minutes', 5);
    config()->set('otp.p0a.policy.cooldown_seconds', 60);
});

afterEach(function () {
    Carbon::setTestNow();
    disableAkubicaStepUpResultsFlags();
});

test('p0b1 flags off returns FEATURE_DISABLED and does not send sms', function () {
    [$user, $token] = stepUpCustomerToken();
    $order = createAkubicaLaboratoryPurchase($user);

    $this->postJson("/api/v1/orders/{$order->id}/results/step-up/request", [], authHeaders($token))
        ->assertStatus(503)
        ->assertJsonPath('error.code', 'FEATURE_DISABLED');

    expect(OtpChallenge::query()->count())->toBe(0)
        ->and(count(app(FakeOtpDeliveryProvider::class)->sent))->toBe(0);
});

test('p0b1 flags off leave bearer result download unchanged', function () {
    [$user, $token] = stepUpCustomerToken();
    $path = 'results/p0b1-unchanged.pdf';
    storeFakePdf($path);
    $order = createAkubicaLaboratoryPurchase($user, ['results' => $path]);

    $this->get("/api/v1/orders/{$order->id}/results/download", authHeaders($token))
        ->assertOk()
        ->assertHeader('Content-Type', 'application/pdf');
});

test('p0b1 owner request sends sms and creates step_up_results challenge', function () {
    enableAkubicaStepUpResultsFlags();
    $this->app->instance(OtpCodeGenerator::class, new FakeOtpCodeGenerator('123456'));

    [$user, $token] = stepUpCustomerToken();
    $order = createAkubicaLaboratoryPurchase($user);

    $response = $this->postJson(
        "/api/v1/orders/{$order->id}/results/step-up/request",
        [],
        authHeaders($token),
    )->assertStatus(202)
        ->assertJsonPath('success', true)
        ->assertJsonPath('data.requires_otp', true)
        ->assertJsonPath('data.purpose', 'step_up_results')
        ->assertJsonPath('data.channel', 'sms')
        ->assertJsonPath('data.resource_type', 'laboratory_purchase')
        ->assertJsonPath('data.resource_id', $order->id)
        ->assertJsonPath('data.destination_masked', '***5678');

    $json = $response->json('data');
    expect($json)->toHaveKeys(['challenge_id', 'expires_at', 'resend_available_at'])
        ->and($json)->not->toHaveKey('token')
        ->and($json)->not->toHaveKey('grant_id')
        ->and(json_encode($json))->not->toContain('123456')
        ->and(json_encode($json))->not->toContain('5512345678')
        ->and(json_encode($json))->not->toContain('+525512345678');

    $challenge = OtpChallenge::query()->where('public_id', $json['challenge_id'])->first();
    expect($challenge)->not->toBeNull()
        ->and($challenge->purpose)->toBe('step_up_results')
        ->and($challenge->context_type)->toBe('laboratory_purchase')
        ->and((int) $challenge->context_id)->toBe($order->id)
        ->and((int) $challenge->user_id)->toBe($user->id)
        ->and(Hash::check('123456', $challenge->code_hash))->toBeTrue()
        ->and(count(app(FakeOtpDeliveryProvider::class)->sent))->toBe(1)
        ->and(app(FakeOtpDeliveryProvider::class)->sent[0]['purpose'])->toBe('step_up_results')
        ->and(OtpDeliveryOperation::query()->where('purpose', 'step_up_results')->count())->toBe(1)
        ->and(OtpStepUpGrant::query()->count())->toBe(0);
});

test('p0b1 third party request returns 404 without sms', function () {
    enableAkubicaStepUpResultsFlags();

    [$owner] = stepUpCustomerToken(['phone' => '5511111111']);
    [$stranger, $tokenB] = stepUpCustomerToken(['phone' => '5522222222']);
    $order = createAkubicaLaboratoryPurchase($owner);

    $this->postJson(
        "/api/v1/orders/{$order->id}/results/step-up/request",
        [],
        authHeaders($tokenB),
    )->assertNotFound()
        ->assertJsonPath('error.code', 'ORDER_NOT_FOUND');

    expect(OtpChallenge::query()->count())->toBe(0)
        ->and(count(app(FakeOtpDeliveryProvider::class)->sent))->toBe(0)
        ->and(OtpStepUpGrant::query()->count())->toBe(0);
});

test('p0b1 correct otp creates grant bound to user token purpose and resource', function () {
    enableAkubicaStepUpResultsFlags();
    $this->app->instance(OtpCodeGenerator::class, new FakeOtpCodeGenerator('654321'));

    [$user, $token, $tokenId] = stepUpCustomerToken();
    $order = createAkubicaLaboratoryPurchase($user);

    $challengeId = $this->postJson(
        "/api/v1/orders/{$order->id}/results/step-up/request",
        [],
        authHeaders($token),
    )->json('data.challenge_id');

    $verify = $this->postJson(
        "/api/v1/orders/{$order->id}/results/step-up/verify",
        ['challenge_id' => $challengeId, 'code' => '654321'],
        authHeaders($token),
    )->assertOk()
        ->assertJsonPath('data.purpose', 'step_up_results')
        ->assertJsonPath('data.resource_type', 'laboratory_purchase')
        ->assertJsonPath('data.resource_id', $order->id);

    $grantId = $verify->json('data.grant_id');
    $expiresAt = $verify->json('data.expires_at');

    expect($grantId)->not->toBeEmpty()
        ->and($verify->json('data'))->not->toHaveKey('token')
        ->and($verify->json('data'))->not->toHaveKey('url')
        ->and(json_encode($verify->json()))->not->toContain('654321');

    $grant = OtpStepUpGrant::query()->where('public_id', $grantId)->first();
    expect($grant)->not->toBeNull()
        ->and((int) $grant->user_id)->toBe($user->id)
        ->and((int) $grant->personal_access_token_id)->toBe($tokenId)
        ->and($grant->purpose)->toBe('step_up_results')
        ->and($grant->resource_type)->toBe('laboratory_purchase')
        ->and((int) $grant->resource_id)->toBe($order->id)
        ->and($grant->expires_at->equalTo(now()->addMinutes(10)))->toBeTrue()
        ->and($expiresAt)->toBe($grant->expires_at->utc()->format('Y-m-d\TH:i:s\Z'));

    $service = app(OtpStepUpGrantService::class);
    expect($service->findValid(
        $grantId,
        $user->id,
        'step_up_results',
        'laboratory_purchase',
        $order->id,
        $tokenId,
    ))->not->toBeNull();
});

test('p0b1 wrong otp does not create grant', function () {
    enableAkubicaStepUpResultsFlags();
    $this->app->instance(OtpCodeGenerator::class, new FakeOtpCodeGenerator('111111'));

    [$user, $token] = stepUpCustomerToken();
    $order = createAkubicaLaboratoryPurchase($user);
    $challengeId = $this->postJson(
        "/api/v1/orders/{$order->id}/results/step-up/request",
        [],
        authHeaders($token),
    )->json('data.challenge_id');

    $this->postJson(
        "/api/v1/orders/{$order->id}/results/step-up/verify",
        ['challenge_id' => $challengeId, 'code' => '000000'],
        authHeaders($token),
    )->assertUnprocessable()
        ->assertJsonPath('error.code', 'INVALID_CODE');

    expect(OtpStepUpGrant::query()->count())->toBe(0);
});

test('p0b1 expired otp does not create grant', function () {
    enableAkubicaStepUpResultsFlags();
    $this->app->instance(OtpCodeGenerator::class, new FakeOtpCodeGenerator('222222'));

    [$user, $token] = stepUpCustomerToken();
    $order = createAkubicaLaboratoryPurchase($user);
    $challengeId = $this->postJson(
        "/api/v1/orders/{$order->id}/results/step-up/request",
        [],
        authHeaders($token),
    )->json('data.challenge_id');

    OtpChallenge::query()->where('public_id', $challengeId)->update([
        'expires_at' => now()->subMinute(),
    ]);

    $this->postJson(
        "/api/v1/orders/{$order->id}/results/step-up/verify",
        ['challenge_id' => $challengeId, 'code' => '222222'],
        authHeaders($token),
    )->assertUnprocessable()
        ->assertJsonPath('error.code', 'CODE_EXPIRED');

    expect(OtpStepUpGrant::query()->count())->toBe(0);
});

test('p0b1 consumed otp cannot create a second grant', function () {
    enableAkubicaStepUpResultsFlags();
    $this->app->instance(OtpCodeGenerator::class, new FakeOtpCodeGenerator('333333'));

    [$user, $token] = stepUpCustomerToken();
    $order = createAkubicaLaboratoryPurchase($user);
    $challengeId = $this->postJson(
        "/api/v1/orders/{$order->id}/results/step-up/request",
        [],
        authHeaders($token),
    )->json('data.challenge_id');

    $this->postJson(
        "/api/v1/orders/{$order->id}/results/step-up/verify",
        ['challenge_id' => $challengeId, 'code' => '333333'],
        authHeaders($token),
    )->assertOk();

    $this->postJson(
        "/api/v1/orders/{$order->id}/results/step-up/verify",
        ['challenge_id' => $challengeId, 'code' => '333333'],
        authHeaders($token),
    )->assertUnprocessable()
        ->assertJsonPath('error.code', 'CODE_ALREADY_USED');

    expect(OtpStepUpGrant::query()->count())->toBe(1);
});

test('p0b1 login challenge cannot be used for step-up verify', function () {
    enableAkubicaStepUpResultsFlags();

    [$user, $token] = stepUpCustomerToken();
    $order = createAkubicaLaboratoryPurchase($user);

    $challenge = OtpChallenge::query()->create([
        'public_id' => (string) Illuminate\Support\Str::uuid(),
        'user_id' => $user->id,
        'subject_type' => 'phone',
        'subject_key' => 'MX|5512345678',
        'purpose' => P0aOtpPurpose::AkubicaLogin->value,
        'channel' => 'sms',
        'destination_normalized' => '+525512345678',
        'destination_masked' => '***5678',
        'code_hash' => Hash::make('444444'),
        'expires_at' => now()->addMinutes(5),
        'failed_attempts' => 0,
        'max_attempts' => 5,
        'send_count' => 1,
        'last_sent_at' => now(),
        'context_type' => 'akubica_login',
        'context_id' => $user->id,
    ]);

    $this->postJson(
        "/api/v1/orders/{$order->id}/results/step-up/verify",
        ['challenge_id' => $challenge->public_id, 'code' => '444444'],
        authHeaders($token),
    )->assertUnprocessable()
        ->assertJsonPath('error.code', 'INVALID_CODE');

    expect(OtpStepUpGrant::query()->count())->toBe(0);
});

test('p0b1 register challenge cannot be used for step-up verify', function () {
    enableAkubicaStepUpResultsFlags();

    [$user, $token] = stepUpCustomerToken();
    $order = createAkubicaLaboratoryPurchase($user);

    $challenge = OtpChallenge::query()->create([
        'public_id' => (string) Illuminate\Support\Str::uuid(),
        'user_id' => $user->id,
        'subject_type' => 'phone',
        'subject_key' => 'MX|5512345678',
        'purpose' => P0aOtpPurpose::AkubicaRegister->value,
        'channel' => 'sms',
        'destination_normalized' => '+525512345678',
        'destination_masked' => '***5678',
        'code_hash' => Hash::make('555555'),
        'expires_at' => now()->addMinutes(5),
        'failed_attempts' => 0,
        'max_attempts' => 5,
        'send_count' => 1,
        'last_sent_at' => now(),
        'context_type' => 'akubica_register',
        'context_id' => $user->id,
    ]);

    $this->postJson(
        "/api/v1/orders/{$order->id}/results/step-up/verify",
        ['challenge_id' => $challenge->public_id, 'code' => '555555'],
        authHeaders($token),
    )->assertUnprocessable()
        ->assertJsonPath('error.code', 'INVALID_CODE');

    expect(OtpStepUpGrant::query()->count())->toBe(0);
});

test('p0b1 challenge for another order cannot verify', function () {
    enableAkubicaStepUpResultsFlags();
    $this->app->instance(OtpCodeGenerator::class, new FakeOtpCodeGenerator('666666'));

    [$user, $token] = stepUpCustomerToken();
    $orderA = createAkubicaLaboratoryPurchase($user);
    $orderB = createAkubicaLaboratoryPurchase($user);

    $challengeId = $this->postJson(
        "/api/v1/orders/{$orderA->id}/results/step-up/request",
        [],
        authHeaders($token),
    )->json('data.challenge_id');

    $this->postJson(
        "/api/v1/orders/{$orderB->id}/results/step-up/verify",
        ['challenge_id' => $challengeId, 'code' => '666666'],
        authHeaders($token),
    )->assertUnprocessable()
        ->assertJsonPath('error.code', 'INVALID_CODE');

    expect(OtpStepUpGrant::query()->count())->toBe(0);
});

test('p0b1 invoices purpose challenge cannot verify results step-up', function () {
    enableAkubicaStepUpResultsFlags();

    [$user, $token] = stepUpCustomerToken();
    $order = createAkubicaLaboratoryPurchase($user);

    $challenge = OtpChallenge::query()->create([
        'public_id' => (string) Illuminate\Support\Str::uuid(),
        'user_id' => $user->id,
        'subject_type' => 'phone',
        'subject_key' => 'MX|5512345678',
        'purpose' => P0aOtpPurpose::StepUpInvoices->value,
        'channel' => 'sms',
        'destination_normalized' => '+525512345678',
        'destination_masked' => '***5678',
        'code_hash' => Hash::make('777777'),
        'expires_at' => now()->addMinutes(5),
        'failed_attempts' => 0,
        'max_attempts' => 5,
        'send_count' => 1,
        'last_sent_at' => now(),
        'context_type' => 'laboratory_purchase',
        'context_id' => $order->id,
    ]);

    $this->postJson(
        "/api/v1/orders/{$order->id}/results/step-up/verify",
        ['challenge_id' => $challenge->public_id, 'code' => '777777'],
        authHeaders($token),
    )->assertUnprocessable()
        ->assertJsonPath('error.code', 'INVALID_CODE');

    expect(OtpStepUpGrant::query()->count())->toBe(0);
});

test('p0b1 grant ttl uses configured minutes', function () {
    enableAkubicaStepUpResultsFlags();
    config()->set('otp.p0a.step_up.grant_ttl_minutes', 7);
    $this->app->instance(OtpCodeGenerator::class, new FakeOtpCodeGenerator('888888'));

    [$user, $token] = stepUpCustomerToken();
    $order = createAkubicaLaboratoryPurchase($user);
    $challengeId = $this->postJson(
        "/api/v1/orders/{$order->id}/results/step-up/request",
        [],
        authHeaders($token),
    )->json('data.challenge_id');

    $expiresAt = $this->postJson(
        "/api/v1/orders/{$order->id}/results/step-up/verify",
        ['challenge_id' => $challengeId, 'code' => '888888'],
        authHeaders($token),
    )->json('data.expires_at');

    expect($expiresAt)->toBe(now()->addMinutes(7)->utc()->format('Y-m-d\TH:i:s\Z'));
});

test('p0b1 different sanctum token cannot use grant', function () {
    enableAkubicaStepUpResultsFlags();
    $this->app->instance(OtpCodeGenerator::class, new FakeOtpCodeGenerator('999999'));

    [$user, $tokenA, $tokenIdA] = stepUpCustomerToken();
    $order = createAkubicaLaboratoryPurchase($user);
    $challengeId = $this->postJson(
        "/api/v1/orders/{$order->id}/results/step-up/request",
        [],
        authHeaders($tokenA),
    )->json('data.challenge_id');

    $grantId = $this->postJson(
        "/api/v1/orders/{$order->id}/results/step-up/verify",
        ['challenge_id' => $challengeId, 'code' => '999999'],
        authHeaders($tokenA),
    )->json('data.grant_id');

    $tokenB = $user->createToken('akubica-other');
    $tokenIdB = (int) $tokenB->accessToken->id;

    $service = app(OtpStepUpGrantService::class);
    expect($service->findValid(
        $grantId,
        $user->id,
        'step_up_results',
        'laboratory_purchase',
        $order->id,
        $tokenIdA,
    ))->not->toBeNull()
        ->and($service->findValid(
            $grantId,
            $user->id,
            'step_up_results',
            'laboratory_purchase',
            $order->id,
            $tokenIdB,
        ))->toBeNull()
        ->and($service->findActiveForBinding(
            $user->id,
            'step_up_results',
            'laboratory_purchase',
            $order->id,
            $tokenIdB,
        ))->toBeNull();
});

test('p0b1 expired grant is invalid', function () {
    enableAkubicaStepUpResultsFlags();
    $this->app->instance(OtpCodeGenerator::class, new FakeOtpCodeGenerator('121212'));

    [$user, $token, $tokenId] = stepUpCustomerToken();
    $order = createAkubicaLaboratoryPurchase($user);
    $challengeId = $this->postJson(
        "/api/v1/orders/{$order->id}/results/step-up/request",
        [],
        authHeaders($token),
    )->json('data.challenge_id');

    $grantId = $this->postJson(
        "/api/v1/orders/{$order->id}/results/step-up/verify",
        ['challenge_id' => $challengeId, 'code' => '121212'],
        authHeaders($token),
    )->json('data.grant_id');

    OtpStepUpGrant::query()->where('public_id', $grantId)->update([
        'expires_at' => now()->subMinute(),
    ]);

    $service = app(OtpStepUpGrantService::class);
    expect($service->findValid(
        $grantId,
        $user->id,
        'step_up_results',
        'laboratory_purchase',
        $order->id,
        $tokenId,
    ))->toBeNull();
});

test('p0b1 revoked grant is invalid', function () {
    enableAkubicaStepUpResultsFlags();
    $this->app->instance(OtpCodeGenerator::class, new FakeOtpCodeGenerator('131313'));

    [$user, $token, $tokenId] = stepUpCustomerToken();
    $order = createAkubicaLaboratoryPurchase($user);
    $challengeId = $this->postJson(
        "/api/v1/orders/{$order->id}/results/step-up/request",
        [],
        authHeaders($token),
    )->json('data.challenge_id');

    $grantId = $this->postJson(
        "/api/v1/orders/{$order->id}/results/step-up/verify",
        ['challenge_id' => $challengeId, 'code' => '131313'],
        authHeaders($token),
    )->json('data.grant_id');

    $service = app(OtpStepUpGrantService::class);
    $service->revokeByPublicId($grantId);

    expect($service->findValid(
        $grantId,
        $user->id,
        'step_up_results',
        'laboratory_purchase',
        $order->id,
        $tokenId,
    ))->toBeNull()
        ->and(OtpStepUpGrant::query()->where('public_id', $grantId)->value('revoked_at'))
        ->not->toBeNull();
});

test('p0b1 request rejects client phone and purpose fields', function () {
    enableAkubicaStepUpResultsFlags();
    $this->app->instance(OtpCodeGenerator::class, new FakeOtpCodeGenerator('141414'));

    [$user, $token] = stepUpCustomerToken();
    $order = createAkubicaLaboratoryPurchase($user);

    $this->postJson(
        "/api/v1/orders/{$order->id}/results/step-up/request",
        [
            'phone' => '5587654321',
            'purpose' => 'akubica_login',
            'user_id' => 999,
        ],
        authHeaders($token),
    )->assertUnprocessable();

    expect(OtpChallenge::query()->count())->toBe(0)
        ->and(count(app(FakeOtpDeliveryProvider::class)->sent))->toBe(0);
});

test('p0b1 verify response does not expose pii otp or storage paths', function () {
    enableAkubicaStepUpResultsFlags();
    $this->app->instance(OtpCodeGenerator::class, new FakeOtpCodeGenerator('151515'));

    [$user, $token] = stepUpCustomerToken(['email' => 'privado@ejemplo.com']);
    $path = 'results/secret-internal.pdf';
    storeFakePdf($path);
    $order = createAkubicaLaboratoryPurchase($user, ['results' => $path]);

    $challengeId = $this->postJson(
        "/api/v1/orders/{$order->id}/results/step-up/request",
        [],
        authHeaders($token),
    )->json('data.challenge_id');

    $body = json_encode($this->postJson(
        "/api/v1/orders/{$order->id}/results/step-up/verify",
        ['challenge_id' => $challengeId, 'code' => '151515'],
        authHeaders($token),
    )->assertOk()->json());

    expect($body)->not->toContain('151515')
        ->and($body)->not->toContain('5512345678')
        ->and($body)->not->toContain('privado@ejemplo.com')
        ->and($body)->not->toContain($path)
        ->and($body)->not->toContain('secret-internal');
});

test('p0b1 other user cannot validate foreign grant', function () {
    enableAkubicaStepUpResultsFlags();
    $this->app->instance(OtpCodeGenerator::class, new FakeOtpCodeGenerator('161616'));

    [$userA, $tokenA, $tokenIdA] = stepUpCustomerToken(['phone' => '5533333333']);
    [$userB, , $tokenIdB] = stepUpCustomerToken(['phone' => '5544444444']);
    $order = createAkubicaLaboratoryPurchase($userA);

    $challengeId = $this->postJson(
        "/api/v1/orders/{$order->id}/results/step-up/request",
        [],
        authHeaders($tokenA),
    )->json('data.challenge_id');

    $grantId = $this->postJson(
        "/api/v1/orders/{$order->id}/results/step-up/verify",
        ['challenge_id' => $challengeId, 'code' => '161616'],
        authHeaders($tokenA),
    )->json('data.grant_id');

    $service = app(OtpStepUpGrantService::class);
    expect($service->findValid(
        $grantId,
        $userB->id,
        'step_up_results',
        'laboratory_purchase',
        $order->id,
        $tokenIdB,
    ))->toBeNull()
        ->and($service->matchesBinding(
            OtpStepUpGrant::query()->where('public_id', $grantId)->first(),
            $userB->id,
            'step_up_results',
            'laboratory_purchase',
            $order->id,
            $tokenIdA,
        ))->toBeFalse();
});
