<?php

use App\Actions\Laboratories\RecoverUncertainGdaLaboratoryPurchaseAction;
use App\Enums\GdaOrderStatus;
use App\Enums\Gender;
use App\Enums\LaboratoryBrand;
use App\Enums\MonitoringCartStatus;
use App\Models\Administrator;
use App\Models\Coupon;
use App\Models\CouponUser;
use App\Models\LaboratoryAppointment;
use App\Models\LaboratoryPurchase;
use App\Models\LaboratoryPurchaseItem;
use App\Models\LaboratoryStore;
use App\Models\LaboratoryTest;
use App\Models\Permission;
use App\Models\Transaction;
use App\Models\User;
use Illuminate\Support\Facades\Notification;
use Spatie\Permission\Models\Role;

function recoverGdaCustomer(): \App\Models\Customer
{
    return User::factory()
        ->withCompleteProfile()
        ->has(\App\Models\Customer::factory())
        ->create(['documentation_accepted_at' => now()])
        ->fresh(['customer'])
        ->customer;
}

function recoverGdaAdmin(): User
{
    Permission::firstOrCreate([
        'name' => 'laboratory-purchases.manage.recover-gda',
        'guard_name' => 'web',
    ]);
    Permission::firstOrCreate([
        'name' => 'laboratory-purchases.manage',
        'guard_name' => 'web',
    ]);

    $user = User::factory()->create();
    $administrator = Administrator::factory()->create(['user_id' => $user->id]);
    $administrator->givePermissionTo('laboratory-purchases.manage.recover-gda');
    $administrator->givePermissionTo('laboratory-purchases.manage');

    return $user;
}

function recoverGdaSuperadmin(): User
{
    Permission::firstOrCreate([
        'name' => 'laboratory-purchases.manage.recover-gda',
        'guard_name' => 'web',
    ]);

    $user = User::factory()->create();
    $administrator = Administrator::factory()->create(['user_id' => $user->id]);
    $role = Role::firstOrCreate(['name' => 'superadmin', 'guard_name' => 'web']);
    $administrator->assignRole($role);
    $administrator->givePermissionTo('laboratory-purchases.manage.recover-gda');

    return $user;
}

function recoverGdaUncertainPurchase(
    \App\Models\Customer $customer,
    LaboratoryTest $test,
    int $totalCents = 39900,
): LaboratoryPurchase {
    $purchase = LaboratoryPurchase::create([
        'customer_id' => $customer->id,
        'gda_order_id' => '0',
        'gda_status' => GdaOrderStatus::Uncertain,
        'brand' => $test->brand,
        'name' => 'Paciente',
        'paternal_lastname' => 'Prueba',
        'maternal_lastname' => 'GDA',
        'phone' => '8180000000',
        'phone_country' => 'MX',
        'birth_date' => '1990-01-01',
        'gender' => Gender::MALE,
        'street' => 'Calle',
        'number' => '1',
        'neighborhood' => 'Centro',
        'state' => 'Nuevo León',
        'city' => 'Monterrey',
        'zipcode' => '64000',
        'total_cents' => $totalCents,
        'has_gda_warning' => true,
    ]);

    LaboratoryPurchaseItem::create([
        'laboratory_purchase_id' => $purchase->id,
        'name' => $test->name,
        'description' => $test->description,
        'gda_id' => $test->gda_id,
        'price_cents' => $totalCents,
    ]);

    $transaction = Transaction::factory()->create([
        'transaction_amount_cents' => $totalCents,
        'payment_method' => 'efevoopay',
        'payment_status' => 'completed',
        'gateway_status' => 'completed',
    ]);

    $purchase->transactions()->attach($transaction);

    return $purchase->fresh(['customer.user', 'laboratoryPurchaseItems', 'transactions']);
}

function recoverGdaBalanceCoupon(User $user, int $amountCents): Coupon
{
    $coupon = Coupon::factory()->create([
        'remaining_cents' => $amountCents,
        'amount_cents' => $amountCents,
    ]);

    CouponUser::create([
        'coupon_id' => $coupon->id,
        'user_id' => $user->id,
        'assigned_at' => now(),
    ]);

    return $coupon;
}

test('superadmin can recover uncertain gda purchase with balance coupon', function () {
    Notification::fake();

    $customer = recoverGdaCustomer();
    $test = LaboratoryTest::factory()->create([
        'brand' => LaboratoryBrand::OLAB->value,
        'requires_appointment' => false,
        'famedic_price_cents' => 39900,
        'gda_id' => 'LAB-RECOVER-1',
    ]);
    $purchase = recoverGdaUncertainPurchase($customer, $test);
    $coupon = recoverGdaBalanceCoupon($customer->user, 39900);
    $admin = recoverGdaSuperadmin();

    $this->mock(\App\Actions\Laboratories\CreateGDAQuotationAction::class, function ($mock) {
        $mock->shouldReceive('__invoke')
            ->once()
            ->andReturn([
                'id' => 'GZ0L000999',
                'infogda_consecutivo' => 25273599,
            ]);
    });

    $response = $this->actingAs($admin)->post(route('admin.laboratory-purchases.recover-gda', $purchase), [
        'coupon_id' => $coupon->id,
    ]);

    $response->assertRedirect(route('admin.laboratory-purchases.show', $purchase));

    $purchase->refresh();

    expect($purchase->gda_status)->toBe(GdaOrderStatus::Confirmed)
        ->and($purchase->gda_order_id)->toBe('GZ0L000999')
        ->and($purchase->gda_consecutivo)->toBe(25273599)
        ->and((int) $purchase->coupon_discount_cents)->toBe(39900)
        ->and($coupon->fresh()->remaining_cents)->toBe(0);

    Notification::assertNothingSent();
});

