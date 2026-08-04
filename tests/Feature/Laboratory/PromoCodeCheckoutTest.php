<?php

use App\Enums\CouponApprovalStatus;
use App\Enums\CouponPurchaseType;
use App\Enums\CouponType;
use App\Enums\Gender;
use App\Enums\LaboratoryBrand;
use App\Enums\PromoRedemptionStatus;
use App\Models\Coupon;
use App\Models\CouponTransaction;
use App\Models\CouponUser;
use App\Models\LaboratoryCartItem;
use App\Models\LaboratoryCheckoutDraft;
use App\Models\LaboratoryTest;
use App\Models\PromoCode;
use App\Models\PromoRedemption;
use App\Models\User;
use App\Services\PromoCodeService;
use Illuminate\Support\Facades\DB;

beforeEach(function () {
    $this->withoutMiddleware([
        \App\Http\Middleware\RedirectIfEmptyLaboratoryCartItems::class,
        \App\Http\Middleware\RedirectIfUserProfileIsIncomplete::class,
        \App\Http\Middleware\EnsureDocumentationIsAccepted::class,
        \App\Http\Middleware\EnsurePhoneIsVerified::class,
        \Illuminate\Auth\Middleware\EnsureEmailIsVerified::class,
    ]);
});

function makePromoCheckoutUser(): User
{
    $user = User::factory()
        ->withCompleteProfile()
        ->withRegularCustomer()
        ->create([
            'documentation_accepted_at' => now(),
        ]);

    return $user->fresh()->load('customer');
}

function seedLaboratoryCart(User $user, LaboratoryBrand $brand, int $priceCents = 80000): void
{
    $test = LaboratoryTest::factory()->create([
        'brand' => $brand->value,
        'requires_appointment' => false,
        'famedic_price_cents' => $priceCents,
    ]);

    LaboratoryCartItem::factory()->create([
        'customer_id' => $user->customer->id,
        'laboratory_test_id' => $test->id,
    ]);
}

function makeSharedPromo(string $code, int $discountCents = 50000, ?int $maxRedemptions = 10): PromoCode
{
    $master = Coupon::factory()->couponType($discountCents)->create();

    return PromoCode::factory()->create([
        'coupon_id' => $master->id,
        'code' => PromoCode::normalizeCode($code),
        'max_redemptions' => $maxRedemptions,
        'max_uses_per_user' => 1,
    ]);
}

function promoCartHash(User $user, int $cartTotalCents): string
{
    $cartItems = $user->customer->laboratoryCartItems()->get();

    return app(PromoCodeService::class)->buildLaboratoryCartHash($cartItems, $cartTotalCents);
}

function makePromoPurchase(User $user, int $totalCents = 80000): \App\Models\LaboratoryPurchase
{
    return $user->customer->laboratoryPurchases()->create([
        'gda_order_id' => 0,
        'brand' => LaboratoryBrand::OLAB->value,
        'name' => 'Test',
        'paternal_lastname' => 'User',
        'maternal_lastname' => 'Test',
        'phone' => '8112345678',
        'phone_country' => 'MX',
        'birth_date' => now()->subYears(30),
        'gender' => Gender::MALE,
        'street' => 'Calle',
        'number' => '1',
        'neighborhood' => 'Centro',
        'state' => 'Nuevo León',
        'city' => 'Monterrey',
        'zipcode' => '64000',
        'total_cents' => $totalCents,
    ]);
}

test('valida código compartido y devuelve token de checkout', function () {
    $user = makePromoCheckoutUser();
    $brand = LaboratoryBrand::OLAB;
    seedLaboratoryCart($user, $brand, 80000);
    $promo = makeSharedPromo('EVENTO10');

    $response = $this->actingAs($user)->postJson(route('laboratory.checkout.promo-codes.validate', [
        'laboratory_brand' => $brand->value,
    ]), [
        'code' => 'evento10',
    ]);

    $response->assertOk()
        ->assertJsonPath('valid', true)
        ->assertJsonPath('discount_cents', 50000)
        ->assertJsonStructure([
            'valid',
            'discount_cents',
            'message',
            'validation_token',
            'expires_in',
            'benefit_label',
            'remaining_uses',
        ]);

    expect(PromoRedemption::query()->where('status', PromoRedemptionStatus::Validated)->count())->toBe(1);
    expect($promo->fresh()->redemptions_count)->toBe(0);
});

