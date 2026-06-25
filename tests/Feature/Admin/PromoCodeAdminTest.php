<?php

use App\Enums\CouponType;
use App\Enums\PromoRedemptionStatus;
use App\Enums\PromoType;
use App\Mail\CouponCreatedAuthorizerMail;
use App\Models\Administrator;
use App\Models\Coupon;
use App\Models\PromoCode;
use App\Models\Permission;
use App\Models\PromoRedemption;
use App\Models\User;
use App\Services\CouponCreatedAuthorizerNotifier;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;

beforeEach(function () {
    config(['coupons.creation_otp_required' => false]);
});

function makeCouponAdminUser(): User
{
    $user = User::factory()->create();
    Administrator::factory()->for($user)->withRole('superadmin')->create();

    return $user->fresh()->load('administrator');
}

/**
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function validSharedPromoPayload(array $overrides = []): array
{
    return array_merge([
        'code' => 'EVENTO10',
        'auto_generate_code' => false,
        'description' => 'Campaña de prueba',
        'amount_cents' => 50000,
        'max_redemptions' => 100,
        'max_uses_per_user' => 2,
        'is_active' => true,
        'validity_mode' => 'open',
        'minimum_purchase_mode' => 'none',
    ], $overrides);
}

test('admin puede ver listado de promo codes', function () {
    $admin = makeCouponAdminUser();
    $master = Coupon::factory()->couponType(50000)->create(['description' => 'Promo listado']);
    PromoCode::factory()->create([
        'coupon_id' => $master->id,
        'code' => 'LISTADO1',
    ]);

    $this->actingAs($admin)
        ->get(route('admin.coupons.promo-codes.index'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('Admin/Coupons/PromoCodes/Index')
            ->has('promoCodes.data', 1)
            ->where('promoCodes.data.0.code', 'LISTADO1'));
});

test('admin puede crear código compartido', function () {
    $admin = makeCouponAdminUser();

    $response = $this->actingAs($admin)
        ->post(route('admin.coupons.promo-codes.store'), validSharedPromoPayload());

    $response->assertRedirect();

    $promo = PromoCode::query()->where('code', 'EVENTO10')->first();
    expect($promo)->not->toBeNull();
    expect($promo->promo_type)->toBe(PromoType::Shared);
    expect($promo->max_redemptions)->toBe(100);
    expect($promo->max_uses_per_user)->toBe(2);
    expect($promo->coupon->type)->toBe(CouponType::Coupon);
    expect($promo->coupon->amount_cents)->toBe(50000);
});

test('el código se normaliza al crear', function () {
    $admin = makeCouponAdminUser();

    $this->actingAs($admin)
        ->post(route('admin.coupons.promo-codes.store'), validSharedPromoPayload([
            'code' => '  evento 20  ',
        ]))
        ->assertRedirect();

    expect(PromoCode::query()->where('code', 'EVENTO20')->exists())->toBeTrue();
});

test('no permite código duplicado', function () {
    $admin = makeCouponAdminUser();
    $master = Coupon::factory()->couponType()->create();
    PromoCode::factory()->create([
        'coupon_id' => $master->id,
        'code' => 'DUPLICADO',
    ]);

    $this->actingAs($admin)
        ->post(route('admin.coupons.promo-codes.store'), validSharedPromoPayload([
            'code' => 'duplicado',
        ]))
        ->assertSessionHasErrors('code');
});

test('no permite monto inválido', function () {
    $admin = makeCouponAdminUser();

    $this->actingAs($admin)
        ->post(route('admin.coupons.promo-codes.store'), validSharedPromoPayload([
            'amount_cents' => 0,
        ]))
        ->assertSessionHasErrors('amount_cents');
});

test('no permite max_redemptions inválido', function () {
    $admin = makeCouponAdminUser();

    $this->actingAs($admin)
        ->post(route('admin.coupons.promo-codes.store'), validSharedPromoPayload([
            'max_redemptions' => 0,
        ]))
        ->assertSessionHasErrors('max_redemptions');
});

test('no permite expiración anterior a inicio', function () {
    $admin = makeCouponAdminUser();

    $this->actingAs($admin)
        ->post(route('admin.coupons.promo-codes.store'), validSharedPromoPayload([
            'validity_mode' => 'configured',
            'valid_from' => now()->addDay()->toDateTimeString(),
            'expires_at' => now()->subDay()->toDateTimeString(),
        ]))
        ->assertSessionHasErrors('expires_at');
});

test('admin puede ver detalle con historial de redenciones', function () {
    $admin = makeCouponAdminUser();
    $customerUser = User::factory()->withRegularCustomer()->create();
    $master = Coupon::factory()->couponType()->create();
    $promo = PromoCode::factory()->create([
        'coupon_id' => $master->id,
        'code' => 'DETALLE1',
    ]);

    PromoRedemption::query()->create([
        'promo_code_id' => $promo->id,
        'user_id' => $customerUser->id,
        'customer_id' => $customerUser->customer->id,
        'status' => PromoRedemptionStatus::Confirmed,
        'discount_cents' => 50000,
        'validation_token' => Str::random(48),
        'cart_hash' => hash('sha256', 'detalle-test-cart'),
        'validated_at' => now(),
        'confirmed_at' => now(),
    ]);

    $this->actingAs($admin)
        ->get(route('admin.coupons.promo-codes.show', $promo))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('Admin/Coupons/PromoCodes/Show')
            ->where('promoCode.code', 'DETALLE1')
            ->has('promoCode.redemptions', 1)
            ->where('promoCode.redemptions.0.status', PromoRedemptionStatus::Confirmed->value));
});

test('admin puede desactivar código', function () {
    $admin = makeCouponAdminUser();
    $promo = PromoCode::factory()->create(['is_active' => true]);

    $this->actingAs($admin)
        ->post(route('admin.coupons.promo-codes.deactivate', $promo), [
            'confirm' => true,
        ])
        ->assertRedirect(route('admin.coupons.promo-codes.show', $promo));

    expect($promo->fresh()->is_active)->toBeFalse();
});

test('usuario sin cuenta admin no puede acceder a crear promo codes', function () {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->post(route('admin.coupons.promo-codes.store'), validSharedPromoPayload())
        ->assertNotFound();
});

test('usuario sin cuenta admin no puede desactivar promo codes', function () {
    $user = User::factory()->create();
    $promo = PromoCode::factory()->create();

    $this->actingAs($user)
        ->post(route('admin.coupons.promo-codes.deactivate', $promo), [
            'confirm' => true,
        ])
        ->assertNotFound();
});

test('administrador sin permiso cupones.create no puede crear promo codes', function () {
    $user = User::factory()->create();
    Administrator::factory()->for($user)->create();

    $this->actingAs($user)
        ->post(route('admin.coupons.promo-codes.store'), validSharedPromoPayload())
        ->assertForbidden();
});

test('administrador sin permiso cupones.edit no puede desactivar promo codes', function () {
    $user = User::factory()->create();
    $admin = Administrator::factory()->for($user)->create();
    $admin->givePermissionTo(Permission::firstOrCreate([
        'name' => 'cupones.view',
        'guard_name' => 'web',
    ]));
    $promo = PromoCode::factory()->create();

    $this->actingAs($user)
        ->post(route('admin.coupons.promo-codes.deactivate', $promo), [
            'confirm' => true,
        ])
        ->assertForbidden();
});

test('creación exige OTP cuando está habilitado', function () {
    config(['coupons.creation_otp_required' => true]);
    $admin = makeCouponAdminUser();

    $this->actingAs($admin)
        ->post(route('admin.coupons.promo-codes.store'), validSharedPromoPayload())
        ->assertSessionHasErrors('otp_verification_token');

    expect(PromoCode::query()->count())->toBe(0);
});

test('creación consume OTP verificado cuando está habilitado', function () {
    config(['coupons.creation_otp_required' => true]);
    $admin = makeCouponAdminUser();
    $payload = validSharedPromoPayload(['code' => 'OTPTEST1']);
    $token = (string) \Illuminate\Support\Str::uuid();

    Cache::put('coupon_assign_verified:'.$token, [
        'user_id' => $admin->id,
        'challenge_id' => (string) \Illuminate\Support\Str::uuid(),
        'payload_hash' => app(\App\Services\CouponAssignOtpService::class)->hashPayload(
            array_merge($payload, [
                'promo_creation' => true,
                'promo_type' => 'shared',
            ])
        ),
        'otp_id' => 1,
    ], now()->addMinutes(15));

    $this->actingAs($admin)
        ->post(route('admin.coupons.promo-codes.store'), array_merge($payload, [
            'otp_verification_token' => $token,
        ]))
        ->assertRedirect();

    expect(PromoCode::query()->where('code', 'OTPTEST1')->exists())->toBeTrue();
    expect(Cache::has('coupon_assign_verified:'.$token))->toBeFalse();
});

test('correo a autorizadores incluye código promocional y máx usos', function () {
    Mail::fake();

    $creator = makeCouponAdminUser();
    $authorizer = User::factory()->create();
    Administrator::factory()->withRole('autorizador')->create(['user_id' => $authorizer->id]);

    $master = Coupon::factory()->couponType(10000)->create([
        'code' => null,
        'description' => 'Campaña evento madre',
        'max_beneficiaries' => null,
        'created_by_user_id' => $creator->id,
        'updated_by_user_id' => $creator->id,
    ]);
    $promo = PromoCode::factory()->create([
        'coupon_id' => $master->id,
        'code' => 'EVENTOMADRE',
        'max_redemptions' => 1,
        'max_uses_per_user' => 1,
        'created_by_user_id' => $creator->id,
    ]);

    app(CouponCreatedAuthorizerNotifier::class)->notify($master, $creator, $promo);

    Mail::assertSent(CouponCreatedAuthorizerMail::class, function (CouponCreatedAuthorizerMail $mail) use ($promo) {
        return $mail->summary['is_promo_code'] === true
            && $mail->summary['code'] === 'EVENTOMADRE'
            && $mail->summary['promo_code'] === 'EVENTOMADRE'
            && $mail->summary['max_beneficiaries'] === '1'
            && $mail->summary['max_uses_per_user'] === '1'
            && str_contains($mail->detailUrl, '/admin/coupons/promo-codes/'.$promo->id);
    });
});
