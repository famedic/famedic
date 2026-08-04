<?php

use App\Contracts\Otp\OtpCodeGenerator;
use App\Models\Api\V1\ApiV1AuditEvent;
use App\Models\OtpChallenge;
use App\Models\OtpDeliveryOperation;
use App\Models\OtpStepUpGrant;
use App\Models\User;
use App\Services\Api\V1\Audit\AuditEventDefinitions;
use App\Services\Api\V1\Audit\AuditEventWriter;
use App\Services\Api\V1\Audit\AuditMetadataNormalizer;
use App\Services\Api\V1\Audit\AuditOutcome;
use App\Services\Api\V1\Idempotency\IdempotencyKey;
use App\Services\Otp\Delivery\FakeOtpDeliveryProvider;
use App\Support\Api\V1\AkubicaCorrelationId;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Schema;
use Tests\Support\Otp\FakeOtpCodeGenerator;

beforeEach(function () {
    Carbon::setTestNow(Carbon::parse('2026-08-03 20:00:00'));
    Notification::fake();
    disableAllAkubicaOtpFeatures();
    config()->set('api_v1.audit.enabled', false);
    config()->set('api_v1.idempotency.enabled', false);
    config()->set('otp.p0a.policy.max_attempts', 5);
    config()->set('otp.p0a.policy.ttl_minutes', 5);
    config()->set('otp.p0a.policy.cooldown_seconds', 60);
});

afterEach(function () {
    Carbon::setTestNow();
    disableAllAkubicaOtpFeatures();
    config()->set('api_v1.audit.enabled', false);
});

function enableAuthOtpAudit(): void
{
    config()->set('api_v1.audit.enabled', true);
    app()->forgetInstance(AuditMetadataNormalizer::class);
    app()->forgetInstance(AuditEventWriter::class);
    app()->forgetInstance(\App\Services\Api\V1\Audit\AuthOtpAuditRecorder::class);
}

function auditAssertNoSecrets(?ApiV1AuditEvent $event = null): void
{
    $rows = $event !== null
        ? [DB::table('api_v1_audit_events')->where('id', $event->id)->first()]
        : DB::table('api_v1_audit_events')->get()->all();

    foreach ($rows as $row) {
        $blob = json_encode($row);
        expect($blob)->not->toContain('123456')
            ->and($blob)->not->toContain('654321')
            ->and($blob)->not->toContain('Bearer')
            ->and($blob)->not->toContain('+5255')
            ->and($blob)->not->toContain('@ejemplo.com')
            ->and($blob)->not->toContain('idem-key-');
    }
}

test('flag OFF login otp works and inserts no audit rows', function () {
    enableLoginOtpWithFakeDelivery();
    $this->app->instance(OtpCodeGenerator::class, new FakeOtpCodeGenerator('123456'));
    User::factory()->create([
        'phone' => '5512345678',
        'phone_country' => 'MX',
        'phone_verified_at' => now(),
    ]);

    $this->postJson('/api/v1/auth/login/request-code', [
        'phone' => '+525512345678',
    ], [AkubicaCorrelationId::HEADER => 'corr-audit-off-login'])
        ->assertStatus(202);

    expect(ApiV1AuditEvent::query()->count())->toBe(0)
        ->and(OtpChallenge::query()->count())->toBe(1);
});

