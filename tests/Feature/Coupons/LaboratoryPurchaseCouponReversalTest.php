<?php

use App\Actions\Coupons\ReverseCouponBalanceForLaboratoryPurchaseAction;
use App\Actions\Laboratories\DeleteLaboratoryPurchaseAction;
use App\Actions\Transactions\RefundTransactionAction;
use App\Enums\CouponPurchaseType;
use App\Enums\Gender;
use App\Enums\LaboratoryBrand;
use App\Exceptions\CouponReversalException;
use App\Models\Coupon;
use App\Models\CouponAuditLog;
use App\Models\CouponTransaction;
use App\Models\CouponUser;
use App\Models\LaboratoryPurchase;
use App\Models\LaboratoryPurchaseItem;
use App\Models\Transaction;
use App\Models\User;
use App\Services\CouponApplicationService;

require __DIR__.'/couponReversalHelpers.php';

test('cancelación con cupón 100% coupon_balance restaura saldo y marca reverso', function () {
    $admin = User::factory()->create();
    ['user' => $customerUser, 'purchase' => $purchase, 'coupon' => $coupon, 'couponTransaction' => $couponTransaction] =
        createConsumedCouponLabPurchase(100_000, 100_000, 'coupon_balance');

    $this->mock(RefundTransactionAction::class, function ($mock) {
        $mock->shouldReceive('__invoke')->once()->andReturn(true);
    });

    app(DeleteLaboratoryPurchaseAction::class)($purchase->fresh(['transactions']), $admin);

    $coupon->refresh();
    $couponTransaction->refresh();
    $assignment = CouponUser::query()->where('coupon_id', $coupon->id)->first();
    $purchase->refresh();

    expect($coupon->remaining_cents)->toBe(100_000)
        ->and($assignment->used_at)->toBeNull()
        ->and($couponTransaction->reversed_at)->not->toBeNull()
        ->and($couponTransaction->reversal_reason)->toBe('laboratory_purchase_cancelled')
        ->and($couponTransaction->reversed_by_user_id)->toBe($admin->id)
        ->and($purchase->coupon_discount_cents)->toBe(100_000)
        ->and($purchase->trashed())->toBeTrue();

    expect(CouponAuditLog::query()->where('action', 'reverse_coupon_application')->exists())->toBeTrue();
});

test('cancelación mixta cupón y pago real restaura cupón sin alterar coupon_discount_cents', function () {
    ['purchase' => $purchase, 'coupon' => $coupon] =
        createConsumedCouponLabPurchase(100_000, 40_000, 'efevoopay');

    $this->mock(RefundTransactionAction::class, function ($mock) {
        $mock->shouldReceive('__invoke')->once()->andReturn(true);
    });

    app(DeleteLaboratoryPurchaseAction::class)($purchase->fresh(['transactions']));

    $coupon->refresh();
    $purchase->refresh();

    expect($coupon->remaining_cents)->toBe(40_000)
        ->and($purchase->coupon_discount_cents)->toBe(40_000)
        ->and($purchase->trashed())->toBeTrue();
});

test('cancelación sin cupón continúa normalmente', function () {
    $user = User::factory()->withRegularCustomer()->create();
    $purchase = LaboratoryPurchase::create([
        'brand' => LaboratoryBrand::OLAB->value,
        'gda_order_id' => '999001',
        'name' => 'Paciente',
        'paternal_lastname' => 'Sin',
        'maternal_lastname' => 'Cupon',
        'phone' => '8112345678',
        'phone_country' => 'MX',
        'birth_date' => '1990-01-01',
        'gender' => Gender::MALE->value,
        'street' => 'Calle',
        'number' => '1',
        'neighborhood' => 'Centro',
        'state' => 'NL',
        'city' => 'Monterrey',
        'zipcode' => '64000',
        'total_cents' => 50_000,
        'customer_id' => $user->customer->id,
    ]);

    $transaction = Transaction::create([
        'transaction_amount_cents' => 50_000,
        'payment_method' => 'efevoopay',
        'gateway' => 'efevoopay',
        'reference_id' => 'TEST-NO-COUPON',
        'gateway_status' => 'completed',
    ]);
    $purchase->transactions()->attach($transaction);

    LaboratoryPurchaseItem::create([
        'laboratory_purchase_id' => $purchase->id,
        'name' => 'Estudio',
        'indications' => 'N/A',
        'gda_id' => 'GDA-2',
        'price_cents' => 50_000,
    ]);

    $this->mock(RefundTransactionAction::class, function ($mock) {
        $mock->shouldReceive('__invoke')->once()->andReturn(true);
    });

    app(DeleteLaboratoryPurchaseAction::class)($purchase->fresh(['transactions']));

    expect($purchase->fresh()->trashed())->toBeTrue();
});

