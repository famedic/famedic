<?php

use App\Actions\Api\V1\Auth\IssueAkubicaTokenAction;
use App\Contracts\Otp\OtpCodeGenerator;
use App\Enums\P0aOtpPurpose;
use App\Models\OtpChallenge;
use App\Models\OtpStepUpGrant;
use App\Models\User;
use App\Notifications\Api\V1\Auth\AkubicaOtpNotification;
use App\Services\Otp\Delivery\FakeOtpDeliveryProvider;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Laravel\Sanctum\PersonalAccessToken;
use Tests\Support\Otp\FakeOtpCodeGenerator;

beforeEach(function () {
    Carbon::setTestNow(Carbon::parse('2026-07-30 12:00:00', config('app.timezone')));
    Notification::fake();
    Storage::fake();
    disableAllAkubicaOtpFeatures();
});

afterEach(function () {
    Carbon::setTestNow();
    disableAllAkubicaOtpFeatures();
});

test('p0c1 flag off issues akubica token without pat expires_at and legacy ttl', function () {
    $user = User::factory()->withRegularCustomer()->create();
    $payload = app(IssueAkubicaTokenAction::class)($user);

    expect($payload['token_type'])->toBe('Bearer')
        ->and($payload['expires_in'])->toBe(1440 * 60)
        ->and($payload['expires_at'])->toBe(now()->copy()->addMinutes(1440)->utc()->format('Y-m-d\TH:i:s\Z'));

    $pat = PersonalAccessToken::query()->where('name', config('akubica.token_name'))->latest('id')->first();
    expect($pat)->not->toBeNull()
        ->and($pat->expires_at)->toBeNull()
        ->and($pat->name)->toBe('akubica')
        ->and($pat->abilities)->toContain('akubica:auth');
});

test('p0c1 flag on persists expires_at from config ttl not hardcode', function () {
    enableSanctum3hTokenExpiration(180);
    config()->set('otp.p0a.sanctum.target_expiration_minutes', 120);

    $user = User::factory()->withRegularCustomer()->create();
    $payload = app(IssueAkubicaTokenAction::class)($user);

    expect($payload['expires_in'])->toBe(120 * 60)
        ->and($payload['expires_at'])->toBe(now()->copy()->addMinutes(120)->utc()->format('Y-m-d\TH:i:s\Z'));

    $pat = PersonalAccessToken::query()->where('tokenable_id', $user->id)->latest('id')->first();
    expect($pat->expires_at)->not->toBeNull()
        ->and($pat->expires_at->equalTo(now()->copy()->addMinutes(120)))->toBeTrue();
});

test('p0c1 login otp verify uses 180 minute ttl when flag on', function () {
    enableLoginOtpWithFakeDelivery();
    enableSanctum3hTokenExpiration(180);
    app()->instance(OtpCodeGenerator::class, new FakeOtpCodeGenerator('123456'));

    $user = User::factory()->withRegularCustomer()->create([
        'email' => 'p0c1.login@ejemplo.test',
        'phone' => '5533334444',
        'phone_country' => 'MX',
        'phone_verified_at' => now(),
    ]);

    $request = $this->postJson('/api/v1/auth/login/request-code', [
        'phone' => '+52 55 3333 4444',
    ])->assertStatus(202);

    $challengeId = $request->json('data.challenge_id');

    $verify = $this->postJson('/api/v1/auth/login/verify-code', [
        'challenge_id' => $challengeId,
        'code' => '123456',
    ])->assertOk();

    expect($verify->json('data.expires_in'))->toBe(180 * 60)
        ->and($verify->json('data.expires_at'))->toBe(now()->copy()->addMinutes(180)->utc()->format('Y-m-d\TH:i:s\Z'))
        ->and($verify->json('data.token_type'))->toBe('Bearer');

    $pat = PersonalAccessToken::findToken($verify->json('data.token'));
    expect($pat)->not->toBeNull()
        ->and($pat->expires_at)->not->toBeNull()
        ->and($pat->expires_at->equalTo(now()->copy()->addMinutes(180)))->toBeTrue();
});

test('p0c1 legacy login verify uses 180 minute ttl when flag on', function () {
    enableSanctum3hTokenExpiration(180);
    $user = User::factory()->withRegularCustomer()->create(['email' => 'p0c1.legacy@ejemplo.test']);

    \App\Models\OtpCode::query()->create([
        'email' => 'p0c1.legacy@ejemplo.test',
        'purpose' => \App\Models\OtpCode::PURPOSE_AKUBICA_LOGIN,
        'payload' => null,
        'channel' => \App\Models\OtpCode::CHANNEL_EMAIL,
        'code' => Hash::make('654321'),
        'expires_at' => now()->addMinutes(10),
        'attempts' => 0,
        'max_attempts' => 5,
        'status' => \App\Models\OtpCode::STATUS_PENDING,
    ]);

    $verify = $this->postJson('/api/v1/auth/login/verify-code', [
        'email' => 'p0c1.legacy@ejemplo.test',
        'code' => '654321',
    ])->assertOk();

    expect($verify->json('data.expires_in'))->toBe(10800);

    $pat = PersonalAccessToken::findToken($verify->json('data.token'));
    expect($pat->expires_at->equalTo(now()->copy()->addMinutes(180)))->toBeTrue();
});

