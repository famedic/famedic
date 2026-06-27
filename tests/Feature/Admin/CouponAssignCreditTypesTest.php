<?php

use App\Enums\CouponType;
use App\Enums\PromoType;
use App\Models\Administrator;
use App\Models\Coupon;
use App\Models\PromoCode;
use App\Models\User;
use App\Services\PromoCodeService;

beforeEach(function () {
    config(['coupons.creation_otp_required' => false]);
});

function makeAssignAdmin(): User
{
    $user = User::factory()->create();
    Administrator::factory()->for($user)->withRole('superadmin')->create();

    return $user->fresh()->load('administrator');
}

function assignSharedPromoPayload(array $overrides = []): array
{
    return array_merge([
        'coupon_mode' => 'new',
        'assignment_mode' => 'none',
        'type' => 'shared_promo',
        'amount_cents' => 50000,
        'code' => 'EVENTO-ASSIGN',
        'auto_generate_code' => false,
        'description' => 'Desde assign',
        'max_redemptions' => 10,
        'max_uses_per_user' => 1,
        'is_active' => true,
        'send_notification' => true,
        'send_notifications' => true,
        'validity_mode' => 'open',
        'minimum_purchase_mode' => 'none',
    ], $overrides);
}

function assignBalancePayload(array $overrides = []): array
{
    return array_merge([
        'coupon_mode' => 'new',
        'assignment_mode' => 'none',
        'type' => 'balance',
        'amount_cents' => 50000,
        'description' => 'Saldo directo',
        'is_active' => true,
        'send_notification' => true,
        'send_notifications' => true,
    ], $overrides);
}

test('assign puede crear código promocional compartido', function () {
    $admin = makeAssignAdmin();

    $this->actingAs($admin)
        ->post(route('admin.coupons.assign.store'), assignSharedPromoPayload())
        ->assertRedirect();

    $promo = PromoCode::query()->where('code', 'EVENTO-ASSIGN')->first();
    expect($promo)->not->toBeNull();
    expect($promo->promo_type)->toBe(PromoType::Shared);
    expect($promo->max_redemptions)->toBe(10);
    expect($promo->max_uses_per_user)->toBe(1);
    expect($promo->coupon->type)->toBe(CouponType::Coupon);
});

test('assign normaliza código promocional', function () {
    $admin = makeAssignAdmin();

    $this->actingAs($admin)
        ->post(route('admin.coupons.assign.store'), assignSharedPromoPayload([
            'code' => '  evento assign  ',
        ]))
        ->assertRedirect();

    expect(PromoCode::query()->where('code', 'EVENTOASSIGN')->exists())->toBeTrue();
});

test('assign rechaza código promocional duplicado', function () {
    $admin = makeAssignAdmin();
    $master = Coupon::factory()->couponType()->create();
    PromoCode::factory()->create(['coupon_id' => $master->id, 'code' => 'DUPLICADO']);

    $this->actingAs($admin)
        ->post(route('admin.coupons.assign.store'), assignSharedPromoPayload(['code' => 'duplicado']))
        ->assertSessionHasErrors('code');
});

test('assign exige max_redemptions para código promocional', function () {
    $admin = makeAssignAdmin();

    $this->actingAs($admin)
        ->post(route('admin.coupons.assign.store'), assignSharedPromoPayload(['max_redemptions' => 0]))
        ->assertSessionHasErrors('max_redemptions');
});

test('saldo a favor no exige vigencia en assign', function () {
    $admin = makeAssignAdmin();

    $this->actingAs($admin)
        ->post(route('admin.coupons.assign.store'), assignBalancePayload())
        ->assertRedirect();

    $coupon = Coupon::query()->latest('id')->first();
    expect($coupon->type)->toBe(CouponType::Balance);
    expect($coupon->valid_from)->toBeNull();
    expect($coupon->expires_at)->toBeNull();
    expect($coupon->min_purchase_cents)->toBeNull();
});

test('endpoint check-code indica disponibilidad', function () {
    $admin = makeAssignAdmin();
    PromoCode::factory()->create(['code' => 'TAKEN123']);

    $this->actingAs($admin)
        ->postJson(route('admin.coupons.promo-codes.check-code'), ['code' => 'TAKEN123'])
        ->assertOk()
        ->assertJsonPath('available', false);

    $this->actingAs($admin)
        ->postJson(route('admin.coupons.promo-codes.check-code'), ['code' => 'FREE-CODE'])
        ->assertOk()
        ->assertJsonPath('available', true);
});

test('generador backend produce código legible único', function () {
    $code = app(PromoCodeService::class)->generateUniqueCode();
    expect($code)->toMatch('/^[A-Z0-9-]+$/');
    expect(str_contains($code, 'O'))->toBeFalse();
    expect(str_contains($code, '0'))->toBeFalse();
});
