<?php

use App\Enums\CouponApprovalStatus;
use App\Enums\CouponType;
use App\Enums\OtpPurpose;
use App\Models\Administrator;
use App\Models\Coupon;
use App\Models\CouponApprovalRequest;
use App\Models\CouponApprovalRequestAuthorizer;
use App\Models\OtpCode;
use App\Models\Permission;
use App\Models\PromoCode;
use App\Models\Role;
use App\Models\User;
use App\Services\AdminOtpService;
use App\Services\CouponAuthorizationOtpService;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Mail;

function makeCouponAuthorizerAdmin(): User
{
    $user = User::factory()->create(['email' => 'authorizer@example.com']);
    $admin = Administrator::factory()->create(['user_id' => $user->id]);
    $role = Role::firstOrCreate(['name' => 'autorizador', 'guard_name' => 'web']);
    $admin->assignRole($role);
    Permission::firstOrCreate(['name' => 'cupones.view', 'guard_name' => 'web']);
    $admin->givePermissionTo('cupones.view');

    return $user->fresh(['administrator']);
}

function makeCouponCreatorAdmin(): User
{
    $user = User::factory()->create(['email' => 'creator@example.com']);
    $admin = Administrator::factory()->create(['user_id' => $user->id]);
    Permission::firstOrCreate(['name' => 'coupons.manage', 'guard_name' => 'web']);
    Permission::firstOrCreate(['name' => 'cupones.view', 'guard_name' => 'web']);
    $admin->givePermissionTo(['coupons.manage', 'cupones.view']);

    return $user->fresh(['administrator']);
}

function pendingMasterCoupon(User $creator, array $overrides = []): Coupon
{
    return Coupon::factory()->create(array_merge([
        'parent_coupon_id' => null,
        'approval_status' => CouponApprovalStatus::PendingAuthorization,
        'is_active' => false,
        'remaining_cents' => 0,
        'created_by_user_id' => $creator->id,
        'updated_by_user_id' => $creator->id,
    ], $overrides));
}

beforeEach(function () {
    Mail::fake();
    Cache::flush();
});

