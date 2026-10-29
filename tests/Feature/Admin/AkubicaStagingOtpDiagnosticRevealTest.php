<?php

use App\Models\Api\V1\ApiV1AuditEvent;
use App\Models\OtpChallenge;
use App\Models\OtpStagingDiagnosticRevealAudit;
use App\Models\OtpStagingDiagnosticVault;
use App\Models\Permission;
use App\Models\User;
use App\Services\Otp\AkubicaLoginOtpService;
use App\Services\Otp\Delivery\FakeOtpDeliveryProvider;
use App\Services\Otp\Staging\AkubicaStagingOtpDiagnosticVault;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Tests\Support\Otp\FakeOtpCodeGenerator;

function otpRevealAdminUser(bool $withRevealPermission = true): User
{
    $user = User::factory()->create();
    $admin = \App\Models\Administrator::factory()->create(['user_id' => $user->id]);
    $monitor = Permission::query()->firstOrCreate([
        'name' => 'otp-movements.monitor',
        'guard_name' => 'web',
    ], ['description' => 'Monitorear movimientos OTP de Akúbica']);
    $admin->givePermissionTo($monitor);

    if ($withRevealPermission) {
        $reveal = Permission::query()->firstOrCreate([
            'name' => 'otp-movements.otp-reveal',
            'guard_name' => 'web',
        ], ['description' => 'Revelar OTP login staging']);
        $admin->givePermissionTo($reveal);
    }

    return $user->fresh();
}

function stagingOtpRevealEnabled(): void
{
    app()->detectEnvironment(fn () => 'staging');
    config()->set('otp.staging_diagnostic.code_reveal_enabled', true);
}

function createLoginChallengeForVault(string $plainCode = '654321'): OtpChallenge
{
    $user = User::factory()->create(['phone_verified_at' => now()]);

    $challenge = OtpChallenge::factory()->create([
        'public_id' => (string) Str::uuid(),
        'user_id' => $user->id,
        'purpose' => 'akubica_login',
        'context_type' => AkubicaLoginOtpService::CONTEXT_TYPE,
        'context_id' => $user->id,
        'expires_at' => now()->addMinutes(5),
        'consumed_at' => null,
        'invalidated_at' => null,
    ]);

    app(AkubicaStagingOtpDiagnosticVault::class)->storeLoginChallengeCode($challenge, $plainCode);

    return $challenge;
}

beforeEach(function () {
    Carbon::setTestNow(Carbon::parse('2026-10-21 12:00:00'));
});

afterEach(function () {
    Carbon::setTestNow();
    config()->set('otp.staging_diagnostic.code_reveal_enabled', false);
});

test('staging with flag and permission can reveal a fresh login otp once', function () {
    stagingOtpRevealEnabled();
    $user = otpRevealAdminUser(true);
    $challenge = createLoginChallengeForVault('112233');

    $response = $this->actingAs($user)->postJson(route('admin.otp-movements-monitor.reveal-login-otp'), [
        'challenge_id' => $challenge->public_id,
    ]);

    $response->assertOk()
        ->assertHeader('Cache-Control')
        ->assertJsonPath('data.code', '112233');

    expect(OtpStagingDiagnosticRevealAudit::query()->count())->toBe(1)
        ->and(OtpStagingDiagnosticVault::query()->where('challenge_public_id', $challenge->public_id)->value('code_ciphertext'))->toBe('');

    $this->actingAs($user)->postJson(route('admin.otp-movements-monitor.reveal-login-otp'), [
        'challenge_id' => $challenge->public_id,
    ])->assertStatus(409);
});

test('admin without otp-reveal permission receives 403', function () {
    stagingOtpRevealEnabled();
    $user = otpRevealAdminUser(false);
    $challenge = createLoginChallengeForVault();

    $this->actingAs($user)->postJson(route('admin.otp-movements-monitor.reveal-login-otp'), [
        'challenge_id' => $challenge->public_id,
    ])->assertForbidden();
});

test('feature flag disabled responds 404', function () {
    app()->detectEnvironment(fn () => 'staging');
    config()->set('otp.staging_diagnostic.code_reveal_enabled', false);
    $user = otpRevealAdminUser(true);
    $challenge = createLoginChallengeForVault();

    $this->actingAs($user)->postJson(route('admin.otp-movements-monitor.reveal-login-otp'), [
        'challenge_id' => $challenge->public_id,
    ])->assertNotFound();
});

