<?php

use App\Models\OtpCode;
use App\Models\User;
use App\Notifications\Api\V1\Auth\AkubicaOtpNotification;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;

function createAkubicaAuthOtp(string $email, string $purpose, string $plainCode, ?array $payload = null): OtpCode
{
    return OtpCode::query()->create([
        'email' => strtolower($email),
        'purpose' => $purpose,
        'payload' => $payload,
        'channel' => OtpCode::CHANNEL_EMAIL,
        'code' => Hash::make($plainCode),
        'expires_at' => now()->addMinutes(10),
        'attempts' => 0,
        'max_attempts' => 5,
        'status' => OtpCode::STATUS_PENDING,
    ]);
}

// ── Login request-code ──────────────────────────────────────────────────

test('login request-code rejects invalid email with 422', function () {
    $this->postJson('/api/v1/auth/login/request-code', [])
        ->assertUnprocessable()
        ->assertJsonPath('error.code', 'VALIDATION_ERROR');
});

test('login request-code returns generic 200 for nonexistent email without sending mail', function () {
    Notification::fake();

    $this->postJson('/api/v1/auth/login/request-code', [
        'email' => 'noexiste@ejemplo.com',
    ])
        ->assertOk()
        ->assertJson([
            'success' => true,
            'data' => [
                'verification_sent' => true,
                'channel' => 'email',
            ],
        ]);

    Notification::assertNothingSent();
    expect(OtpCode::query()->where('email', 'noexiste@ejemplo.com')->count())->toBe(0);
});

test('login request-code creates OTP and sends notification for existing user', function () {
    Notification::fake();

    $user = User::factory()->create(['email' => 'paciente@ejemplo.com']);

    $this->postJson('/api/v1/auth/login/request-code', [
        'email' => 'paciente@ejemplo.com',
    ])
        ->assertOk()
        ->assertJsonPath('data.verification_sent', true)
        ->assertJsonPath('data.expires_in', 600);

    Notification::assertSentTo($user, AkubicaOtpNotification::class);

    $otp = OtpCode::query()
        ->where('email', 'paciente@ejemplo.com')
        ->where('purpose', OtpCode::PURPOSE_AKUBICA_LOGIN)
        ->first();

    expect($otp)->not->toBeNull();
    expect($otp->code)->not->toBe('123456');
});

// ── Login verify-code ────────────────────────────────────────────────

test('login verify-code returns NO_ACTIVE_CODE when no active OTP exists', function () {
    User::factory()->create(['email' => 'paciente@ejemplo.com']);

    $this->postJson('/api/v1/auth/login/verify-code', [
        'email' => 'paciente@ejemplo.com',
        'code' => '123456',
    ])
        ->assertUnprocessable()
        ->assertJsonPath('error.code', 'NO_ACTIVE_CODE');
});

test('login verify-code returns INVALID_CODE for wrong code', function () {
    User::factory()->create(['email' => 'paciente@ejemplo.com']);
    createAkubicaAuthOtp('paciente@ejemplo.com', OtpCode::PURPOSE_AKUBICA_LOGIN, '654321');

    $this->postJson('/api/v1/auth/login/verify-code', [
        'email' => 'paciente@ejemplo.com',
        'code' => '123456',
    ])
        ->assertUnprocessable()
        ->assertJsonPath('error.code', 'INVALID_CODE');
});

test('login verify-code returns CODE_EXPIRED for expired OTP', function () {
    User::factory()->create(['email' => 'paciente@ejemplo.com']);

    OtpCode::query()->create([
        'email' => 'paciente@ejemplo.com',
        'purpose' => OtpCode::PURPOSE_AKUBICA_LOGIN,
        'channel' => OtpCode::CHANNEL_EMAIL,
        'code' => Hash::make('123456'),
        'expires_at' => now()->subMinute(),
        'attempts' => 0,
        'max_attempts' => 5,
        'status' => OtpCode::STATUS_PENDING,
    ]);

    $this->postJson('/api/v1/auth/login/verify-code', [
        'email' => 'paciente@ejemplo.com',
        'code' => '123456',
    ])
        ->assertUnprocessable()
        ->assertJsonPath('error.code', 'CODE_EXPIRED');
});

