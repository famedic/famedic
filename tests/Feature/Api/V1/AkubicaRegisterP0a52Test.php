<?php

use App\Http\Requests\Api\V1\Auth\SecureRegisterRequest;
use App\Http\Requests\Api\V1\Auth\SecureRegisterResendCodeRequest;
use App\Http\Requests\Api\V1\Auth\SecureRegisterVerifyCodeRequest;
use App\Models\OtpChallenge;
use App\Models\OtpCode;
use App\Models\User;
use App\Notifications\Api\V1\Auth\AkubicaOtpNotification;
use App\Services\Otp\Registration\AkubicaRegistrationPolicy;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Validator;
use Laravel\Sanctum\PersonalAccessToken;

beforeEach(function () {
    Notification::fake();
    config()->set('otp.p0a.flags.akubica_register_enabled', false);
});

test('p0a52 flags off keep legacy register contract including 409 enumeration', function () {
    expect(AkubicaRegistrationPolicy::isEnabled())->toBeFalse();

    User::factory()->create(['email' => 'ya@ejemplo.com']);

    $this->postJson('/api/v1/auth/register', [
        'email' => 'ya@ejemplo.com',
        'phone' => '+5255512345678',
        'full_name' => 'Nombre Apellido',
    ])
        ->assertStatus(409)
        ->assertJsonPath('error.code', 'EMAIL_ALREADY_REGISTERED');
});

test('p0a52 flags off keep legacy register otp and notification path', function () {
    $this->postJson('/api/v1/auth/register', [
        'email' => 'nuevo.p0a52@ejemplo.com',
        'phone' => '+5255512345601',
        'full_name' => 'Nombre Apellido',
    ])
        ->assertOk()
        ->assertJsonPath('data.verification_sent', true)
        ->assertJsonPath('data.channel', 'email');

    Notification::assertSentOnDemand(AkubicaOtpNotification::class);
    expect(OtpCode::query()->where('email', 'nuevo.p0a52@ejemplo.com')->count())->toBe(1)
        ->and(OtpChallenge::query()->count())->toBe(0)
        ->and(User::query()->where('email', 'nuevo.p0a52@ejemplo.com')->exists())->toBeFalse();
});

test('p0a52 register resend route is inert feature disabled', function () {
    $this->postJson('/api/v1/auth/register/resend-code', [
        'challenge_id' => '00000000-0000-4000-8000-000000000001',
    ])
        ->assertStatus(503)
        ->assertJsonPath('error.code', 'FEATURE_DISABLED');

    expect(OtpChallenge::query()->count())->toBe(0)
        ->and(PersonalAccessToken::query()->count())->toBe(0);
});

test('p0a52 secure verify request rejects identity substitution fields', function () {
    $request = SecureRegisterVerifyCodeRequest::create('/api/v1/auth/register/verify-code', 'POST', [
        'challenge_id' => '00000000-0000-4000-8000-000000000002',
        'code' => '123456',
        'email' => 'atacante@ejemplo.com',
        'phone' => '5511119999',
        'user_id' => 99,
        'purpose' => 'akubica_login',
    ]);
    $request->setContainer(app())->setRedirector(app('redirect'));

    $validator = Validator::make($request->all(), $request->rules());
    $request->setValidator($validator);
    $request->withValidator($validator);

    expect($validator->fails())->toBeTrue()
        ->and($validator->errors()->has('email'))->toBeTrue()
        ->and($validator->errors()->has('phone'))->toBeTrue()
        ->and($validator->errors()->has('user_id'))->toBeTrue()
        ->and($validator->errors()->has('purpose'))->toBeTrue();
});

test('p0a52 secure resend request rejects identity and code fields', function () {
    $request = SecureRegisterResendCodeRequest::create('/api/v1/auth/register/resend-code', 'POST', [
        'challenge_id' => '00000000-0000-4000-8000-000000000003',
        'email' => 'atacante@ejemplo.com',
        'code' => '123456',
    ]);
    $request->setContainer(app())->setRedirector(app('redirect'));

    $validator = Validator::make($request->all(), $request->rules());
    $request->setValidator($validator);
    $request->withValidator($validator);

    expect($validator->fails())->toBeTrue()
        ->and($validator->errors()->has('email'))->toBeTrue()
        ->and($validator->errors()->has('code'))->toBeTrue();
});

test('p0a52 secure register request accepts minimal creation fields', function () {
    $request = SecureRegisterRequest::create('/api/v1/auth/register', 'POST', [
        'email' => '  Nuevo.Seguro@Ejemplo.com ',
        'phone' => '+52 55 1234 5602',
        'full_name' => 'Nombre Apellido',
    ]);
    $request->setContainer(app())->setRedirector(app('redirect'));

    // Trigger FormRequest lifecycle without hitting a route.
    $request->setMethod('POST');
    app()->instance('request', $request);

    $form = SecureRegisterRequest::createFrom($request);
    $form->setContainer(app())->setRedirector(app('redirect'));
    $form->validateResolved();

    $identity = $form->registrationIdentity();
    expect($identity->email->value())->toBe('nuevo.seguro@ejemplo.com')
        ->and($identity->phone->nationalNumber())->toBe('5512345602')
        ->and($identity->fullName)->toBe('Nombre Apellido');
});

test('p0a52 secure verify request rejects invalid uuid and code length', function () {
    $request = SecureRegisterVerifyCodeRequest::create('/', 'POST', [
        'challenge_id' => 'not-a-uuid',
        'code' => '12',
    ]);
    $validator = Validator::make($request->all(), $request->rules());

    expect($validator->fails())->toBeTrue()
        ->and($validator->errors()->has('challenge_id'))->toBeTrue()
        ->and($validator->errors()->has('code'))->toBeTrue();
});

test('p0a52 does not create accounts challenges intents or tokens by itself', function () {
    $users = User::query()->count();
    $codes = OtpCode::query()->count();
    $challenges = OtpChallenge::query()->count();
    $tokens = PersonalAccessToken::query()->count();

    expect(AkubicaRegistrationPolicy::isEnabled())->toBeFalse()
        ->and(AkubicaRegistrationPolicy::isPatientReady())->toBeFalse()
        ->and(User::query()->count())->toBe($users)
        ->and(OtpCode::query()->count())->toBe($codes)
        ->and(OtpChallenge::query()->count())->toBe($challenges)
        ->and(PersonalAccessToken::query()->count())->toBe($tokens);
});
