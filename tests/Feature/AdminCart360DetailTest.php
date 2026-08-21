<?php

use App\Enums\LaboratoryBrand;
use App\Enums\MonitoringCartStatus;
use App\Enums\MonitoringCartType;
use App\Models\Cart;
use App\Models\CartItem;
use App\Models\LaboratoryAppointment;
use App\Models\LaboratoryPurchase;
use App\Models\LaboratoryStore;
use App\Models\LaboratoryTest;
use App\Models\PaymentAttempt;
use App\Models\User;
use Spatie\Permission\Models\Permission;

function cart360AdminUserWithCartDetailPermission(): User
{
    Permission::findOrCreate('view cart details', 'web');

    $user = User::factory()->withAdministrator()->create();
    $user->administrator->givePermissionTo('view cart details');

    return $user;
}

function cart360LabCart(User $customerUser, array $attributes = []): Cart
{
    $test = LaboratoryTest::factory()->create([
        'brand' => LaboratoryBrand::OLAB->value,
        'requires_appointment' => true,
    ]);

    $cart = Cart::query()->create(array_merge([
        'user_id' => $customerUser->id,
        'type' => MonitoringCartType::Lab->value,
        'status' => MonitoringCartStatus::Active->value,
        'total' => 1000.00,
        'created_at' => now()->subHour(),
        'updated_at' => now()->subMinutes(20),
    ], $attributes));

    CartItem::query()->create([
        'cart_id' => $cart->id,
        'product_id' => (string) $test->id,
        'name' => 'Biometría hemática',
        'price' => 1000.00,
        'quantity' => 1,
    ]);

    return $cart;
}

it('returns drawer payload for a new customer without previous purchases', function () {
    $admin = cart360AdminUserWithCartDetailPermission();
    $customerUser = User::factory()->withRegularCustomer()->create();
    $cart = cart360LabCart($customerUser);

    $this->actingAs($admin);

    $this->getJson(route('admin.carts.show', $cart))
        ->assertOk()
        ->assertJsonPath('data.customer.segment_label', 'Cliente nuevo')
        ->assertJsonPath('data.history.previous_purchases_count', 0)
        ->assertJsonPath('data.history.last_purchase_label', 'Sin compras anteriores');
});

it('summarizes a recurrent customer history', function () {
    $admin = cart360AdminUserWithCartDetailPermission();
    $customerUser = User::factory()->withRegularCustomer()->create();
    $customer = $customerUser->customer;
    LaboratoryPurchase::query()->create([
        'customer_id' => $customer->id,
        'brand' => LaboratoryBrand::OLAB->value,
        'gda_order_id' => 'gda-history-1',
        'name' => 'Paciente',
        'paternal_lastname' => 'Historial',
        'maternal_lastname' => 'Uno',
        'phone' => '8111111111',
        'phone_country' => 'MX',
        'birth_date' => '1990-01-01',
        'gender' => null,
        'street' => 'Calle',
        'number' => '1',
        'neighborhood' => 'Centro',
        'state' => 'Nuevo Leon',
        'city' => 'Monterrey',
        'zipcode' => '64000',
        'total_cents' => 50000,
        'created_at' => now()->subMonths(2),
    ]);
    LaboratoryPurchase::query()->create([
        'customer_id' => $customer->id,
        'brand' => LaboratoryBrand::OLAB->value,
        'gda_order_id' => 'gda-history-2',
        'name' => 'Paciente',
        'paternal_lastname' => 'Historial',
        'maternal_lastname' => 'Dos',
        'phone' => '8111111111',
        'phone_country' => 'MX',
        'birth_date' => '1990-01-01',
        'gender' => null,
        'street' => 'Calle',
        'number' => '1',
        'neighborhood' => 'Centro',
        'state' => 'Nuevo Leon',
        'city' => 'Monterrey',
        'zipcode' => '64000',
        'total_cents' => 70000,
        'created_at' => now()->subMonth(),
    ]);
    $cart = cart360LabCart($customerUser);

    $this->actingAs($admin);

    $this->getJson(route('admin.carts.show', $cart))
        ->assertOk()
        ->assertJsonPath('data.customer.segment_label', 'Cliente recurrente')
        ->assertJsonPath('data.history.previous_purchases_count', 2)
        ->assertJsonPath('data.history.historical_value_formatted', formattedPrice(1200));
});

it('shows a pending appointment in the drawer', function () {
    $admin = cart360AdminUserWithCartDetailPermission();
    $customerUser = User::factory()->withRegularCustomer()->create();
    $cart = cart360LabCart($customerUser);

    LaboratoryAppointment::factory()->create([
        'customer_id' => $customerUser->customer->id,
        'brand' => LaboratoryBrand::OLAB->value,
        'created_at' => now()->subMinutes(40),
        'confirmed_at' => null,
    ]);

    $this->actingAs($admin);

    $this->getJson(route('admin.carts.show', $cart))
        ->assertOk()
        ->assertJsonPath('data.appointment.status_label', 'Pendiente')
        ->assertJsonPath('data.checkout.journey.3.detail', 'Pendiente');
});

