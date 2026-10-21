<?php

use App\Enums\CouponType;
use App\Models\Coupon;
use App\Models\CouponTransaction;
use App\Models\LaboratoryCartItem;
use App\Models\MedicalServiceCart;
use App\Models\MedicalServiceCartBenefit;
use App\Models\User;
use App\Services\Api\V1\MedicalServiceCartService;
use App\Services\Api\V1\Idempotency\IdempotencyKey;

function m2cMedicalHeaders(string $token, ?string $idempotencyKey = null): array
{
    $headers = authHeaders($token);
    if ($idempotencyKey !== null) {
        $headers[IdempotencyKey::HEADER] = $idempotencyKey;
    }

    return $headers;
}

function addM2cMembership(User $user): MedicalServiceCart
{
    return app(MedicalServiceCartService::class)->addBasicPlan($user->customer);
}

function createM2cCoupon(User $user, string $code, CouponType $type, int $amountCents, array $attributes = []): Coupon
{
    $coupon = Coupon::factory()->create(array_merge([
        'code' => $code,
        'type' => $type,
        'amount_cents' => $amountCents,
        'remaining_cents' => $amountCents,
    ], $attributes));

    assignUserCoupon($user, $coupon);

    return $coupon;
}

test('applies assigned credit partially without consuming it', function () {
    [$user, $token] = akubicaCustomerToken();
    addM2cMembership($user);
    $credit = createM2cCoupon($user, 'CREDITO-M2C', CouponType::Balance, 50000);

    $this->postJson('/api/v1/cart/medical-benefit', [
        'service_line' => 'medical_services',
        'code' => 'CREDITO-M2C',
    ], m2cMedicalHeaders($token))->assertOk()
        ->assertJsonPath('data.cart.totals.subtotal_cents', 30000)
        ->assertJsonPath('data.cart.totals.discount_cents', 0)
        ->assertJsonPath('data.cart.totals.credit_applied_cents', 30000)
        ->assertJsonPath('data.cart.totals.total_cents', 0)
        ->assertJsonPath('data.cart.applied_benefits.0.type', 'credit')
        ->assertJsonPath('data.cart.applied_benefits.0.applied_cents', 30000);

    expect($credit->fresh()->remaining_cents)->toBe(50000)
        ->and(CouponTransaction::query()->count())->toBe(0)
        ->and(MedicalServiceCartBenefit::query()->count())->toBe(1);
});

test('applies a coupon as discount and does not combine it with credit', function () {
    [$user, $token] = akubicaCustomerToken();
    addM2cMembership($user);
    createM2cCoupon($user, 'CUPON-M2C', CouponType::Coupon, 7000);
    createM2cCoupon($user, 'SALDO-M2C', CouponType::Balance, 7000);

    $this->postJson('/api/v1/cart/medical-benefit', [
        'service_line' => 'medical_services',
        'code' => 'CUPON-M2C',
    ], m2cMedicalHeaders($token))->assertOk()
        ->assertJsonPath('data.cart.totals.discount_cents', 7000)
        ->assertJsonPath('data.cart.totals.credit_applied_cents', 0)
        ->assertJsonPath('data.cart.totals.total_cents', 23000);

    $this->postJson('/api/v1/cart/medical-benefit', [
        'service_line' => 'medical_services',
        'code' => 'SALDO-M2C',
    ], m2cMedicalHeaders($token))->assertStatus(409)
        ->assertJsonPath('error.code', 'BENEFIT_ALREADY_APPLIED');

    expect(MedicalServiceCartBenefit::query()->count())->toBe(1);
});

test('rejects expired, unassigned and invalid medical benefits', function () {
    [$user, $token] = akubicaCustomerToken();
    addM2cMembership($user);
    createM2cCoupon($user, 'VENCIDO-M2C', CouponType::Balance, 5000, ['expires_at' => now()->subMinute()]);

    $this->postJson('/api/v1/cart/medical-benefit', [
        'service_line' => 'medical_services',
        'code' => 'VENCIDO-M2C',
    ], m2cMedicalHeaders($token))->assertStatus(409)
        ->assertJsonPath('error.code', 'COUPON_EXPIRED');

    $this->postJson('/api/v1/cart/medical-benefit', [
        'service_line' => 'medical_services',
        'code' => 'NO-ASIGNADO',
    ], m2cMedicalHeaders($token))->assertNotFound()
        ->assertJsonPath('error.code', 'COUPON_NOT_FOUND');
});

