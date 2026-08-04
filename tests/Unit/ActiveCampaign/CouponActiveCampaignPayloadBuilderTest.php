<?php

use App\Enums\CouponType;
use App\Models\Coupon;
use App\Models\CouponTransaction;
use App\Models\CouponUser;
use App\Models\LaboratoryPurchase;
use App\Models\User;
use App\Services\ActiveCampaign\CouponActiveCampaignPayloadBuilder;
use App\Services\CouponService;

test('credit_assigned payload no contiene datos sensibles', function () {
    $couponService = Mockery::mock(CouponService::class);
    $couponService->shouldReceive('buildCheckoutCreditPresentation')
        ->with(10, 0)
        ->andReturn([
            'total_balance_cents' => 50000,
            'applicable_balance_cents' => 50000,
            'conditional_balance_cents' => 0,
        ]);

    $builder = new CouponActiveCampaignPayloadBuilder($couponService);

    $user = new User(['id' => 10, 'email' => 'u@example.com']);
    $coupon = new Coupon([
        'id' => 1,
        'amount_cents' => 50000,
        'remaining_cents' => 50000,
        'type' => CouponType::Balance,
    ]);
    $assignment = new CouponUser(['assigned_at' => now()]);
    $assignment->id = 9;

    $payload = $builder->creditAssigned($coupon, $assignment, $user, 'individual');

    expect($payload['event_type'])->toBe('credit_assigned');
    expect($payload['email'])->toBe('u@example.com');
    expect($payload['coupon_user_id'])->toBe(9);
    expect($payload)->not->toHaveKey('validation_token');
    expect($payload)->not->toHaveKey('otp');
    expect($payload)->not->toHaveKey('authorization_code');
});

test('credit_redeemed usa idempotency por coupon_transaction', function () {
    $builder = new CouponActiveCampaignPayloadBuilder(Mockery::mock(CouponService::class));

    expect($builder->idempotencyKeyForRedeemed(77))->toBe('credit_redeemed:coupon_transaction:77');
});

test('credit_restored marca usable cuando el cupón sigue vigente', function () {
    $couponService = Mockery::mock(CouponService::class);
    $couponService->shouldReceive('buildCheckoutCreditPresentation')->andReturn([
        'total_balance_cents' => 50000,
        'applicable_balance_cents' => 50000,
        'conditional_balance_cents' => 0,
    ]);

    $builder = new CouponActiveCampaignPayloadBuilder($couponService);

    $user = new User(['id' => 10, 'email' => 'u@example.com']);
    $coupon = new Coupon([
        'id' => 1,
        'amount_cents' => 50000,
        'remaining_cents' => 50000,
        'is_active' => true,
        'type' => CouponType::Balance,
        'expires_at' => now()->addMonth(),
    ]);
    $assignment = new CouponUser(['id' => 9]);
    $transaction = new CouponTransaction(['id' => 44, 'amount_used_cents' => 50000, 'reversed_at' => now()]);
    $purchase = new LaboratoryPurchase(['id' => 200, 'total_cents' => 60000]);

    $payload = $builder->creditRestored($coupon, $assignment, $transaction, $user, $purchase, 'cancelled');

    expect($payload['is_usable_after_restore'])->toBeTrue();
    expect($payload['event_type'])->toBe('credit_restored');
});

test('credit_restored vencido no queda usable', function () {
    $couponService = Mockery::mock(CouponService::class);
    $couponService->shouldReceive('buildCheckoutCreditPresentation')->andReturn([
        'total_balance_cents' => 0,
        'applicable_balance_cents' => 0,
        'conditional_balance_cents' => 0,
    ]);

    $builder = new CouponActiveCampaignPayloadBuilder($couponService);

    $user = new User(['id' => 10, 'email' => 'u@example.com']);
    $coupon = new Coupon([
        'id' => 1,
        'amount_cents' => 50000,
        'remaining_cents' => 50000,
        'is_active' => true,
        'type' => CouponType::Balance,
        'expires_at' => now()->subDay(),
    ]);
    $assignment = new CouponUser(['id' => 9]);
    $transaction = new CouponTransaction(['id' => 44, 'amount_used_cents' => 50000, 'reversed_at' => now()]);
    $purchase = new LaboratoryPurchase(['id' => 200, 'total_cents' => 60000]);

    $payload = $builder->creditRestored($coupon, $assignment, $transaction, $user, $purchase, 'cancelled');

    expect($payload['is_usable_after_restore'])->toBeFalse();
});
