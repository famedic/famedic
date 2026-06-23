<?php

use App\Enums\OtpPurpose;
use App\Mail\CouponCreatedAuthorizerMail;
use App\Models\Administrator;
use App\Models\Coupon;
use App\Models\OtpCode;
use App\Models\Permission;
use App\Models\User;
use App\Notifications\CouponCreationOtpNotification;
use App\Services\AdminOtpService;
use App\Services\CouponAssignOtpService;
use App\Services\CouponCreatedAuthorizerNotifier;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

function makeCouponCreatorUser(?string $email = null): User
{
    $user = User::factory()->create([
        'email' => $email ?? 'creator-'.uniqid('', true).'@famedic.test',
    ]);

    $admin = Administrator::factory()->create(['user_id' => $user->id]);

    $permission = Permission::query()->firstOrCreate([
        'name' => 'cupones.create',
        'guard_name' => 'web',
    ]);
    $admin->givePermissionTo($permission);

    return $user->fresh();
}

function makeAuthorizerUser(?string $email = null): User
{
    $user = User::factory()->create([
        'email' => $email ?? 'autorizador-'.uniqid('', true).'@famedic.test',
    ]);

    Administrator::factory()
        ->withRole('autorizador')
        ->create(['user_id' => $user->id]);

    return $user->fresh();
}

function newCouponAssignPayload(): array
{
    return [
        'coupon_mode' => 'new',
        'assignment_mode' => 'none',
        'amount_cents' => 50000,
        'type' => 'balance',
        'code' => null,
        'description' => 'Crédito de prueba OTP',
        'is_active' => true,
        'send_notification' => true,
        'send_notifications' => true,
        'authorizer_ids' => [],
        'max_beneficiaries' => null,
        'validity_mode' => 'open',
        'minimum_purchase_mode' => 'none',
    ];
}

beforeEach(function () {
    config(['coupons.creation_otp_required' => true]);
    Cache::flush();
});

test('el servicio OTP de cupones está habilitado por configuración', function () {
    expect(app(CouponAssignOtpService::class)->isRequired())->toBeTrue();
});

test('crear cupón nuevo exige token OTP cuando está habilitado', function () {
    $user = makeCouponCreatorUser();

    $this->withoutMiddleware();

    $response = $this->actingAs($user)
        ->from(route('admin.coupons.assign'))
        ->post(route('admin.coupons.assign.store'), newCouponAssignPayload());

    $response->assertInvalid(['otp_verification_token']);
    expect(Coupon::query()->count())->toBe(0);
});

test('flujo OTP completo permite crear cupón y notifica autorizadores', function () {
    Mail::fake();
    Notification::fake();

    $creator = makeCouponCreatorUser();
    makeAuthorizerUser();

    $payload = newCouponAssignPayload();
    $service = app(CouponAssignOtpService::class);

    $send = $service->send($creator, OtpCode::CHANNEL_EMAIL, $payload);
    expect($send['challenge_id'])->not->toBeEmpty();

    Notification::assertSentTo($creator, CouponCreationOtpNotification::class);

    $plainOtp = null;
    Notification::assertSentTo($creator, CouponCreationOtpNotification::class, function (CouponCreationOtpNotification $notification) use (&$plainOtp) {
        $plainOtp = $notification->otp;

        return strlen($plainOtp) === 6;
    });

    $verified = $service->verify($creator, $send['challenge_id'], $plainOtp);
    $token = $verified['verification_token'];
    expect($token)->not->toBeEmpty();

    $this->withoutMiddleware();
    $assignResponse = $this->actingAs($creator)
        ->from(route('admin.coupons.assign'))
        ->post(route('admin.coupons.assign.store'), [
            ...$payload,
            'otp_verification_token' => $token,
        ]);

    $assignResponse->assertRedirect();
    expect(Coupon::query()->count())->toBe(1);

    Mail::assertSent(CouponCreatedAuthorizerMail::class, 1);
});

test('OTP inválido rechaza verificación', function () {
    $creator = makeCouponCreatorUser();
    $service = app(CouponAssignOtpService::class);
    $payload = newCouponAssignPayload();

    $send = $service->send($creator, OtpCode::CHANNEL_EMAIL, $payload);

    expect(fn () => $service->verify($creator, $send['challenge_id'], '000000'))
        ->toThrow(DomainException::class, 'Código incorrecto.');

    expect(Coupon::query()->count())->toBe(0);
});