test('isolates medical benefits from laboratory carts and customer ownership', function () {
    [$owner, $ownerToken] = akubicaCustomerToken();
    [$other, $otherToken] = akubicaCustomerToken();
    addM2cMembership($owner);
    addM2cMembership($other);
    LaboratoryCartItem::factory()->create(['customer_id' => $owner->customer->id]);
    $coupon = createM2cCoupon($owner, 'PROPIO-M2C', CouponType::Balance, 5000);

    $this->postJson('/api/v1/cart/medical-benefit', [
        'service_line' => 'medical_services',
        'code' => 'PROPIO-M2C',
    ], switchApiBearerToken($this, $otherToken))->assertNotFound();

    $this->postJson('/api/v1/cart/medical-benefit', [
        'service_line' => 'medical_services',
        'code' => 'PROPIO-M2C',
    ], switchApiBearerToken($this, $ownerToken))->assertOk();

    expect($owner->customer->laboratoryCartItems()->count())->toBe(1)
        ->and($owner->customer->medicalServiceCarts()->first()->benefits()->count())->toBe(1);
});

test('recalculates the persisted benefit after item removal', function () {
    [$user, $token] = akubicaCustomerToken();
    addM2cMembership($user);
    createM2cCoupon($user, 'RECALC-M2C', CouponType::Coupon, 5000);

    $this->postJson('/api/v1/cart/medical-benefit', [
        'service_line' => 'medical_services',
        'code' => 'RECALC-M2C',
    ], m2cMedicalHeaders($token))->assertOk();

    $itemId = $user->customer->medicalServiceCarts()->first()->items()->sole()->id;
    $this->deleteJson("/api/v1/cart/items/{$itemId}", [], m2cMedicalHeaders($token))->assertOk()
        ->assertJsonPath('data.totals.discount_cents', 0)
        ->assertJsonPath('data.totals.credit_applied_cents', 0)
        ->assertJsonPath('data.totals.total_cents', 0);
});

test('reapplies the same medical benefit idempotently', function () {
    config()->set('api_v1.idempotency.enabled', true);
    [$user, $token] = akubicaCustomerToken();
    addM2cMembership($user);
    createM2cCoupon($user, 'IDEMP-M2C', CouponType::Coupon, 5000);
    $headers = m2cMedicalHeaders($token, 'm2c-benefit-idempotency-abcdef');
    $payload = ['service_line' => 'medical_services', 'code' => 'IDEMP-M2C'];

    $first = $this->postJson('/api/v1/cart/medical-benefit', $payload, $headers)->assertOk();
    $second = $this->postJson('/api/v1/cart/medical-benefit', $payload, $headers)->assertOk();

    expect($second->json('data.cart.totals.discount_cents'))->toBe(5000)
        ->and($user->customer->medicalServiceCarts()->first()->benefits()->count())->toBe(1);
});

test('removes a medical benefit without changing laboratory coupon behavior', function () {
    [$user, $token] = akubicaCustomerToken();
    addM2cMembership($user);
    createM2cCoupon($user, 'REMOVE-M2C', CouponType::Balance, 5000);

    $this->postJson('/api/v1/cart/medical-benefit', [
        'service_line' => 'medical_services',
        'code' => 'REMOVE-M2C',
    ], m2cMedicalHeaders($token))->assertOk();

    $this->deleteJson('/api/v1/cart/medical-benefit?service_line=medical_services', [], m2cMedicalHeaders($token))
        ->assertOk()
        ->assertJsonPath('data.removed', true)
        ->assertJsonPath('data.cart.totals.credit_applied_cents', 0)
        ->assertJsonPath('data.cart.totals.total_cents', 30000);

    $this->deleteJson('/api/v1/cart/medical-benefit?service_line=medical_services', [], m2cMedicalHeaders($token))
        ->assertOk()
        ->assertJsonPath('data.removed', false);
});