test('login request resend and verify emit audit events with safe metadata', function () {
    enableLoginOtpWithFakeDelivery();
    enableAuthOtpAudit();
    $this->app->instance(OtpCodeGenerator::class, new FakeOtpCodeGenerator('123456'));

    $user = User::factory()->withRegularCustomer()->create([
        'phone' => '5512345678',
        'phone_country' => 'MX',
        'phone_verified_at' => now(),
        'email' => 'audit.login@ejemplo.com',
    ]);

    $corr = 'corr-audit-login-flow-01';
    $headers = [AkubicaCorrelationId::HEADER => $corr];

    $request = $this->postJson('/api/v1/auth/login/request-code', [
        'phone' => '+525512345678',
    ], $headers)->assertStatus(202);

    expect($request->headers->get(AkubicaCorrelationId::HEADER))->toBe($corr);

    $challengeId = $request->json('data.challenge_id');
    $requested = ApiV1AuditEvent::query()
        ->where('event_name', AuditEventDefinitions::EVENT_LOGIN_CODE_REQUESTED)
        ->sole();

    expect($requested->outcome)->toBe(AuditOutcome::SUCCEEDED)
        ->and($requested->http_status)->toBe(202)
        ->and($requested->correlation_id)->toBe($corr)
        ->and($requested->actor_type)->toBe('public')
        ->and($requested->actor_key)->toStartWith('public:')
        ->and($requested->metadata['delivery_channel'])->toBe('sms')
        ->and($requested->metadata['is_decoy'])->toBeFalse()
        ->and($requested->metadata['challenge_row_id'])->toBeInt();

    Carbon::setTestNow(now()->addSeconds(61));
    $this->app->instance(OtpCodeGenerator::class, new FakeOtpCodeGenerator('654321'));

    $this->postJson('/api/v1/auth/login/resend-code', [
        'challenge_id' => $challengeId,
    ], $headers)->assertStatus(202);

    $resent = ApiV1AuditEvent::query()
        ->where('event_name', AuditEventDefinitions::EVENT_LOGIN_CODE_RESENT)
        ->sole();
    expect($resent->outcome)->toBe(AuditOutcome::SUCCEEDED)
        ->and($resent->metadata['is_resend'])->toBeTrue();

    $newChallengeId = OtpChallenge::query()->where('user_id', $user->id)->latest('id')->value('public_id');

    $verify = $this->postJson('/api/v1/auth/login/verify-code', [
        'challenge_id' => $newChallengeId,
        'code' => '654321',
    ], $headers)->assertOk();

    expect($verify->headers->get(AkubicaCorrelationId::HEADER))->toBe($corr);

    $verified = ApiV1AuditEvent::query()
        ->where('event_name', AuditEventDefinitions::EVENT_LOGIN_VERIFIED)
        ->where('outcome', AuditOutcome::SUCCEEDED)
        ->sole();

    expect($verified->actor_type)->toBe('customer')
        ->and($verified->customer_id)->toBe($user->customer->id)
        ->and($verified->user_id)->toBe($user->id)
        ->and($verified->http_status)->toBe(200)
        ->and($verified->metadata['session_issued'])->toBeTrue()
        ->and($verified->personal_access_token_id)->not->toBeNull();

    auditAssertNoSecrets();
    expect(json_encode(ApiV1AuditEvent::query()->get()->toArray()))
        ->not->toContain($verify->json('data.token'));
});

test('login verify with invalid code audits rejected without secrets', function () {
    enableLoginOtpWithFakeDelivery();
    enableAuthOtpAudit();
    $this->app->instance(OtpCodeGenerator::class, new FakeOtpCodeGenerator('123456'));

    User::factory()->create([
        'phone' => '5512345678',
        'phone_country' => 'MX',
        'phone_verified_at' => now(),
    ]);

    $challengeId = $this->postJson('/api/v1/auth/login/request-code', [
        'phone' => '+525512345678',
    ])->json('data.challenge_id');

    $response = $this->postJson('/api/v1/auth/login/verify-code', [
        'challenge_id' => $challengeId,
        'code' => '000000',
    ])->assertStatus(422)
        ->assertJsonPath('error.code', 'INVALID_CODE')
        ->assertJsonPath('error.retryable', false);

    $event = ApiV1AuditEvent::query()
        ->where('event_name', AuditEventDefinitions::EVENT_LOGIN_VERIFIED)
        ->sole();

    expect($event->outcome)->toBe(AuditOutcome::REJECTED)
        ->and($event->error_code)->toBe('INVALID_CODE')
        ->and($event->http_status)->toBe(422)
        ->and($event->retryable)->toBeFalse();

    auditAssertNoSecrets($event);
});