it('permite al autorizador ver la bandeja de autorizaciones', function () {
    $creator = makeCouponCreatorAdmin();
    $authorizer = makeCouponAuthorizerAdmin();
    pendingMasterCoupon($creator);

    $this->actingAs($authorizer)
        ->get(route('admin.coupons.authorizations.index'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('Admin/Coupons/Authorizations/Index')
            ->has('items', 1));
});

it('bloquea la bandeja a usuarios sin rol autorizador', function () {
    $user = makeCouponCreatorAdmin();

    $this->actingAs($user)
        ->get(route('admin.coupons.authorizations.index'))
        ->assertForbidden();
});

it('comparte navegación de autorizador solo para autorizadores', function () {
    $authorizer = makeCouponAuthorizerAdmin();
    $creator = makeCouponCreatorAdmin();
    pendingMasterCoupon($creator);

    $this->actingAs($authorizer)
        ->get(route('admin.coupons.authorizations.index'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('couponAuthorizerNav.is_authorizer', true)
            ->where('couponAuthorizerNav.pending_actionable_count', 1));

    $this->actingAs($creator)
        ->get(route('admin.coupons.index'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('couponAuthorizerNav.is_authorizer', false));
});

it('muestra detalle de solicitud pendiente', function () {
    $creator = makeCouponCreatorAdmin();
    $authorizer = makeCouponAuthorizerAdmin();
    $coupon = pendingMasterCoupon($creator, ['description' => 'Crédito de prueba']);

    $this->actingAs($authorizer)
        ->get(route('admin.coupons.authorizations.show', $coupon))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('Admin/Coupons/Authorizations/Show')
            ->where('authorization.coupon.id', $coupon->id)
            ->where('authorization.i_can_approve', true));
});

it('impide al creador aprobar su propia solicitud', function () {
    $creator = makeCouponCreatorAdmin();
    $creator->administrator->assignRole(Role::firstOrCreate(['name' => 'autorizador', 'guard_name' => 'web']));
    $coupon = pendingMasterCoupon($creator);

    $this->actingAs($creator)
        ->get(route('admin.coupons.authorizations.show', $coupon))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('authorization.i_can_approve', false)
            ->where('authorization.is_creator', true));
});

it('aprueba un maestro pendiente con OTP válido', function () {
    $creator = makeCouponCreatorAdmin();
    $authorizer = makeCouponAuthorizerAdmin();
    $coupon = pendingMasterCoupon($creator, ['amount_cents' => 25000, 'remaining_cents' => 0]);

    $otpService = app(CouponAuthorizationOtpService::class);
    $issued = app(AdminOtpService::class)->issue(
        userId: $authorizer->id,
        purpose: OtpPurpose::CouponAuthorizationApproval,
        channel: OtpCode::CHANNEL_EMAIL,
        challengeId: $otpService->challengeIdFor($coupon->id),
    );

    $verified = $otpService->verify(
        $authorizer,
        $coupon->id,
        null,
        $issued['plain_code'],
    );

    $this->actingAs($authorizer)
        ->post(route('admin.coupons.authorizations.approve', $coupon), [
            'otp_verification_token' => $verified['verification_token'],
        ])
        ->assertRedirect(route('admin.coupons.authorizations.index'));

    $coupon->refresh();
    expect($coupon->approval_status)->toBe(CouponApprovalStatus::Active)
        ->and($coupon->is_active)->toBeTrue()
        ->and($coupon->remaining_cents)->toBe(25000);
});

it('rechaza un maestro pendiente con motivo obligatorio', function () {
    $creator = makeCouponCreatorAdmin();
    $authorizer = makeCouponAuthorizerAdmin();
    $coupon = pendingMasterCoupon($creator);

    $this->actingAs($authorizer)
        ->post(route('admin.coupons.authorizations.reject', $coupon), [
            'reason' => 'Monto no autorizado por política interna.',
        ])
        ->assertRedirect(route('admin.coupons.authorizations.index'));

    $coupon->refresh();
    expect($coupon->approval_status)->toBe(CouponApprovalStatus::Rejected)
        ->and($coupon->rejected_reason)->toContain('política interna');
});

it('exige motivo mínimo al rechazar', function () {
    $creator = makeCouponCreatorAdmin();
    $authorizer = makeCouponAuthorizerAdmin();
    $coupon = pendingMasterCoupon($creator);

    $this->actingAs($authorizer)
        ->post(route('admin.coupons.authorizations.reject', $coupon), [
            'reason' => 'corto',
        ])
        ->assertSessionHasErrors('reason');
});

it('no permite doble aprobación del mismo autorizador en asignación', function () {
    $creator = makeCouponCreatorAdmin();
    $authorizer = makeCouponAuthorizerAdmin();
    $coupon = pendingMasterCoupon($creator, [
        'approval_status' => CouponApprovalStatus::Active,
        'is_active' => true,
        'remaining_cents' => 50000,
    ]);

    $request = CouponApprovalRequest::create([
        'type' => 'assignment',
        'status' => 'pending',
        'requested_by_user_id' => $creator->id,
        'coupon_id' => $coupon->id,
        'required_approvals' => 1,
        'current_approvals' => 0,
        'payload' => [
            'coupon_id' => $coupon->id,
            'emails' => [],
            'pre_approval_only' => true,
            'send_notification' => false,
        ],
    ]);

    CouponApprovalRequestAuthorizer::create([
        'coupon_approval_request_id' => $request->id,
        'administrator_id' => $authorizer->administrator->id,
        'status' => 'pending',
    ]);

    $otpService = app(CouponAuthorizationOtpService::class);

    $issueAndVerify = function () use ($otpService, $authorizer, $coupon, $request) {
        $issued = app(AdminOtpService::class)->issue(
            userId: $authorizer->id,
            purpose: OtpPurpose::CouponAuthorizationApproval,
            channel: OtpCode::CHANNEL_EMAIL,
            challengeId: $otpService->challengeIdFor($coupon->id, $request->id),
        );

        return $otpService->verify($authorizer, $coupon->id, $request->id, $issued['plain_code']);
    };

    $verified = $issueAndVerify();

    $this->actingAs($authorizer)
        ->post(route('admin.coupons.authorizations.approve', $coupon), [
            'otp_verification_token' => $verified['verification_token'],
            'approval_request_id' => $request->id,
        ])
        ->assertRedirect(route('admin.coupons.authorizations.index'));

    $verifiedAgain = $issueAndVerify();

    $this->actingAs($authorizer)
        ->post(route('admin.coupons.authorizations.approve', $coupon), [
            'otp_verification_token' => $verifiedAgain['verification_token'],
            'approval_request_id' => $request->id,
        ])
        ->assertRedirect()
        ->assertSessionHas('flashMessage.type', 'error');
});

it('bloquea promo codes cuyo maestro está pendiente en checkout', function () {
    $creator = makeCouponCreatorAdmin();
    $coupon = pendingMasterCoupon($creator, [
        'type' => CouponType::Coupon,
        'amount_cents' => 10000,
        'remaining_cents' => 0,
    ]);

    $promo = PromoCode::create([
        'coupon_id' => $coupon->id,
        'code' => 'PENDIENTE10',
        'promo_type' => 'shared',
        'max_redemptions' => 10,
        'max_uses_per_user' => 1,
        'is_active' => true,
        'created_by_user_id' => $creator->id,
    ]);

    $customerUser = User::factory()->create();
    $customer = \App\Models\Customer::factory()->create(['user_id' => $customerUser->id]);

    $service = app(\App\Services\PromoCodeService::class);

    expect(fn () => $service->validateForCheckout(
        $customerUser,
        $customer,
        $promo->code,
        50000,
        hash('sha256', 'cart'),
    ))->toThrow(\App\Exceptions\PromoCodeException::class);
});

it('rechaza OTP inválido al verificar aprobación', function () {
    $creator = makeCouponCreatorAdmin();
    $authorizer = makeCouponAuthorizerAdmin();
    $coupon = pendingMasterCoupon($creator);

    $this->actingAs($authorizer)
        ->postJson(route('admin.coupons.authorizations.approval-otp.verify', $coupon), [
            'code' => '000000',
        ])
        ->assertStatus(422);
});

it('lista códigos promocionales pendientes en la bandeja', function () {
    $creator = makeCouponCreatorAdmin();
    $authorizer = makeCouponAuthorizerAdmin();
    $coupon = pendingMasterCoupon($creator, ['type' => CouponType::Coupon]);

    PromoCode::create([
        'coupon_id' => $coupon->id,
        'code' => 'EVENTO2026',
        'promo_type' => 'shared',
        'max_redemptions' => 5,
        'max_uses_per_user' => 1,
        'is_active' => true,
        'created_by_user_id' => $creator->id,
    ]);

    $this->actingAs($authorizer)
        ->get(route('admin.coupons.authorizations.index'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('items.0.credit_type_label', 'Código promocional')
            ->where('items.0.promo_code', 'EVENTO2026'));
});

it('envía notificación al aprobar maestro pendiente', function () {
    $creator = makeCouponCreatorAdmin();
    $authorizer = makeCouponAuthorizerAdmin();
    $coupon = pendingMasterCoupon($creator);

    $otpService = app(CouponAuthorizationOtpService::class);
    $issued = app(AdminOtpService::class)->issue(
        userId: $authorizer->id,
        purpose: OtpPurpose::CouponAuthorizationApproval,
        channel: OtpCode::CHANNEL_EMAIL,
        challengeId: $otpService->challengeIdFor($coupon->id),
    );
    $verified = $otpService->verify($authorizer, $coupon->id, null, $issued['plain_code']);

    $this->actingAs($authorizer)
        ->post(route('admin.coupons.authorizations.approve', $coupon), [
            'otp_verification_token' => $verified['verification_token'],
        ]);

    Mail::assertSent(\App\Mail\CouponAuthorizationDecisionMail::class);
});