test('normaliza código con espacios y minúsculas', function () {
    $user = makePromoCheckoutUser();
    seedLaboratoryCart($user, LaboratoryBrand::OLAB, 80000);
    makeSharedPromo('EVENTO10');

    $this->actingAs($user)->postJson(route('laboratory.checkout.promo-codes.validate', [
        'laboratory_brand' => LaboratoryBrand::OLAB->value,
    ]), [
        'code' => '  evento 10  ',
    ])->assertOk()
        ->assertJsonPath('valid', true);
});

test('rechaza código inválido', function () {
    $user = makePromoCheckoutUser();
    seedLaboratoryCart($user, LaboratoryBrand::OLAB);

    $this->actingAs($user)->postJson(route('laboratory.checkout.promo-codes.validate', [
        'laboratory_brand' => LaboratoryBrand::OLAB->value,
    ]), [
        'code' => 'NOEXISTE',
    ])->assertUnprocessable()
        ->assertJsonValidationErrors(['code']);
});

test('rechaza código inactivo', function () {
    $user = makePromoCheckoutUser();
    seedLaboratoryCart($user, LaboratoryBrand::OLAB, 80000);

    $promo = makeSharedPromo('INACTIVO');
    $promo->update(['is_active' => false]);

    $this->actingAs($user)->postJson(route('laboratory.checkout.promo-codes.validate', [
        'laboratory_brand' => LaboratoryBrand::OLAB->value,
    ]), [
        'code' => 'INACTIVO',
    ])->assertUnprocessable()
        ->assertJsonValidationErrors(['code']);
});

test('rechaza promo cuando cupón maestro está pendiente de autorización', function () {
    $user = makePromoCheckoutUser();
    seedLaboratoryCart($user, LaboratoryBrand::OLAB, 80000);

    $master = Coupon::factory()->couponType(50000)->create([
        'approval_status' => CouponApprovalStatus::PendingAuthorization,
        'is_active' => false,
    ]);

    PromoCode::factory()->create([
        'coupon_id' => $master->id,
        'code' => 'PENDAUTH',
        'is_active' => true,
    ]);

    $this->actingAs($user)->postJson(route('laboratory.checkout.promo-codes.validate', [
        'laboratory_brand' => LaboratoryBrand::OLAB->value,
    ]), [
        'code' => 'PENDAUTH',
    ])->assertUnprocessable()
        ->assertJsonValidationErrors(['code']);
});

test('rechaza código expirado', function () {
    $user = makePromoCheckoutUser();
    seedLaboratoryCart($user, LaboratoryBrand::OLAB);

    $master = Coupon::factory()->couponType(30000)->create([
        'expires_at' => now()->subDay(),
    ]);

    PromoCode::factory()->create([
        'coupon_id' => $master->id,
        'code' => 'VENCIDO',
    ]);

    $this->actingAs($user)->postJson(route('laboratory.checkout.promo-codes.validate', [
        'laboratory_brand' => LaboratoryBrand::OLAB->value,
    ]), [
        'code' => 'VENCIDO',
    ])->assertUnprocessable()
        ->assertJsonValidationErrors(['code']);
});

test('rechaza código agotado', function () {
    $user = makePromoCheckoutUser();
    seedLaboratoryCart($user, LaboratoryBrand::OLAB);

    $promo = makeSharedPromo('AGOTADO', 20000, 1);
    $promo->update(['redemptions_count' => 1]);

    $this->actingAs($user)->postJson(route('laboratory.checkout.promo-codes.validate', [
        'laboratory_brand' => LaboratoryBrand::OLAB->value,
    ]), [
        'code' => 'AGOTADO',
    ])->assertUnprocessable()
        ->assertJsonValidationErrors(['code']);
});

test('bloquea segundo uso del mismo usuario', function () {
    $user = makePromoCheckoutUser();
    seedLaboratoryCart($user, LaboratoryBrand::OLAB);
    $promo = makeSharedPromo('UNSOLO', 20000, 10);

    PromoRedemption::query()->create([
        'promo_code_id' => $promo->id,
        'user_id' => $user->id,
        'customer_id' => $user->customer->id,
        'status' => PromoRedemptionStatus::Confirmed,
        'discount_cents' => 20000,
        'validation_token' => 'used-token-'.uniqid(),
        'cart_hash' => 'hash',
        'validated_at' => now(),
        'confirmed_at' => now(),
    ]);

    $this->actingAs($user)->postJson(route('laboratory.checkout.promo-codes.validate', [
        'laboratory_brand' => LaboratoryBrand::OLAB->value,
    ]), [
        'code' => 'UNSOLO',
    ])->assertUnprocessable()
        ->assertJsonValidationErrors(['code']);
});

