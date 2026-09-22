<?php

use App\Actions\Laboratories\CreateReplacementGdaLaboratoryPurchaseAction;
use App\Enums\GdaOrderStatus;
use App\Enums\Gender;
use App\Enums\LaboratoryBrand;
use App\Enums\MonitoringCartStatus;
use App\Enums\MonitoringCartType;
use App\Models\Cart;
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

function replaceGdaCustomer(): \App\Models\Customer
{
    return User::factory()
        ->withCompleteProfile()
        ->has(\App\Models\Customer::factory())
        ->create(['documentation_accepted_at' => now()])
        ->fresh(['customer'])
        ->customer;
}

function replaceGdaAdmin(): User
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

function replaceGdaSuperadmin(): User
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

function replaceGdaUncertainPurchase(
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

function replaceGdaBalanceCoupon(User $user, int $amountCents): Coupon
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

test('superadmin can create replacement gda purchase with new id', function () {
    Notification::fake();

    $customer = replaceGdaCustomer();
    $test = LaboratoryTest::factory()->create([
        'brand' => LaboratoryBrand::OLAB->value,
        'requires_appointment' => false,
        'famedic_price_cents' => 39900,
        'gda_id' => 'LAB-REPLACE-1',
    ]);
    $source = replaceGdaUncertainPurchase($customer, $test);
    $coupon = replaceGdaBalanceCoupon($customer->user, 39900);
    $admin = replaceGdaSuperadmin();

    $this->mock(\App\Actions\Laboratories\CreateGDAQuotationAction::class, function ($mock) use ($source) {
        $mock->shouldReceive('__invoke')
            ->once()
            ->withArgs(function (...$args) use ($source) {
                return (int) $args[5] !== (int) $source->id;
            })
            ->andReturn([
                'id' => 'GZ0L001111',
                'infogda_consecutivo' => 25273611,
            ]);
    });

    $response = $this->actingAs($admin)->post(route('admin.laboratory-purchases.replace-gda', $source), [
        'coupon_id' => $coupon->id,
    ]);

    $source->refresh();
    $replacement = LaboratoryPurchase::query()
        ->where('replaces_laboratory_purchase_id', $source->id)
        ->first();

    expect($replacement)->not->toBeNull()
        ->and($replacement->id)->not->toBe($source->id)
        ->and($replacement->gda_status)->toBe(GdaOrderStatus::Confirmed)
        ->and($replacement->gda_order_id)->toBe('GZ0L001111')
        ->and((int) $replacement->coupon_discount_cents)->toBe(39900)
        ->and($source->replacement_laboratory_purchase_id)->toBe($replacement->id)
        ->and($source->transactions)->toHaveCount(1)
        ->and($replacement->transactions)->toHaveCount(0);

    $response->assertRedirect(route('admin.laboratory-purchases.show', $replacement));

    Notification::assertNothingSent();
});

test('replacement action clones items and appointment to new purchase', function () {
    Notification::fake();

    $customer = replaceGdaCustomer();
    $test = LaboratoryTest::factory()->create([
        'brand' => LaboratoryBrand::OLAB->value,
        'requires_appointment' => true,
        'famedic_price_cents' => 39900,
        'gda_id' => 'LAB-REPLACE-APPT',
    ]);
    $source = replaceGdaUncertainPurchase($customer, $test);
    $coupon = replaceGdaBalanceCoupon($customer->user, 39900);

    $store = LaboratoryStore::factory()->create(['brand' => LaboratoryBrand::OLAB->value]);
    $originalAppointment = LaboratoryAppointment::query()->create([
        'customer_id' => $customer->id,
        'brand' => LaboratoryBrand::OLAB,
        'laboratory_purchase_id' => $source->id,
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
            'id' => 'GZ0L001222',
            'infogda_consecutivo' => 25273622,
        ]);
    });

    $replacement = app(CreateReplacementGdaLaboratoryPurchaseAction::class)(
        $source->fresh(['laboratoryAppointment']),
        $coupon->id,
        replaceGdaAdmin(),
    );

    $originalAppointment->refresh();

    expect($replacement->laboratoryPurchaseItems)->toHaveCount(1)
        ->and($replacement->laboratoryAppointment)->not->toBeNull()
        ->and($replacement->laboratoryAppointment->id)->not->toBe($originalAppointment->id)
        ->and($originalAppointment->laboratory_purchase_id)->toBeNull();
});