test('login verify-code returns ATTEMPTS_EXHAUSTED after max failed attempts', function () {
    User::factory()->create(['email' => 'paciente@ejemplo.com']);

    OtpCode::query()->create([
        'email' => 'paciente@ejemplo.com',
        'purpose' => OtpCode::PURPOSE_AKUBICA_LOGIN,
        'channel' => OtpCode::CHANNEL_EMAIL,
        'code' => Hash::make('123456'),
        'expires_at' => now()->addMinutes(10),
        'attempts' => 5,
        'max_attempts' => 5,
        'status' => OtpCode::STATUS_PENDING,
    ]);

    $this->postJson('/api/v1/auth/login/verify-code', [
        'email' => 'paciente@ejemplo.com',
        'code' => '999999',
    ])
        ->assertUnprocessable()
        ->assertJsonPath('error.code', 'ATTEMPTS_EXHAUSTED');
});

test('login verify-code returns Bearer token for valid code', function () {
    $user = User::factory()->create([
        'email' => 'paciente@ejemplo.com',
        'name' => 'Ana',
        'paternal_lastname' => 'García',
    ]);

    createAkubicaAuthOtp('paciente@ejemplo.com', OtpCode::PURPOSE_AKUBICA_LOGIN, '123456');

    $response = $this->postJson('/api/v1/auth/login/verify-code', [
        'email' => 'paciente@ejemplo.com',
        'code' => '123456',
    ]);

    $response->assertOk()
        ->assertJsonStructure([
            'success',
            'data' => [
                'token',
                'token_type',
                'expires_in',
                'expires_at',
                'user' => ['id', 'email', 'name'],
            ],
        ])
        ->assertJsonPath('data.token_type', 'Bearer')
        ->assertJsonPath('data.user.id', $user->id)
        ->assertJsonPath('data.user.email', 'paciente@ejemplo.com');

    expect($response->json('data.token'))->not->toBeEmpty();
});

// ── Register ───────────────────────────────────────────────────────

test('register rejects invalid data with 422', function () {
    $this->postJson('/api/v1/auth/register', [
        'email' => 'no-es-email',
        'phone' => '',
        'full_name' => 'AB',
    ])
        ->assertUnprocessable()
        ->assertJsonPath('error.code', 'VALIDATION_ERROR');
});

test('register returns 409 EMAIL_ALREADY_REGISTERED for duplicate email', function () {
    User::factory()->create(['email' => 'duplicado@ejemplo.com']);

    $this->postJson('/api/v1/auth/register', [
        'email' => 'duplicado@ejemplo.com',
        'phone' => '+5255512345678',
        'full_name' => 'Nombre Apellido',
    ])
        ->assertStatus(409)
        ->assertJsonPath('error.code', 'EMAIL_ALREADY_REGISTERED');
});

test('register returns 409 PHONE_ALREADY_REGISTERED for duplicate phone', function () {
    User::factory()->withVerifiedPhone()->create([
        'email' => 'otro@ejemplo.com',
        'phone' => '5512345678',
        'phone_country' => 'MX',
    ]);

    $this->postJson('/api/v1/auth/register', [
        'email' => 'nuevo@ejemplo.com',
        'phone' => '+525512345678',
        'full_name' => 'Nombre Apellido',
    ])
        ->assertStatus(409)
        ->assertJsonPath('error.code', 'PHONE_ALREADY_REGISTERED');
});

test('register creates registration OTP for valid data', function () {
    Notification::fake();

    $this->postJson('/api/v1/auth/register', [
        'email' => 'nuevo@ejemplo.com',
        'phone' => '+5255512345678',
        'full_name' => 'Nombre Apellido',
    ])
        ->assertOk()
        ->assertJsonPath('data.verification_sent', true)
        ->assertJsonPath('data.channel', 'email');

    Notification::assertSentOnDemand(AkubicaOtpNotification::class);

    $otp = OtpCode::query()
        ->where('email', 'nuevo@ejemplo.com')
        ->where('purpose', OtpCode::PURPOSE_AKUBICA_REGISTER)
        ->first();

    expect($otp)->not->toBeNull();
    expect($otp->payload)->toMatchArray([
        'email' => 'nuevo@ejemplo.com',
        'full_name' => 'Nombre Apellido',
    ]);
});