test('registration request resend verify completion audits with public then customer actor', function () {
    enableRegisterOtpWithFakeDelivery();
    enableAuthOtpAudit();
    $this->app->instance(OtpCodeGenerator::class, new FakeOtpCodeGenerator('123456'));

    $corr = 'corr-audit-register-01';
    $payload = [
        'email' => 'audit.register@ejemplo.com',
        'phone' => '+525512349999',
        'full_name' => 'Audit Register',
        'phone_country' => 'MX',
    ];

    $request = $this->postJson('/api/v1/auth/register', $payload, [
        AkubicaCorrelationId::HEADER => $corr,
    ])->assertStatus(202);

    $challengeId = $request->json('data.challenge_id');
    expect(ApiV1AuditEvent::query()->where('event_name', AuditEventDefinitions::EVENT_REGISTRATION_CODE_REQUESTED)->count())->toBe(1);

    Carbon::setTestNow(now()->addSeconds(61));
    $this->app->instance(OtpCodeGenerator::class, new FakeOtpCodeGenerator('654321'));

    $this->postJson('/api/v1/auth/register/resend-code', [
        'challenge_id' => $challengeId,
    ], [AkubicaCorrelationId::HEADER => $corr])->assertStatus(202);

    expect(ApiV1AuditEvent::query()->where('event_name', AuditEventDefinitions::EVENT_REGISTRATION_CODE_RESENT)->count())->toBe(1);

    $freshChallenge = OtpChallenge::query()->latest('id')->value('public_id');

    $this->postJson('/api/v1/auth/register/verify-code', [
        'challenge_id' => $freshChallenge,
        'code' => '654321',
    ], [AkubicaCorrelationId::HEADER => $corr])->assertOk();

    $completed = ApiV1AuditEvent::query()
        ->where('event_name', AuditEventDefinitions::EVENT_REGISTRATION_COMPLETED)
        ->sole();

    expect($completed->outcome)->toBe(AuditOutcome::SUCCEEDED)
        ->and($completed->actor_type)->toBe('customer')
        ->and($completed->customer_id)->not->toBeNull()
        ->and($completed->correlation_id)->toBe($corr)
        ->and($completed->metadata['session_issued'])->toBeTrue();

    auditAssertNoSecrets();
});

test('step-up results and invoices request verify audit with purpose metadata', function () {
    enableResultsStepUpWithFakeDelivery();
    enableAuthOtpAudit();
    $this->app->instance(OtpCodeGenerator::class, new FakeOtpCodeGenerator('123456'));

    $user = User::factory()->withRegularCustomer()->create([
        'phone' => '5512345678',
        'phone_country' => 'MX',
        'phone_verified_at' => now(),
    ]);
    $token = $user->createToken('akubica-test')->plainTextToken;
    $order = createAkubicaLaboratoryPurchase($user);

    $corr = 'corr-audit-stepup-results';
    $headers = array_merge(authHeaders($token), [AkubicaCorrelationId::HEADER => $corr]);

    $req = $this->postJson("/api/v1/orders/{$order->id}/results/step-up/request", [], $headers)
        ->assertStatus(202);
    $challengeId = $req->json('data.challenge_id');

    $requested = ApiV1AuditEvent::query()
        ->where('event_name', AuditEventDefinitions::EVENT_STEP_UP_REQUESTED)
        ->sole();
    expect($requested->actor_type)->toBe('customer')
        ->and($requested->metadata['purpose'])->toBe('results')
        ->and($requested->resource_type)->toBe('laboratory_purchase')
        ->and($requested->resource_key)->toBe((string) $order->id)
        ->and($requested->actor_key)->not->toContain($token);

    $this->postJson("/api/v1/orders/{$order->id}/results/step-up/verify", [
        'challenge_id' => $challengeId,
        'code' => '123456',
    ], $headers)->assertOk();

    $verified = ApiV1AuditEvent::query()
        ->where('event_name', AuditEventDefinitions::EVENT_STEP_UP_VERIFIED)
        ->sole();
    expect($verified->outcome)->toBe(AuditOutcome::SUCCEEDED)
        ->and($verified->metadata['purpose'])->toBe('results')
        ->and($verified->metadata['step_up_row_id'])->toBeInt()
        ->and($verified->metadata)->not->toHaveKey('grant_public_id');

    // Invoices
    enableInvoiceStepUpWithFakeDelivery();
    enableAuthOtpAudit();
    $this->app->instance(OtpCodeGenerator::class, new FakeOtpCodeGenerator('222222'));
    $invoice = createAkubicaLaboratoryInvoice($order);

    $invReq = $this->postJson(
        "/api/v1/orders/{$order->id}/invoices/{$invoice->id}/step-up/request",
        [],
        $headers,
    )->assertStatus(202);

    $invRequested = ApiV1AuditEvent::query()
        ->where('event_name', AuditEventDefinitions::EVENT_STEP_UP_REQUESTED)
        ->where('resource_type', 'invoice')
        ->sole();
    expect($invRequested->metadata['purpose'])->toBe('invoices')
        ->and($invRequested->resource_key)->toBe((string) $invoice->id);

    $this->postJson(
        "/api/v1/orders/{$order->id}/invoices/{$invoice->id}/step-up/verify",
        [
            'challenge_id' => $invReq->json('data.challenge_id'),
            'code' => '222222',
        ],
        $headers,
    )->assertOk();

    expect(ApiV1AuditEvent::query()
        ->where('event_name', AuditEventDefinitions::EVENT_STEP_UP_VERIFIED)
        ->where('resource_type', 'invoice')
        ->count())->toBe(1);

    auditAssertNoSecrets();
});