test('production never reveals even if flag is true', function () {
    app()->detectEnvironment(fn () => 'production');
    config()->set('otp.staging_diagnostic.code_reveal_enabled', true);
    $user = otpRevealAdminUser(true);
    $challenge = createLoginChallengeForVault('998877');

    $this->actingAs($user)->postJson(route('admin.otp-movements-monitor.reveal-login-otp'), [
        'challenge_id' => $challenge->public_id,
    ])->assertNotFound();

    expect(OtpStagingDiagnosticVault::query()->count())->toBe(0);
});

test('otp plain text is not written to application logs on reveal', function () {
    $leaked = false;
    Log::listen(function (string $level, string $message, array $context) use (&$leaked): void {
        $blob = $message.' '.json_encode($context);
        if (str_contains($blob, '445566')) {
            $leaked = true;
        }
    });

    stagingOtpRevealEnabled();
    $user = otpRevealAdminUser(true);
    $challenge = createLoginChallengeForVault('445566');

    $this->actingAs($user)->postJson(route('admin.otp-movements-monitor.reveal-login-otp'), [
        'challenge_id' => $challenge->public_id,
    ])->assertOk();

    expect($leaked)->toBeFalse();
});

test('reveal audit stores metadata without otp value', function () {
    stagingOtpRevealEnabled();
    $user = otpRevealAdminUser(true);
    $challenge = createLoginChallengeForVault('778899');

    $this->actingAs($user)->postJson(route('admin.otp-movements-monitor.reveal-login-otp'), [
        'challenge_id' => $challenge->public_id,
        'correlation_id' => (string) Str::uuid(),
    ])->assertOk();

    $audit = OtpStagingDiagnosticRevealAudit::query()->first();
    expect($audit)->not->toBeNull()
        ->and($audit->challenge_public_id)->toBe($challenge->public_id);

    $serialized = json_encode($audit->toArray());
    expect($serialized)->not->toContain('778899');
});

test('expired challenge cannot be revealed', function () {
    stagingOtpRevealEnabled();
    $user = otpRevealAdminUser(true);
    $challenge = OtpChallenge::factory()->create([
        'public_id' => (string) Str::uuid(),
        'purpose' => 'akubica_login',
        'context_type' => AkubicaLoginOtpService::CONTEXT_TYPE,
        'expires_at' => now()->subMinute(),
    ]);
    OtpStagingDiagnosticVault::query()->create([
        'challenge_public_id' => $challenge->public_id,
        'purpose' => 'akubica_login',
        'code_ciphertext' => encrypt('123456'),
        'expires_at' => now()->subMinute(),
    ]);

    $this->actingAs($user)->postJson(route('admin.otp-movements-monitor.reveal-login-otp'), [
        'challenge_id' => $challenge->public_id,
    ])->assertStatus(410);
});

test('consumed challenge cannot be revealed', function () {
    stagingOtpRevealEnabled();
    $user = otpRevealAdminUser(true);
    $challenge = OtpChallenge::factory()->create([
        'public_id' => (string) Str::uuid(),
        'purpose' => 'akubica_login',
        'context_type' => AkubicaLoginOtpService::CONTEXT_TYPE,
        'expires_at' => now()->addMinutes(5),
        'consumed_at' => now(),
    ]);
    OtpStagingDiagnosticVault::query()->create([
        'challenge_public_id' => $challenge->public_id,
        'purpose' => 'akubica_login',
        'code_ciphertext' => encrypt('123456'),
        'expires_at' => now()->addMinutes(5),
    ]);

    $this->actingAs($user)->postJson(route('admin.otp-movements-monitor.reveal-login-otp'), [
        'challenge_id' => $challenge->public_id,
    ])->assertStatus(410);
});