test('no permite código promocional con crédito asignado en draft', function () {
    $user = makePromoCheckoutUser();
    $brand = LaboratoryBrand::OLAB;
    seedLaboratoryCart($user, $brand);

    $assignedCoupon = Coupon::factory()->couponType(10000)->create();
    CouponUser::query()->create([
        'coupon_id' => $assignedCoupon->id,
        'user_id' => $user->id,
        'assigned_at' => now(),
    ]);

    LaboratoryCheckoutDraft::query()->create([
        'customer_id' => $user->customer->id,
        'laboratory_brand' => $brand->value,
        'coupon_id' => $assignedCoupon->id,
    ]);

    makeSharedPromo('MIXTO');

    $this->actingAs($user)->postJson(route('laboratory.checkout.promo-codes.validate', [
        'laboratory_brand' => $brand->value,
    ]), [
        'code' => 'MIXTO',
    ])->assertUnprocessable()
        ->assertJsonValidationErrors(['code']);
});

test('persiste promo_validation_token en draft al validar', function () {
    $user = makePromoCheckoutUser();
    $brand = LaboratoryBrand::OLAB;
    seedLaboratoryCart($user, $brand, 80000);
    makeSharedPromo('DRAFT10');

    $response = $this->actingAs($user)->postJson(route('laboratory.checkout.promo-codes.validate', [
        'laboratory_brand' => $brand->value,
    ]), [
        'code' => 'DRAFT10',
    ]);

    $token = $response->json('validation_token');

    $draft = LaboratoryCheckoutDraft::query()
        ->where('customer_id', $user->customer->id)
        ->where('laboratory_brand', $brand->value)
        ->first();

    expect($draft)->not->toBeNull();
    expect($draft->promo_validation_token)->toBe($token);
    expect($draft->coupon_id)->toBeNull();
});

test('delete libera validación y limpia draft', function () {
    $user = makePromoCheckoutUser();
    $brand = LaboratoryBrand::OLAB;
    seedLaboratoryCart($user, $brand, 80000);
    makeSharedPromo('QUITAR');

    $validate = $this->actingAs($user)->postJson(route('laboratory.checkout.promo-codes.validate', [
        'laboratory_brand' => $brand->value,
    ]), [
        'code' => 'QUITAR',
    ]);

    $token = $validate->json('validation_token');

    LaboratoryCheckoutDraft::query()->updateOrCreate(
        [
            'customer_id' => $user->customer->id,
            'laboratory_brand' => $brand->value,
        ],
        ['promo_validation_token' => $token],
    );

    $this->actingAs($user)->deleteJson(route('laboratory.checkout.promo-codes.destroy', [
        'laboratory_brand' => $brand->value,
    ]), [
        'validation_token' => $token,
    ])->assertOk();

    expect(
        PromoRedemption::query()
            ->where('validation_token', $token)
            ->where('status', PromoRedemptionStatus::Released)
            ->exists()
    )->toBeTrue();

    expect(
        LaboratoryCheckoutDraft::query()
            ->where('customer_id', $user->customer->id)
            ->where('laboratory_brand', $brand->value)
            ->value('promo_validation_token')
    )->toBeNull();
});

test('confirmación post-pago crea cupón hijo y redención confirmada', function () {
    $user = makePromoCheckoutUser();
    seedLaboratoryCart($user, LaboratoryBrand::OLAB, 80000);
    $promo = makeSharedPromo('CONFIRM', 30000, 5);
    $service = app(PromoCodeService::class);
    $cartTotal = 80000;
    $cartHash = promoCartHash($user, $cartTotal);

    $validation = $service->validateForCheckout(
        $user,
        $user->customer,
        'CONFIRM',
        $cartTotal,
        $cartHash,
    );

    expect($promo->fresh()->redemptions_count)->toBe(0);

    $purchase = makePromoPurchase($user, $cartTotal);

    $applied = $service->confirmRedemption(
        $user,
        $validation['validation_token'],
        $purchase,
        $cartTotal,
        $cartHash,
    );

    expect($applied)->toBe(30000);
    expect($promo->fresh()->redemptions_count)->toBe(1);

    $redemption = PromoRedemption::query()
        ->where('validation_token', $validation['validation_token'])
        ->first();

    expect($redemption->status)->toBe(PromoRedemptionStatus::Confirmed);
    expect($redemption->coupon_id)->not->toBeNull();
    expect($redemption->purchase_id)->toBe($purchase->id);
    expect($redemption->purchase_type)->toBe(CouponPurchaseType::Lab->value);

    $child = Coupon::query()->findOrFail($redemption->coupon_id);
    expect($child->parent_coupon_id)->toBe($promo->coupon_id);
    expect(CouponUser::query()->where('coupon_id', $child->id)->where('user_id', $user->id)->exists())->toBeTrue();
    expect(CouponTransaction::query()->where('coupon_id', $child->id)->where('purchase_id', $purchase->id)->exists())->toBeTrue();
    expect($purchase->fresh()->coupon_discount_cents)->toBe(30000);
});

