<?php

use App\Enums\Gender;
use App\Enums\LaboratoryBrand;
use App\Models\Administrator;
use App\Models\CouponAuditLog;
use App\Models\CouponTransaction;
use App\Models\LaboratoryPurchase;
use App\Models\Permission;
use App\Models\User;
use App\Notifications\CustomerLaboratoryPurchaseDeleted;
use App\Notifications\GDALaboratoryPurchaseDeleted;
use Illuminate\Support\Facades\Notification;

require __DIR__.'/couponReversalHelpers.php';

test('getCouponReversalSummary expone datos del reverso en pedido cancelado', function () {
    ['purchase' => $purchase, 'coupon' => $coupon] = createConsumedCouponLabPurchase(80_000, 30_000, null);

    app(\App\Services\CouponApplicationService::class)->reverseForLaboratoryPurchase($purchase);

    $summary = $purchase->fresh()->getCouponReversalSummary();

    expect($summary)->not->toBeNull()
        ->and($summary['amount_restored_cents'])->toBe(30_000)
        ->and($summary['coupon_id'])->toBe($coupon->id)
        ->and($summary['reversal_reason'])->toBe('laboratory_purchase_cancelled')
        ->and($summary['formatted_amount_restored'])->not->toBeEmpty();
});

test('pedido sin reverso no expone couponReversal summary', function () {
    ['purchase' => $purchase] = createConsumedCouponLabPurchase(50_000, 20_000, null);

    expect($purchase->getCouponReversalSummary())->toBeNull();
});

test('transacción revertida permanece en historial del cupón', function () {
    ['purchase' => $purchase, 'coupon' => $coupon, 'couponTransaction' => $couponTransaction] =
        createConsumedCouponLabPurchase(60_000, 25_000, null);

    app(\App\Services\CouponApplicationService::class)->reverseForLaboratoryPurchase($purchase);

    $transactions = CouponTransaction::query()
        ->where('coupon_id', $coupon->id)
        ->get();

    expect($transactions)->toHaveCount(1)
        ->and($transactions->first()->id)->toBe($couponTransaction->id)
        ->and($transactions->first()->reversed_at)->not->toBeNull();
});

test('notificación al cliente incluye saldo restaurado cuando aplica', function () {
    $purchase = LaboratoryPurchase::make([
        'gda_order_id' => '12345',
        'name' => 'Ana',
        'paternal_lastname' => 'Test',
        'maternal_lastname' => 'User',
        'total_cents' => 100_000,
    ]);
    $purchase->setAttribute('formatted_total', '$1,000.00');

    $mail = (new CustomerLaboratoryPurchaseDeleted($purchase, 40_000))->toMail(new User());

    $rendered = implode("\n", $mail->introLines);

    expect($rendered)->toContain('Tu saldo a favor aplicado a este pedido fue restaurado.')
        ->and($rendered)->toContain('Saldo restaurado:');
});

test('notificación al cliente sin cupón no menciona saldo restaurado', function () {
    $purchase = LaboratoryPurchase::make([
        'gda_order_id' => '12345',
        'name' => 'Ana',
        'paternal_lastname' => 'Test',
        'maternal_lastname' => 'User',
        'total_cents' => 100_000,
    ]);
    $purchase->setAttribute('formatted_total', '$1,000.00');

    $mail = (new CustomerLaboratoryPurchaseDeleted($purchase, 0))->toMail(new User());

    $rendered = implode("\n", $mail->introLines);

    expect($rendered)->not->toContain('saldo a favor aplicado a este pedido fue restaurado');
});

test('notificación GDA incluye saldo restaurado cuando aplica', function () {
    $purchase = LaboratoryPurchase::make([
        'gda_order_id' => '99999',
        'name' => 'Luis',
        'paternal_lastname' => 'Demo',
        'maternal_lastname' => 'User',
        'total_cents' => 50_000,
    ]);
    $purchase->setAttribute('formatted_total', '$500.00');

    $mail = (new GDALaboratoryPurchaseDeleted($purchase, 15_000))->toMail(new User());

    $rendered = implode("\n", $mail->introLines);

    expect($rendered)->toContain('El saldo a favor aplicado a este pedido fue restaurado al cliente.')
        ->and($rendered)->toContain('Saldo restaurado:');
});