test('recover action clones previous appointment automatically', function () {
    Notification::fake();

    $customer = recoverGdaCustomer();
    $test = LaboratoryTest::factory()->create([
        'brand' => LaboratoryBrand::OLAB->value,
        'requires_appointment' => true,
        'famedic_price_cents' => 39900,
        'gda_id' => 'LAB-RECOVER-APPT',
    ]);
    $purchase = recoverGdaUncertainPurchase($customer, $test);
    $coupon = recoverGdaBalanceCoupon($customer->user, 39900);

    $store = LaboratoryStore::factory()->create(['brand' => LaboratoryBrand::OLAB->value]);
    $originalAppointment = LaboratoryAppointment::query()->create([
        'customer_id' => $customer->id,
        'brand' => LaboratoryBrand::OLAB,
        'laboratory_purchase_id' => $purchase->id,
        'laboratory_store_id' => $store->id,
        'patient_name' => 'Ana',
        'patient_paternal_lastname' => 'Lopez',
        'patient_maternal_lastname' => 'Perez',
        'patient_birth_date' => '1990-01-01',
        'patient_gender' => Gender::FEMALE,
        'patient_phone' => '8111111111',
        'patient_phone_country' => 'MX',
        'appointment_date' => now('America/Monterrey')->addDays(2),
        'confirmed_at' => now(),
    ]);

    $this->mock(\App\Actions\Laboratories\CreateGDAQuotationAction::class, function ($mock) {
        $mock->shouldReceive('__invoke')->once()->andReturn([
            'id' => 'GZ0L000888',
            'infogda_consecutivo' => 25273588,
        ]);
    });

    app(RecoverUncertainGdaLaboratoryPurchaseAction::class)(
        $purchase->fresh(['laboratoryAppointment']),
        $coupon->id,
        recoverGdaAdmin(),
    );

    $purchase->refresh();
    $originalAppointment->refresh();

    expect($purchase->gda_status)->toBe(GdaOrderStatus::Confirmed)
        ->and($originalAppointment->laboratory_purchase_id)->toBeNull()
        ->and($purchase->laboratoryAppointment)->not->toBeNull()
        ->and($purchase->laboratoryAppointment->id)->not->toBe($originalAppointment->id)
        ->and($purchase->laboratoryAppointment->laboratory_store_id)->toBe($store->id);
});

test('legacy purchase without gda folio is eligible for recovery', function () {
    $customer = recoverGdaCustomer();
    $test = LaboratoryTest::factory()->create([
        'brand' => LaboratoryBrand::OLAB->value,
        'famedic_price_cents' => 39900,
        'gda_id' => 'LAB-RECOVER-LEGACY',
    ]);
    $purchase = recoverGdaUncertainPurchase($customer, $test);
    $purchase->update([
        'gda_status' => null,
        'gda_order_id' => '0',
        'gda_consecutivo' => null,
    ]);
    recoverGdaBalanceCoupon($customer->user, 39900);

    expect(app(RecoverUncertainGdaLaboratoryPurchaseAction::class)->needsGdaRecovery($purchase->fresh()))->toBeTrue()
        ->and(app(RecoverUncertainGdaLaboratoryPurchaseAction::class)->canRecover($purchase->fresh()))->toBeTrue();
});

test('cannot recover purchase when gda is already confirmed', function () {
    $customer = recoverGdaCustomer();
    $test = LaboratoryTest::factory()->create([
        'brand' => LaboratoryBrand::OLAB->value,
        'famedic_price_cents' => 39900,
        'gda_id' => 'LAB-RECOVER-2',
    ]);
    $purchase = recoverGdaUncertainPurchase($customer, $test);
    $purchase->update([
        'gda_status' => GdaOrderStatus::Confirmed,
        'gda_order_id' => 'GZ0L000001',
        'gda_consecutivo' => 12345678,
    ]);
    recoverGdaBalanceCoupon($customer->user, 39900);

    expect(app(RecoverUncertainGdaLaboratoryPurchaseAction::class)->canRecover($purchase->fresh()))->toBeFalse();
});

test('admin without permission cannot recover gda purchase', function () {
    $customer = recoverGdaCustomer();
    $test = LaboratoryTest::factory()->create([
        'brand' => LaboratoryBrand::OLAB->value,
        'famedic_price_cents' => 39900,
        'gda_id' => 'LAB-RECOVER-3',
    ]);
    $purchase = recoverGdaUncertainPurchase($customer, $test);
    $coupon = recoverGdaBalanceCoupon($customer->user, 39900);

    $admin = User::factory()->create();
    Administrator::factory()->create(['user_id' => $admin->id]);

    $this->actingAs($admin)->post(route('admin.laboratory-purchases.recover-gda', $purchase), [
        'coupon_id' => $coupon->id,
    ])->assertForbidden();
});