test('confirmación es idempotente para el mismo pedido', function () {
    $user = makePromoCheckoutUser();
    seedLaboratoryCart($user, LaboratoryBrand::OLAB, 80000);
    makeSharedPromo('IDEMPOTENT', 25000, 5);
    $service = app(PromoCodeService::class);
    $cartTotal = 80000;
    $cartHash = promoCartHash($user, $cartTotal);

    $validation = $service->validateForCheckout(
        $user,
        $user->customer,
        'IDEMPOTENT',
        $cartTotal,
        $cartHash,
    );

    $purchase = makePromoPurchase($user, $cartTotal);

    $first = $service->confirmRedemption($user, $validation['validation_token'], $purchase, $cartTotal, $cartHash);
    $second = $service->confirmRedemption($user, $validation['validation_token'], $purchase, $cartTotal, $cartHash);

    expect($first)->toBe($second);
    expect(PromoRedemption::query()->where('validation_token', $validation['validation_token'])->count())->toBe(1);
    expect(PromoCode::query()->where('code', 'IDEMPOTENT')->value('redemptions_count'))->toBe(1);
});

test('respeta límite total bajo concurrencia', function () {
    $promo = makeSharedPromo('CONCURRENTE', 10000, 1);
    $service = app(PromoCodeService::class);

    $users = User::factory()
        ->count(2)
        ->withCompleteProfile()
        ->withRegularCustomer()
        ->create(['documentation_accepted_at' => now()])
        ->each(fn (User $user) => $user->load('customer'));

    foreach ($users as $user) {
        seedLaboratoryCart($user, LaboratoryBrand::OLAB, 50000);
    }

    $validations = [];
    foreach ($users as $user) {
        $validations[] = $service->validateForCheckout(
            $user,
            $user->customer,
            'CONCURRENTE',
            50000,
            promoCartHash($user, 50000),
        );
    }

    expect($promo->fresh()->redemptions_count)->toBe(0);

    $results = [];
    foreach ($users as $index => $user) {
        $cartHash = promoCartHash($user, 50000);
        $purchase = makePromoPurchase($user, 50000);

        try {
            DB::transaction(function () use ($service, $user, $validations, $index, $purchase, $cartHash, &$results) {
                $service->confirmRedemption(
                    $user,
                    $validations[$index]['validation_token'],
                    $purchase,
                    50000,
                    $cartHash,
                );
                $results[] = 'ok-'.$index;
            });
        } catch (\App\Exceptions\PromoCodeException) {
            $results[] = 'fail-'.$index;
        }
    }

    expect($results)->toHaveCount(2);
    expect(collect($results)->filter(fn ($r) => str_starts_with($r, 'ok'))->count())->toBe(1);
    expect($promo->fresh()->redemptions_count)->toBe(1);
});

test('no crea códigos promocionales duplicados', function () {
    $master = Coupon::factory()->couponType()->create();
    $service = app(PromoCodeService::class);

    $service->createSharedPromoCode($master, 'UNICO123');

    expect(fn () => $service->createSharedPromoCode($master, 'unico123'))
        ->toThrow(\App\Exceptions\PromoCodeException::class);
});

test('rechaza maestro tipo balance para promo compartido', function () {
    $master = Coupon::factory()->create(['type' => CouponType::Balance]);
    $service = app(PromoCodeService::class);

    expect(fn () => $service->createSharedPromoCode($master, 'BALANCEBAD'))
        ->toThrow(\App\Exceptions\PromoCodeException::class);
});