test('cancelación con cupón envía notificación con monto restaurado', function () {
    Notification::fake();

    $admin = User::factory()->create();
    ['user' => $customerUser, 'purchase' => $purchase] =
        createConsumedCouponLabPurchase(100_000, 35_000, 'coupon_balance');

    $this->mock(\App\Actions\Transactions\RefundTransactionAction::class, function ($mock) {
        $mock->shouldReceive('__invoke')->once()->andReturn(true);
    });

    app(\App\Actions\Laboratories\DeleteLaboratoryPurchaseAction::class)(
        $purchase->fresh(['transactions']),
        $admin
    );

    Notification::assertSentTo(
        $customerUser,
        CustomerLaboratoryPurchaseDeleted::class,
        function (CustomerLaboratoryPurchaseDeleted $notification) use ($customerUser) {
            $mail = $notification->toMail($customerUser);
            $rendered = implode("\n", $mail->introLines);

            return str_contains($rendered, 'Tu saldo a favor aplicado a este pedido fue restaurado.')
                && str_contains($rendered, 'Saldo restaurado:');
        }
    );
});

test('auditoría reverse_coupon_application queda disponible para logs', function () {
    $admin = User::factory()->create();
    ['purchase' => $purchase] = createConsumedCouponLabPurchase(70_000, 20_000, null);

    app(\App\Services\CouponApplicationService::class)->reverseForLaboratoryPurchase(
        $purchase,
        $admin
    );

    $log = CouponAuditLog::query()
        ->where('action', 'reverse_coupon_application')
        ->first();

    expect($log)->not->toBeNull()
        ->and($log->type)->toBe('application')
        ->and($log->context['purchase_id'])->toBe($purchase->id)
        ->and($log->context['amount_restored_cents'])->toBe(20_000);
});

test('admin puede ver detalle de cupón con transacción revertida en props', function () {
    $permission = Permission::firstOrCreate(['name' => 'cupones.view', 'guard_name' => 'web']);
    $adminUser = User::factory()->create();
    $administrator = Administrator::factory()->for($adminUser)->create();
    $administrator->givePermissionTo($permission);

    ['purchase' => $purchase, 'coupon' => $coupon] =
        createConsumedCouponLabPurchase(90_000, 45_000, null);

    app(\App\Services\CouponApplicationService::class)->reverseForLaboratoryPurchase($purchase);

    $response = $this->actingAs($adminUser)->get(route('admin.coupons.show', $coupon));

    $response->assertOk();
    $response->assertInertia(fn ($page) => $page
        ->component('Admin/Coupons/Show')
        ->has('beneficiaryRows', 1)
        ->where('beneficiaryRows.0.transaction.is_reversed', true)
        ->where('beneficiaryRows.0.transaction.amount_used_cents', 45_000)
    );
});

test('admin pedido cancelado recibe prop couponReversal', function () {
    $permission = Permission::firstOrCreate(['name' => 'laboratory-purchases.manage', 'guard_name' => 'web']);
    $adminUser = User::factory()->create();
    $administrator = Administrator::factory()->for($adminUser)->create();
    $administrator->givePermissionTo($permission);

    ['purchase' => $purchase] = createConsumedCouponLabPurchase(55_000, 22_000, null);

    app(\App\Services\CouponApplicationService::class)->reverseForLaboratoryPurchase($purchase);

    $response = $this->actingAs($adminUser)->get(
        route('admin.laboratory-purchases.show', $purchase)
    );

    $response->assertOk();
    $response->assertInertia(fn ($page) => $page
        ->component('Admin/LaboratoryPurchase')
        ->has('couponReversal')
        ->where('couponReversal.amount_restored_cents', 22_000)
    );
});

test('admin pedido sin cupón no recibe couponReversal', function () {
    $permission = Permission::firstOrCreate(['name' => 'laboratory-purchases.manage', 'guard_name' => 'web']);
    $adminUser = User::factory()->create();
    $administrator = Administrator::factory()->for($adminUser)->create();
    $administrator->givePermissionTo($permission);

    $user = User::factory()->withRegularCustomer()->create();
    $purchase = LaboratoryPurchase::create([
        'brand' => LaboratoryBrand::OLAB->value,
        'gda_order_id' => '888001',
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
        'total_cents' => 40_000,
        'customer_id' => $user->customer->id,
    ]);

    $response = $this->actingAs($adminUser)->get(
        route('admin.laboratory-purchases.show', $purchase)
    );

    $response->assertOk();
    $response->assertInertia(fn ($page) => $page
        ->component('Admin/LaboratoryPurchase')
        ->where('couponReversal', null)
    );
});