test('doble reverso es idempotente y no duplica saldo', function () {
    ['purchase' => $purchase, 'coupon' => $coupon] =
        createConsumedCouponLabPurchase(80_000, 30_000, null);

    $service = app(CouponApplicationService::class);

    $restored = $service->reverseForLaboratoryPurchase($purchase);
    $second = $service->reverseForLaboratoryPurchase($purchase->fresh());

    $coupon->refresh();

    expect($restored)->toBe(30_000)
        ->and($second)->toBe(0)
        ->and($coupon->remaining_cents)->toBe(30_000);
});

test('refund monetario fallido no revierte cupón ni elimina pedido', function () {
    ['purchase' => $purchase, 'coupon' => $coupon] =
        createConsumedCouponLabPurchase(100_000, 50_000, 'efevoopay');

    $this->mock(RefundTransactionAction::class, function ($mock) {
        $mock->shouldReceive('__invoke')->once()->andReturn(false);
    });

    expect(fn () => app(DeleteLaboratoryPurchaseAction::class)($purchase->fresh(['transactions'])))
        ->toThrow(Exception::class);

    $coupon->refresh();
    $purchase->refresh();

    expect($coupon->remaining_cents)->toBe(0)
        ->and($purchase->trashed())->toBeFalse()
        ->and(CouponTransaction::query()->whereNull('reversed_at')->exists())->toBeTrue();
});

test('inconsistencia coupon_discount sin coupon_transaction aborta reverso', function () {
    $user = User::factory()->withRegularCustomer()->create();
    $purchase = LaboratoryPurchase::create([
        'brand' => LaboratoryBrand::OLAB->value,
        'gda_order_id' => '999002',
        'name' => 'Paciente',
        'paternal_lastname' => 'Incon',
        'maternal_lastname' => 'Sistente',
        'phone' => '8112345678',
        'phone_country' => 'MX',
        'birth_date' => '1990-01-01',
        'gender' => Gender::MALE->value,
        'street' => 'Calle',
        'number' => '1',
        'neighborhood' => 'Centro',
        'state' => 'NL',
        'city' => 'Monterrey',
        'zipcode' => '64000',
        'total_cents' => 60_000,
        'coupon_discount_cents' => 10_000,
        'customer_id' => $user->customer->id,
    ]);

    expect(fn () => app(ReverseCouponBalanceForLaboratoryPurchaseAction::class)($purchase))
        ->toThrow(CouponReversalException::class);
});

test('reverso fallido aborta cancelación del pedido', function () {
    ['purchase' => $purchase, 'coupon' => $coupon] =
        createConsumedCouponLabPurchase(100_000, 25_000, 'efevoopay');

    $this->mock(RefundTransactionAction::class, function ($mock) {
        $mock->shouldReceive('__invoke')->once()->andReturn(true);
    });

    $this->mock(ReverseCouponBalanceForLaboratoryPurchaseAction::class, function ($mock) {
        $mock->shouldReceive('__invoke')
            ->once()
            ->andThrow(new CouponReversalException('Fallo simulado de reverso'));
    });

    expect(fn () => app(DeleteLaboratoryPurchaseAction::class)($purchase->fresh(['transactions'])))
        ->toThrow(CouponReversalException::class);

    $coupon->refresh();
    $purchase->refresh();

    expect($coupon->remaining_cents)->toBe(0)
        ->and($purchase->trashed())->toBeFalse();
});