// ── Register verify-code ───────────────────────────────────────────

test('register verify-code creates user and customer with Bearer token', function () {
    $payload = [
        'email' => 'registro@ejemplo.com',
        'phone' => '+5255511112222',
        'full_name' => 'María López',
        'phone_country' => 'MX',
    ];

    createAkubicaAuthOtp('registro@ejemplo.com', OtpCode::PURPOSE_AKUBICA_REGISTER, '654321', $payload);

    $response = $this->postJson('/api/v1/auth/register/verify-code', [
        'email' => 'registro@ejemplo.com',
        'code' => '654321',
    ]);

    $response->assertOk()
        ->assertJsonPath('data.token_type', 'Bearer')
        ->assertJsonPath('data.user.email', 'registro@ejemplo.com');

    $user = User::query()->where('email', 'registro@ejemplo.com')->first();
    expect($user)->not->toBeNull();
    expect($user->customer)->not->toBeNull();
    expect($user->email_verified_at)->not->toBeNull();
    expect($response->json())->not->toHaveKey('data.password');
});

test('register verify-code does not reuse consumed OTP', function () {
    $payload = [
        'email' => 'reuso@ejemplo.com',
        'phone' => '+5255511113333',
        'full_name' => 'Pedro Ruiz',
        'phone_country' => 'MX',
    ];

    createAkubicaAuthOtp('reuso@ejemplo.com', OtpCode::PURPOSE_AKUBICA_REGISTER, '111111', $payload);

    $this->postJson('/api/v1/auth/register/verify-code', [
        'email' => 'reuso@ejemplo.com',
        'code' => '111111',
    ])->assertOk();

    $this->postJson('/api/v1/auth/register/verify-code', [
        'email' => 'reuso@ejemplo.com',
        'code' => '111111',
    ])
        ->assertUnprocessable()
        ->assertJsonPath('error.code', 'NO_ACTIVE_CODE');
});

test('registered user can access GET /api/v1/cart with token', function () {
    $payload = [
        'email' => 'carrito@ejemplo.com',
        'phone' => '+5255514445555',
        'full_name' => 'Laura Díaz',
        'phone_country' => 'MX',
    ];

    createAkubicaAuthOtp('carrito@ejemplo.com', OtpCode::PURPOSE_AKUBICA_REGISTER, '222222', $payload);

    $token = $this->postJson('/api/v1/auth/register/verify-code', [
        'email' => 'carrito@ejemplo.com',
        'code' => '222222',
    ])->json('data.token');

    $this->getJson('/api/v1/cart?brand=olab', [
        'Authorization' => 'Bearer '.$token,
    ])
        ->assertOk()
        ->assertJsonPath('success', true)
        ->assertJsonPath('data.brand', 'olab');
});

// ── Token revoke ─────────────────────────────────────────────────

test('DELETE /api/v1/auth/token revokes token without requiring customer', function () {
    $user = User::factory()->create();
    $token = $user->createToken('akubica-test')->plainTextToken;

    $this->deleteJson('/api/v1/auth/token', [], [
        'Authorization' => 'Bearer '.$token,
    ])
        ->assertOk()
        ->assertJsonPath('data.revoked', true);

    $this->flushSession();
    $this->app['auth']->forgetGuards();

    $this->getJson('/api/v1/cart?brand=olab', [
        'Authorization' => 'Bearer '.$token,
    ])->assertUnauthorized();
});

// ── Security ───────────────────────────────────────────────────

test('auth OTP is stored hashed not in plain text', function () {
    Notification::fake();

    User::factory()->create(['email' => 'hash@ejemplo.com']);

    $this->postJson('/api/v1/auth/login/request-code', [
        'email' => 'hash@ejemplo.com',
    ])->assertOk();

    $otp = OtpCode::query()->where('email', 'hash@ejemplo.com')->first();

    expect($otp->code)->not->toMatch('/^\d{6}$/');
    expect(strlen($otp->code))->toBeGreaterThan(20);
});