test('cannot replace purchase that was already replaced', function () {
    $customer = replaceGdaCustomer();
    $test = LaboratoryTest::factory()->create([
        'brand' => LaboratoryBrand::OLAB->value,
        'famedic_price_cents' => 39900,
        'gda_id' => 'LAB-REPLACE-2',
    ]);
    $source = replaceGdaUncertainPurchase($customer, $test);
    replaceGdaBalanceCoupon($customer->user, 39900);

    $replacement = LaboratoryPurchase::create([
        'customer_id' => $customer->id,
        'gda_order_id' => 'GZ0L000001',
        'gda_status' => GdaOrderStatus::Confirmed,
        'brand' => LaboratoryBrand::OLAB,
        'name' => $source->name,
        'paternal_lastname' => $source->paternal_lastname,
        'maternal_lastname' => $source->maternal_lastname,
        'phone' => $source->phone,
        'phone_country' => $source->phone_country,
        'birth_date' => $source->birth_date,
        'gender' => $source->gender,
        'street' => $source->street,
        'number' => $source->number,
        'neighborhood' => $source->neighborhood,
        'state' => $source->state,
        'city' => $source->city,
        'zipcode' => $source->zipcode,
        'total_cents' => $source->total_cents,
        'replaces_laboratory_purchase_id' => $source->id,
    ]);

    $source->update(['replacement_laboratory_purchase_id' => $replacement->id]);

    expect(app(CreateReplacementGdaLaboratoryPurchaseAction::class)->canReplace($source->fresh()))->toBeFalse();
});

test('replacement resolves appointment from cart_id when purchase link is missing', function () {
    Notification::fake();

    $customer = replaceGdaCustomer();
    $test = LaboratoryTest::factory()->create([
        'brand' => LaboratoryBrand::OLAB->value,
        'requires_appointment' => true,
        'famedic_price_cents' => 39900,
        'gda_id' => 'LAB-REPLACE-CART',
    ]);
    $source = replaceGdaUncertainPurchase($customer, $test);
    $coupon = replaceGdaBalanceCoupon($customer->user, 39900);

    $cart = Cart::query()->create([
        'user_id' => $customer->user_id,
        'type' => MonitoringCartType::Lab,
        'status' => MonitoringCartStatus::Active,
        'total' => 399,
    ]);

    if (\Illuminate\Support\Facades\Schema::hasColumn('laboratory_purchases', 'cart_id')) {
        $source->update(['cart_id' => $cart->id]);
    }

    $store = LaboratoryStore::factory()->create(['brand' => LaboratoryBrand::OLAB->value]);
    $orphanAppointment = LaboratoryAppointment::query()->create([
        'customer_id' => $customer->id,
        'brand' => LaboratoryBrand::OLAB,
        'cart_id' => $cart->id,
        'laboratory_purchase_id' => null,
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
            'id' => 'GZ0L001333',
            'infogda_consecutivo' => 25273633,
        ]);
    });

    $replacement = app(CreateReplacementGdaLaboratoryPurchaseAction::class)(
        $source->fresh(),
        $coupon->id,
        replaceGdaAdmin(),
    );

    expect($replacement->laboratoryAppointment)->not->toBeNull()
        ->and($replacement->laboratoryAppointment->id)->not->toBe($orphanAppointment->id)
        ->and($replacement->laboratoryAppointment->laboratory_store_id)->toBe($store->id);
});

test('admin without permission cannot replace gda purchase', function () {
    $customer = replaceGdaCustomer();
    $test = LaboratoryTest::factory()->create([
        'brand' => LaboratoryBrand::OLAB->value,
        'famedic_price_cents' => 39900,
        'gda_id' => 'LAB-REPLACE-3',
    ]);
    $source = replaceGdaUncertainPurchase($customer, $test);
    $coupon = replaceGdaBalanceCoupon($customer->user, 39900);

    $admin = User::factory()->create();
    Administrator::factory()->create(['user_id' => $admin->id]);

    $this->actingAs($admin)->post(route('admin.laboratory-purchases.replace-gda', $source), [
        'coupon_id' => $coupon->id,
    ])->assertForbidden();
});