test('step-up ownership miss audits rejection without foreign resource leak beyond attempted id', function () {
    enableResultsStepUpWithFakeDelivery();
    enableAuthOtpAudit();

    [$user, $token] = akubicaCustomerToken(User::factory()->withRegularCustomer()->create([
        'phone' => '5512345678',
        'phone_country' => 'MX',
        'phone_verified_at' => now(),
    ]));
    $other = User::factory()->withRegularCustomer()->create([
        'phone' => '5588888888',
        'phone_country' => 'MX',
        'phone_verified_at' => now(),
    ]);
    $foreignOrder = createAkubicaLaboratoryPurchase($other);

    $this->postJson(
        "/api/v1/orders/{$foreignOrder->id}/results/step-up/request",
        [],
        authHeaders($token),
    )->assertStatus(404)
        ->assertJsonPath('error.code', 'ORDER_NOT_FOUND');

    $event = ApiV1AuditEvent::query()
        ->where('event_name', AuditEventDefinitions::EVENT_STEP_UP_REQUESTED)
        ->sole();

    expect($event->outcome)->toBe(AuditOutcome::REJECTED)
        ->and($event->error_code)->toBe('ORDER_NOT_FOUND')
        ->and($event->customer_id)->toBe($user->customer->id)
        ->and($event->resource_key)->toBe((string) $foreignOrder->id)
        ->and($event->metadata)->not->toHaveKey('owner_customer_id');
});

test('public login and register purposes produce different actor keys', function () {
    enableAuthOtpAudit();
    $resolver = app(\App\Services\Api\V1\Audit\AuditActorResolver::class);
    $material = 'MX|5512345678';

    $login = $resolver->resolvePublic('login', $material);
    $register = $resolver->resolvePublic('register', $material.'|audit.register@ejemplo.com');

    expect($login->key)->not->toBe($register->key)
        ->and($login->key)->not->toContain('5512345678')
        ->and($register->key)->not->toContain('@ejemplo.com');
});

test('allowlisted auth metadata survives aggressive name redaction', function () {
    $normalizer = new AuditMetadataNormalizer(2048, 2);
    $result = $normalizer->normalize(AuditEventDefinitions::EVENT_STEP_UP_VERIFIED, [
        'purpose' => 'results',
        'delivery_channel' => 'sms',
        'delivery_result_class' => 'accepted',
        'challenge_row_id' => 42,
        'step_up_row_id' => 7,
        'order_row_id' => 9,
        'laboratory_purchase_row_id' => 9,
        'grant_internal_id' => 99, // contains "grant" → stripped by name defense
        'otp' => '123456',
        'phone' => '5512345678',
    ]);

    expect($result)->toMatchArray([
        'purpose' => 'results',
        'delivery_channel' => 'sms',
        'delivery_result_class' => 'accepted',
        'challenge_row_id' => 42,
        'step_up_row_id' => 7,
        'order_row_id' => 9,
        'laboratory_purchase_row_id' => 9,
    ])
        ->and($result)->not->toHaveKey('grant_internal_id')
        ->and($result)->not->toHaveKey('otp')
        ->and($result)->not->toHaveKey('phone');
});