it('shows a confirmed appointment without payment', function () {
    $admin = cart360AdminUserWithCartDetailPermission();
    $customerUser = User::factory()->withRegularCustomer()->create();
    $cart = cart360LabCart($customerUser);

    $store = LaboratoryStore::query()->create([
        'name' => 'Roma Norte',
        'brand' => LaboratoryBrand::OLAB->value,
        'state' => 'CDMX',
        'address' => 'Av. Insurgentes 123',
        'weekly_hours' => '8:00 - 18:00',
        'saturday_hours' => '8:00 - 13:00',
        'sunday_hours' => 'Cerrado',
        'google_maps_url' => 'https://maps.example.test',
    ]);
    LaboratoryAppointment::factory()->confirmed(now()->addDay(), now()->subMinutes(30))->create([
        'customer_id' => $customerUser->customer->id,
        'brand' => LaboratoryBrand::OLAB->value,
        'laboratory_store_id' => $store->id,
        'laboratory_purchase_id' => null,
        'patient_gender' => null,
    ]);

    $this->actingAs($admin);

    $this->getJson(route('admin.carts.show', $cart))
        ->assertOk()
        ->assertJsonPath('data.appointment.status_label', 'Confirmada sin pago')
        ->assertJsonPath('data.appointment.store_name', 'Roma Norte');
});

it('shows callback and phone intent details', function () {
    $admin = cart360AdminUserWithCartDetailPermission();
    $customerUser = User::factory()->withRegularCustomer()->create();
    $cart = cart360LabCart($customerUser);

    LaboratoryAppointment::factory()->create([
        'customer_id' => $customerUser->customer->id,
        'brand' => LaboratoryBrand::OLAB->value,
        'phone_call_intent_at' => now()->subMinutes(10),
        'callback_availability_starts_at' => now()->addHours(2),
        'callback_availability_ends_at' => now()->addHours(4),
        'patient_callback_comment' => 'Prefiere llamada después de las 4 PM',
    ]);

    $this->actingAs($admin);

    $this->getJson(route('admin.carts.show', $cart))
        ->assertOk()
        ->assertJsonPath('data.contact.phone_call_intent.label', 'Intentó llamar')
        ->assertJsonPath('data.contact.callback_requested.label', 'Solicitó llamada')
        ->assertJsonPath('data.contact.callback_requested.comment', 'Prefiere llamada después de las 4 PM');
});

it('shows a correlated declined payment', function () {
    $admin = cart360AdminUserWithCartDetailPermission();
    $customerUser = User::factory()->withRegularCustomer()->create();
    $cart = cart360LabCart($customerUser);

    $attempt = new PaymentAttempt([
        'customer_id' => $customerUser->customer->id,
        'amount_cents' => 100000,
        'gateway' => 'efevoopay',
        'reference' => 'LAB-test',
        'status' => PaymentAttempt::STATUS_DECLINED,
        'processor_code' => '87',
        'processor_message' => 'Transacción rechazada',
        'processed_at' => now()->subMinutes(15),
    ]);
    $attempt->created_at = now()->subMinutes(16);
    $attempt->updated_at = now()->subMinutes(15);
    $attempt->save();

    $this->actingAs($admin);

    $this->getJson(route('admin.carts.show', $cart))
        ->assertOk()
        ->assertJsonPath('data.payment.status_label', 'Pago rechazado')
        ->assertJsonPath('data.payment.last_attempt.processor_code', '87')
        ->assertJsonPath('data.checkout.journey.4.detail', 'Rechazado');
});

it('shows a correlated technical payment error', function () {
    $admin = cart360AdminUserWithCartDetailPermission();
    $customerUser = User::factory()->withRegularCustomer()->create();
    $cart = cart360LabCart($customerUser);

    $attempt = new PaymentAttempt([
        'customer_id' => $customerUser->customer->id,
        'amount_cents' => 100000,
        'gateway' => 'efevoopay',
        'status' => PaymentAttempt::STATUS_ERROR,
        'processor_message' => 'Timeout',
        'processed_at' => now()->subMinutes(12),
    ]);
    $attempt->created_at = now()->subMinutes(13);
    $attempt->updated_at = now()->subMinutes(12);
    $attempt->save();

    $this->actingAs($admin);

    $this->getJson(route('admin.carts.show', $cart))
        ->assertOk()
        ->assertJsonPath('data.payment.status_label', 'Error técnico')
        ->assertJsonPath('data.payment.last_attempt.processor_message', 'Tiempo de espera agotado')
        ->assertJsonPath('data.checkout.journey.4.detail', 'Error técnico');
});

it('keeps ambiguous payment attempts neutral', function () {
    $admin = cart360AdminUserWithCartDetailPermission();
    $customerUser = User::factory()->withRegularCustomer()->create();
    $firstCart = cart360LabCart($customerUser);
    $secondCart = cart360LabCart($customerUser, [
        'created_at' => now()->subMinutes(55),
        'updated_at' => now()->subMinutes(10),
    ]);

    $attempt = new PaymentAttempt([
        'customer_id' => $customerUser->customer->id,
        'amount_cents' => 100000,
        'gateway' => 'efevoopay',
        'status' => PaymentAttempt::STATUS_DECLINED,
        'processed_at' => now()->subMinutes(30),
    ]);
    $attempt->created_at = now()->subMinutes(31);
    $attempt->updated_at = now()->subMinutes(30);
    $attempt->save();

    $this->actingAs($admin);

    $this->getJson(route('admin.carts.show', $firstCart))
        ->assertOk()
        ->assertJsonPath('data.payment.status_label', 'Información de pago no determinada')
        ->assertJsonPath('data.checkout.journey.4.detail', 'Información no determinada');

    expect($secondCart->exists)->toBeTrue();
});

it('rejects drawer access without cart detail permission', function () {
    Permission::findOrCreate('view cart details', 'web');
    $admin = User::factory()->withAdministrator()->create();
    $customerUser = User::factory()->withRegularCustomer()->create();
    $cart = cart360LabCart($customerUser);

    $this->actingAs($admin);

    $this->getJson(route('admin.carts.show', $cart))->assertForbidden();
});