test('OTP verificado no puede reutilizarse para crear otro cupón', function () {
    Notification::fake();
    Mail::fake();

    $creator = makeCouponCreatorUser();
    makeAuthorizerUser();
    $service = app(CouponAssignOtpService::class);
    $payload = newCouponAssignPayload();

    $plainOtp = null;
    Notification::fake();
    $send = $service->send($creator, OtpCode::CHANNEL_EMAIL, $payload);

    Notification::assertSentTo($creator, CouponCreationOtpNotification::class, function (CouponCreationOtpNotification $notification) use (&$plainOtp) {
        $plainOtp = $notification->otp;

        return true;
    });

    $verified = $service->verify($creator, $send['challenge_id'], $plainOtp);
    $token = $verified['verification_token'];

    $this->withoutMiddleware();
    $this->actingAs($creator)
        ->from(route('admin.coupons.assign'))
        ->post(route('admin.coupons.assign.store'), [
            ...$payload,
            'otp_verification_token' => $token,
        ]);

    try {
        $this->actingAs($creator)
            ->from(route('admin.coupons.assign'))
            ->post(route('admin.coupons.assign.store'), [
                ...$payload,
                'otp_verification_token' => $token,
            ]);
    } catch (ValidationException|DomainException $e) {
        expect(true)->toBeTrue();
    }

    expect(Coupon::query()->count())->toBe(1);
});

test('hash OTP coincide con beneficiarios y tipos mixtos del request', function () {
    $service = app(CouponAssignOtpService::class);

    $otpPayload = [
        'coupon_mode' => 'new',
        'assignment_mode' => 'individual',
        'amount_cents' => 10000,
        'type' => 'balance',
        'description' => 'Crédito prueba',
        'is_active' => true,
        'send_notification' => true,
        'send_notifications' => true,
        'authorizer_ids' => ['2', '1'],
        'validity_mode' => 'open',
        'minimum_purchase_mode' => 'none',
        'max_beneficiaries' => 1,
        'beneficiary_rows' => [
            [
                'email' => 'Paciente@Example.com',
                'first_name' => 'María',
                'paternal_lastname' => 'López',
                'maternal_lastname' => 'García',
                'credit_type' => 'balance',
            ],
        ],
        'beneficiary_source' => 'manual',
        'bulk_emails' => ['paciente@example.com'],
    ];

    $requestPayload = [
        'coupon_mode' => 'new',
        'assignment_mode' => 'individual',
        'amount_cents' => '10000',
        'type' => 'balance',
        'description' => 'Crédito prueba',
        'is_active' => '1',
        'send_notification' => '1',
        'send_notifications' => '1',
        'authorizer_ids' => [1, 2],
        'validity_mode' => 'open',
        'minimum_purchase_mode' => 'none',
        'max_beneficiaries' => '1',
        'beneficiary_rows' => [
            [
                'email' => 'paciente@example.com',
                'first_name' => 'María',
                'paternal_lastname' => 'López',
                'maternal_lastname' => 'García',
                'credit_type' => 'balance',
            ],
        ],
        'beneficiary_source' => 'manual',
        'bulk_emails' => ['Paciente@Example.com'],
    ];

    expect($service->hashPayload($otpPayload))->toBe($service->hashPayload($requestPayload));

    $request = \Illuminate\Http\Request::create('/admin/coupons/assign', 'POST', $requestPayload);
    expect($service->hashPayload($otpPayload))->toBe(
        $service->hashPayload($service->assignPayloadFromRequest($request)),
    );
});
    $creator = makeCouponCreatorUser();

    $labOtp = app(AdminOtpService::class)->issue(
        userId: (int) $creator->id,
        purpose: OtpPurpose::LabResults,
        channel: OtpCode::CHANNEL_EMAIL,
        challengeId: (string) Str::uuid(),
        laboratoryPurchaseId: null,
    );

    $service = app(CouponAssignOtpService::class);
    $challengeId = (string) Str::uuid();

    Cache::put('coupon_assign_draft:'.$challengeId, [
        'user_id' => $creator->id,
        'payload' => $service->normalizePayload(newCouponAssignPayload()),
        'payload_hash' => $service->hashPayload(newCouponAssignPayload()),
    ], now()->addMinutes(10));

    expect(fn () => $service->verify($creator, $challengeId, $labOtp['plain_code']))
        ->toThrow(DomainException::class);
});