test('request-code response does not expose otp and vault stores encrypted', function () {
    stagingOtpRevealEnabled();
    enableLoginOtpWithFakeDelivery();
    config()->set('atix.sandbox.keys', ['sandbox-test' => 'sandbox-secret']);
    config()->set('otp.p0a.account_context.ttl_minutes', 10);
    $this->app->instance(\App\Contracts\Otp\OtpCodeGenerator::class, new FakeOtpCodeGenerator('135790'));
    app(FakeOtpDeliveryProvider::class)->alwaysAccept();

    $runId = strtolower(substr(preg_replace('/[^a-z0-9]/i', '', uniqid('', true)), 0, 10));
    $user = User::factory()->withRegularCustomer()->create([
        'phone' => '551'.str_pad((string) random_int(1000000, 9999999), 7, '0', STR_PAD_LEFT),
        'phone_country' => 'MX',
        'phone_verified_at' => now(),
    ]);

    $atixPayload = [
        'phone' => '+52'.$user->getRawOriginal('phone'),
        'conversation_id' => 'conv-'.$runId,
        'channel_event_id' => 'evt-'.$runId,
        'channel' => 'whatsapp',
        'audience' => 'leo',
        'personalization' => ['allowed' => true],
    ];

    $this->postJson('/api/v1/integrations/atix/phone-lookups', $atixPayload, Tests\Support\Atix\AtixSandboxRequestBuilder::headers(
        $atixPayload,
        nonce: 'nonce-'.$runId,
        idempotency: 'idem-'.$runId,
        correlationId: 'corr-'.$runId,
    ))->assertStatus(202);

    $requestCode = $this->postJson('/api/v1/auth/login/request-code', [
        'phone' => $user->getRawOriginal('phone'),
        'conversation_id' => 'conv-'.$runId,
        'channel_event_id' => 'evt-'.$runId,
        'channel' => 'whatsapp',
        'audience' => 'leo',
        'personalization' => ['allowed' => true],
    ], ['Idempotency-Key' => (string) Str::uuid()]);

    $requestCode->assertStatus(202);
    expect(json_encode($requestCode->json()))->not->toContain('135790');

    $challengeId = $requestCode->json('data.challenge_id');
    expect(OtpStagingDiagnosticVault::query()->where('challenge_public_id', $challengeId)->exists())->toBeTrue();
});

test('contextual verify still issues bearer after vault storage', function () {
    stagingOtpRevealEnabled();
    enableLoginOtpWithFakeDelivery();
    $this->app->instance(\App\Contracts\Otp\OtpCodeGenerator::class, new FakeOtpCodeGenerator('246810'));
    app(FakeOtpDeliveryProvider::class)->alwaysAccept();
    config()->set('atix.sandbox.keys', ['sandbox-test' => 'sandbox-secret']);
    config()->set('otp.p0a.account_context.ttl_minutes', 10);
    config()->set('api_v1.audit.enabled', true);

    $runId = strtolower(substr(preg_replace('/[^a-z0-9]/i', '', uniqid('', true)), 0, 10));
    $user = User::factory()->withRegularCustomer()->create([
        'phone' => '552'.str_pad((string) random_int(1000000, 9999999), 7, '0', STR_PAD_LEFT),
        'phone_country' => 'MX',
        'phone_verified_at' => now(),
    ]);

    $atixPayload = [
        'phone' => '+52'.$user->getRawOriginal('phone'),
        'conversation_id' => 'conv-'.$runId,
        'channel_event_id' => 'evt-'.$runId,
        'channel' => 'whatsapp',
        'audience' => 'leo',
        'personalization' => ['allowed' => true],
    ];

    $this->postJson('/api/v1/integrations/atix/phone-lookups', $atixPayload, Tests\Support\Atix\AtixSandboxRequestBuilder::headers(
        $atixPayload,
        nonce: 'nonce-'.$runId,
        idempotency: 'idem-'.$runId,
        correlationId: 'corr-'.$runId,
    ))->assertStatus(202);

    $challenge = $this->postJson('/api/v1/auth/login/request-code', [
        'phone' => $user->getRawOriginal('phone'),
        'conversation_id' => 'conv-'.$runId,
        'channel_event_id' => 'evt-'.$runId,
        'channel' => 'whatsapp',
        'audience' => 'leo',
        'personalization' => ['allowed' => true],
    ], ['Idempotency-Key' => (string) Str::uuid()])->assertStatus(202);

    $verify = $this->postJson('/api/v1/auth/login/verify-code', [
        'challenge_id' => $challenge->json('data.challenge_id'),
        'code' => '246810',
    ]);

    $verify->assertOk()
        ->assertJsonPath('data.token_type', 'Bearer')
        ->assertJsonPath('data.otp_verified', true)
        ->assertJsonPath('data.assurance_level', 'otp_verified');

    $auditBlob = ApiV1AuditEvent::query()->pluck('metadata')->map(fn ($m) => json_encode($m))->implode(' ');
    expect($auditBlob)->not->toContain('246810');
});

test('legacy login path remains available when p0a login disabled', function () {
    disableAllAkubicaOtpFeatures();
    config()->set('otp.staging_diagnostic.code_reveal_enabled', true);
    app()->detectEnvironment(fn () => 'staging');

    $user = User::factory()->withRegularCustomer()->create([
        'email' => 'legacy-'.Str::random(6).'@example.test',
    ]);

    $response = $this->postJson('/api/v1/auth/login/request-code', [
        'email' => $user->email,
    ]);

    $response->assertOk();
    expect(OtpStagingDiagnosticVault::query()->count())->toBe(0);
});