test('broken audit writer does not change login status body or delivery', function () {
    enableLoginOtpWithFakeDelivery();
    enableAuthOtpAudit();
    $this->app->instance(OtpCodeGenerator::class, new FakeOtpCodeGenerator('123456'));

    User::factory()->create([
        'phone' => '5512345678',
        'phone_country' => 'MX',
        'phone_verified_at' => now(),
    ]);

    Schema::rename('api_v1_audit_events', 'api_v1_audit_events_broken');

    try {
        $response = $this->postJson('/api/v1/auth/login/request-code', [
            'phone' => '+525512345678',
        ])->assertStatus(202)
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.requires_otp', true);

        expect(OtpChallenge::query()->count())->toBe(1)
            ->and(OtpDeliveryOperation::query()->count())->toBe(1)
            ->and(count(app(FakeOtpDeliveryProvider::class)->sent))->toBe(1)
            ->and($response->json('data.challenge_id'))->not->toBeEmpty();
    } finally {
        if (Schema::hasTable('api_v1_audit_events_broken')) {
            Schema::rename('api_v1_audit_events_broken', 'api_v1_audit_events');
        }
    }
});

test('idempotent login request-code replay does not duplicate SMS challenge or semantic audit', function () {
    enableLoginOtpWithFakeDelivery();
    enableAuthOtpAudit();
    config()->set('api_v1.idempotency.enabled', true);
    $this->app->instance(OtpCodeGenerator::class, new FakeOtpCodeGenerator('123456'));

    User::factory()->create([
        'phone' => '5512345678',
        'phone_country' => 'MX',
        'phone_verified_at' => now(),
    ]);

    $key = 'idem-key-audit-login-abcdef';
    $payload = ['phone' => '+525512345678'];
    $headers = [
        IdempotencyKey::HEADER => $key,
        AkubicaCorrelationId::HEADER => 'corr-audit-idem-login',
    ];

    $this->postJson('/api/v1/auth/login/request-code', $payload, $headers)
        ->assertStatus(202);

    expect(ApiV1AuditEvent::query()->where('event_name', AuditEventDefinitions::EVENT_LOGIN_CODE_REQUESTED)->count())->toBe(1)
        ->and(OtpChallenge::query()->count())->toBe(1)
        ->and(OtpDeliveryOperation::query()->count())->toBe(1)
        ->and(count(app(FakeOtpDeliveryProvider::class)->sent))->toBe(1);

    $this->postJson('/api/v1/auth/login/request-code', $payload, $headers)
        ->assertStatus(202)
        ->assertHeader('Idempotency-Replayed', 'true');

    expect(ApiV1AuditEvent::query()->where('event_name', AuditEventDefinitions::EVENT_LOGIN_CODE_REQUESTED)->count())->toBe(1)
        ->and(OtpChallenge::query()->count())->toBe(1)
        ->and(OtpDeliveryOperation::query()->count())->toBe(1)
        ->and(count(app(FakeOtpDeliveryProvider::class)->sent))->toBe(1);

    auditAssertNoSecrets();
});

test('auth otp audit does not instrument cart checkout or secure-link routes', function () {
    enableAuthOtpAudit();
    [$user, $token] = akubicaCustomerToken();

    $this->getJson('/api/v1/cart?brand=olab', authHeaders($token))->assertOk();
    $this->getJson('/api/v1/checkout/prepare?brand=olab', authHeaders($token));

    expect(ApiV1AuditEvent::query()->count())->toBe(0);
});

test('rate limit cooldown on login request is audited as rejected retryable', function () {
    enableLoginOtpWithFakeDelivery();
    enableAuthOtpAudit();
    $this->app->instance(OtpCodeGenerator::class, new FakeOtpCodeGenerator('123456'));
    config()->set('otp.p0a.policy.cooldown_seconds', 60);

    User::factory()->create([
        'phone' => '5512345678',
        'phone_country' => 'MX',
        'phone_verified_at' => now(),
    ]);

    $this->postJson('/api/v1/auth/login/request-code', ['phone' => '+525512345678'])
        ->assertStatus(202);

    $second = $this->postJson('/api/v1/auth/login/request-code', ['phone' => '+525512345678'])
        ->assertStatus(429);

    $code = $second->json('error.code');
    expect(in_array($code, ['OTP_COOLDOWN', 'OTP_RATE_LIMITED', 'OTP_BLOCKED'], true))->toBeTrue();

    $event = ApiV1AuditEvent::query()
        ->where('event_name', AuditEventDefinitions::EVENT_LOGIN_CODE_REQUESTED)
        ->where('outcome', AuditOutcome::REJECTED)
        ->sole();

    expect($event->http_status)->toBe(429)
        ->and($event->retryable)->toBeTrue()
        ->and($event->error_code)->toBe($code);
});