test('p0c1 register otp verify uses configured ttl when flag on', function () {
    enableRegisterOtpWithoutDelivery();
    enableSanctum3hTokenExpiration(180);
    app()->instance(OtpCodeGenerator::class, new FakeOtpCodeGenerator('111222'));

    $request = $this->postJson('/api/v1/auth/register', [
        'email' => 'p0c1.reg@ejemplo.test',
        'phone' => '+52 55 9876 5432',
        'full_name' => 'Nombre Apellido',
    ])->assertStatus(202);

    $verify = $this->postJson('/api/v1/auth/register/verify-code', [
        'challenge_id' => $request->json('data.challenge_id'),
        'code' => '111222',
    ])->assertOk();

    expect($verify->json('data.expires_in'))->toBe(180 * 60);

    $pat = PersonalAccessToken::findToken($verify->json('data.token'));
    expect($pat->expires_at)->not->toBeNull()
        ->and($pat->expires_at->equalTo(now()->copy()->addMinutes(180)))->toBeTrue();
});

test('p0c1 valid token accesses private endpoint; expired token returns 401', function () {
    enableSanctum3hTokenExpiration(180);
    $user = User::factory()->withRegularCustomer()->create();
    $payload = app(IssueAkubicaTokenAction::class)($user);
    $headers = authHeaders($payload['token']);

    $this->getJson('/api/v1/orders', $headers)->assertOk();

    Carbon::setTestNow(now()->copy()->addMinutes(181));
    Auth::forgetGuards();

    $this->getJson('/api/v1/orders', $headers)
        ->assertUnauthorized()
        ->assertJsonPath('error.code', 'UNAUTHENTICATED');
});

test('p0c1 expired token cannot download bearer or request step-up', function () {
    enableSanctum3hTokenExpiration(180);
    enableBearerStepUpResultsEnforcement();
    enableResultsStepUpWithFakeDelivery();

    $user = User::factory()->withRegularCustomer()->create([
        'phone' => '5512345678',
        'phone_country' => 'MX',
        'phone_verified_at' => now(),
    ]);
    $payload = app(IssueAkubicaTokenAction::class)($user);
    $path = 'results/p0c1-exp.pdf';
    storeFakePdf($path);
    $order = createAkubicaLaboratoryPurchase($user, ['results' => $path]);
    $headers = authHeaders($payload['token']);

    Carbon::setTestNow(now()->copy()->addMinutes(181));
    Auth::forgetGuards();

    $this->getJson("/api/v1/orders/{$order->id}/results/download", $headers)
        ->assertUnauthorized()
        ->assertJsonPath('error.code', 'UNAUTHENTICATED');

    $this->postJson("/api/v1/orders/{$order->id}/results/step-up/request", [], $headers)
        ->assertUnauthorized()
        ->assertJsonPath('error.code', 'UNAUTHENTICATED');
});

test('p0c1 new token cannot use grant bound to previous pat', function () {
    enableSanctum3hTokenExpiration(180);
    enableBearerStepUpResultsEnforcement();

    $user = User::factory()->withRegularCustomer()->create([
        'phone' => '5512345678',
        'phone_country' => 'MX',
        'phone_verified_at' => now(),
    ]);
    $first = app(IssueAkubicaTokenAction::class)($user);
    $firstPat = PersonalAccessToken::findToken($first['token']);
    $path = 'results/p0c1-pat.pdf';
    storeFakePdf($path);
    $order = createAkubicaLaboratoryPurchase($user, ['results' => $path]);

    $challenge = OtpChallenge::query()->create([
        'public_id' => (string) Str::uuid(),
        'user_id' => $user->id,
        'subject_type' => 'phone',
        'subject_key' => 'MX|5512345678',
        'purpose' => P0aOtpPurpose::StepUpResults->value,
        'channel' => 'sms',
        'destination_normalized' => '+525512345678',
        'destination_masked' => '***5678',
        'code_hash' => Hash::make('000000'),
        'expires_at' => now()->addMinutes(5),
        'consumed_at' => now(),
        'failed_attempts' => 0,
        'max_attempts' => 5,
        'send_count' => 1,
        'last_sent_at' => now(),
        'context_type' => OtpStepUpGrant::RESOURCE_LABORATORY_PURCHASE,
        'context_id' => $order->id,
    ]);

    $grant = OtpStepUpGrant::query()->create([
        'public_id' => (string) Str::uuid(),
        'user_id' => $user->id,
        'personal_access_token_id' => $firstPat->id,
        'otp_challenge_id' => $challenge->id,
        'purpose' => P0aOtpPurpose::StepUpResults->value,
        'resource_type' => OtpStepUpGrant::RESOURCE_LABORATORY_PURCHASE,
        'resource_id' => $order->id,
        'granted_at' => now(),
        'expires_at' => now()->addMinutes(10),
        'revoked_at' => null,
    ]);

    $second = app(IssueAkubicaTokenAction::class)($user);
    $headers = authHeaders($second['token']);
    $headers[\App\Services\Otp\StepUp\BearerStepUpEnforcement::HEADER] = $grant->public_id;

    $this->getJson("/api/v1/orders/{$order->id}/results/download", $headers)
        ->assertForbidden()
        ->assertJsonPath('error.code', 'STEP_UP_GRANT_INVALID');
});

test('p0c1 non-akubica createToken is unchanged when flag on', function () {
    enableSanctum3hTokenExpiration(180);
    $user = User::factory()->create();
    $token = $user->createToken('web-session');

    expect($token->accessToken->name)->toBe('web-session')
        ->and($token->accessToken->expires_at)->toBeNull();
});

test('p0c1 flag off does not alter sanctum.expiration config', function () {
    expect(config('otp.p0a.flags.sanctum_3h_enabled'))->toBeFalse()
        ->and((int) config('sanctum.expiration'))->toBe(1440)
        ->and((int) config('otp.p0a.sanctum.target_expiration_minutes'))->toBe(180);

    $user = User::factory()->withRegularCustomer()->create();
    $payload = app(IssueAkubicaTokenAction::class)($user);
    expect($payload['expires_in'])->toBe(1440 * 60);
});