test('OTP de otro usuario no permite crear cupón', function () {
    Notification::fake();

    $creator = makeCouponCreatorUser();
    $other = makeCouponCreatorUser();
    $service = app(CouponAssignOtpService::class);
    $payload = newCouponAssignPayload();

    $plainOtp = null;
    $send = $service->send($creator, OtpCode::CHANNEL_EMAIL, $payload);

    Notification::assertSentTo($creator, CouponCreationOtpNotification::class, function (CouponCreationOtpNotification $notification) use (&$plainOtp) {
        $plainOtp = $notification->otp;

        return true;
    });

    expect(fn () => $service->verify($other, $send['challenge_id'], $plainOtp))
        ->toThrow(DomainException::class);
});

test('reenvío OTP respeta rate limit', function () {
    Notification::fake();
    $creator = makeCouponCreatorUser();
    $service = app(CouponAssignOtpService::class);
    $payload = newCouponAssignPayload();

    config(['coupons.creation_otp_resend_seconds' => 120]);

    $send = $service->send($creator, OtpCode::CHANNEL_EMAIL, $payload);

    $this->withoutMiddleware();
    $resend = $this->actingAs($creator)->postJson(route('admin.coupons.assign.creation-otp.resend'), [
        'challenge_id' => $send['challenge_id'],
        'channel' => 'email',
        'assign_payload' => $payload,
    ]);

    $resend->assertStatus(429)->assertJsonStructure(['resend_in']);
});

test('demasiados intentos fallidos bloquean validación OTP', function () {
    Notification::fake();
    $creator = makeCouponCreatorUser();
    $service = app(CouponAssignOtpService::class);
    $payload = newCouponAssignPayload();

    $send = $service->send($creator, OtpCode::CHANNEL_EMAIL, $payload);

    for ($i = 0; $i < AdminOtpService::MAX_ATTEMPTS; $i++) {
        try {
            $service->verify($creator, $send['challenge_id'], '111111');
        } catch (DomainException) {
            // esperado
        }
    }

    expect(fn () => $service->verify($creator, $send['challenge_id'], '111111'))
        ->toThrow(DomainException::class);
});

test('no se notifica a autorizadores si el cupón no se creó', function () {
    Mail::fake();
    $creator = makeCouponCreatorUser();
    makeAuthorizerUser();

    $this->withoutMiddleware();

    try {
        $this->actingAs($creator)
            ->from(route('admin.coupons.assign'))
            ->post(route('admin.coupons.assign.store'), newCouponAssignPayload());
    } catch (ValidationException) {
        // esperado sin OTP
    }

    Mail::assertNothingSent();
});

test('cupón existente no exige OTP al asignar', function () {
    Notification::fake();
    Mail::fake();

    $creator = makeCouponCreatorUser();
    makeAuthorizerUser();

    $parent = Coupon::factory()->create([
        'approval_status' => \App\Enums\CouponApprovalStatus::Active,
        'is_active' => true,
        'created_by_user_id' => $creator->id,
        'updated_by_user_id' => $creator->id,
    ]);

    $this->withoutMiddleware();

    $response = $this->actingAs($creator)
        ->from(route('admin.coupons.assign'))
        ->post(route('admin.coupons.assign.store'), [
            'coupon_mode' => 'existing',
            'assignment_mode' => 'none',
            'coupon_id' => $parent->id,
            'send_notification' => true,
        ]);

    $response->assertRedirect();
    Notification::assertNothingSent();
    Mail::assertNothingSent();
});

test('endpoint HTTP de envío OTP responde JSON', function () {
    Notification::fake();
    $creator = makeCouponCreatorUser();
    $payload = newCouponAssignPayload();

    $this->withoutMiddleware();

    $response = $this->actingAs($creator)->postJson(route('admin.coupons.assign.creation-otp.send'), [
        'channel' => 'email',
        'assign_payload' => $payload,
    ]);

    $response->assertOk()->assertJsonPath('required', true);
    Notification::assertSentTo($creator, CouponCreationOtpNotification::class);
});

test('notificador envía correo a autorizadores tras crear cupón', function () {
    Mail::fake();

    $creator = makeCouponCreatorUser();
    makeAuthorizerUser('autorizador-notify@famedic.test');

    $coupon = Coupon::factory()->create([
        'created_by_user_id' => $creator->id,
        'updated_by_user_id' => $creator->id,
    ]);

    app(CouponCreatedAuthorizerNotifier::class)->notify($coupon, $creator);

    Mail::assertSent(CouponCreatedAuthorizerMail::class, 1);
});
