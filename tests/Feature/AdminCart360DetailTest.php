<?php

use App\Enums\LaboratoryBrand;
use App\Enums\MonitoringCartStatus;
use App\Enums\MonitoringCartType;
use App\Models\ActiveCampaignWebActivity;
use App\Models\Cart;
use App\Models\ActiveCampaignDispatch;
use App\Models\CartEvent;
use App\Models\CartItem;
use App\Models\LaboratoryAppointment;
use App\Models\LaboratoryPurchase;
use App\Models\LaboratoryStore;
use App\Models\LaboratoryTest;
use App\Models\PaymentAttempt;
use App\Models\Transaction;
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


function cart360PurchaseWithTransaction(Cart $cart, string $method = 'paypal', array $transactionAttributes = []): LaboratoryPurchase
{
    $purchase = LaboratoryPurchase::query()->create([
        'customer_id' => $cart->user->customer->id,
        'cart_id' => $cart->id,
        'brand' => LaboratoryBrand::OLAB->value,
        'gda_order_id' => 'gda-cart-'.$cart->id.'-'.$method,
        'name' => 'Paciente',
        'paternal_lastname' => 'Compra',
        'maternal_lastname' => 'Final',
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
        'total_cents' => 100000,
        'created_at' => now()->subMinutes(5),
    ]);

    $transaction = Transaction::query()->create(array_merge([
        'transaction_amount_cents' => 100000,
        'payment_method' => $method,
        'gateway' => $method,
        'payment_status' => 'completed',
        'reference_id' => 'ref-'.$cart->id.'-'.$method,
        'created_at' => now()->subMinutes(4),
        'gateway_processed_at' => now()->subMinutes(4),
    ], $transactionAttributes));

    $purchase->transactions()->attach($transaction->id);

    return $purchase->refresh()->load('transactions');
}

function cart360PaymentAttempt(Cart $cart, string $status, array $attributes = []): PaymentAttempt
{
    $attempt = new PaymentAttempt(array_merge([
        'customer_id' => $cart->user->customer->id,
        'cart_id' => $cart->id,
        'amount_cents' => 100000,
        'gateway' => 'efevoopay',
        'status' => $status,
        'processor_code' => $status === PaymentAttempt::STATUS_DECLINED ? '87' : null,
        'processor_message' => $status === PaymentAttempt::STATUS_ERROR ? 'Timeout' : 'Transaccion rechazada',
        'processed_at' => now()->subMinutes(12),
    ], $attributes));
    $attempt->created_at = $attributes['created_at'] ?? now()->subMinutes(13);
    $attempt->updated_at = $attributes['updated_at'] ?? now()->subMinutes(12);
    $attempt->save();

    return $attempt;
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
        'cart_id' => $cart->id,
        'brand' => LaboratoryBrand::OLAB->value,
        'created_at' => now()->subMinutes(40),
        'confirmed_at' => null,
    ]);

    $this->actingAs($admin);

    $this->getJson(route('admin.carts.show', $cart))
        ->assertOk()
        ->assertJsonPath('data.appointment.status_label', 'Pendiente')
        ->assertJsonPath('data.checkout.journey.4.detail', 'Esperando confirmación del concierge');
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
        'cart_id' => $cart->id,
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
        'cart_id' => $cart->id,
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
        'cart_id' => $cart->id,
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
        ->assertJsonPath('data.checkout.journey.3.detail', 'Rechazado');
});

it('shows a correlated technical payment error', function () {
    $admin = cart360AdminUserWithCartDetailPermission();
    $customerUser = User::factory()->withRegularCustomer()->create();
    $cart = cart360LabCart($customerUser);

    $attempt = new PaymentAttempt([
        'customer_id' => $customerUser->customer->id,
        'cart_id' => $cart->id,
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
        ->assertJsonPath('data.checkout.journey.3.detail', 'Error técnico');
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
        ->assertJsonPath('data.checkout.journey.3.detail', 'No iniciado');

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


it('uses final transaction as payment journey source over previous attempts', function (string $attemptStatus, string $method, string $label) {
    $admin = cart360AdminUserWithCartDetailPermission();
    $customerUser = User::factory()->withRegularCustomer()->create();
    $cart = cart360LabCart($customerUser, [
        'status' => MonitoringCartStatus::Completed->value,
        'completed_at' => now()->subMinutes(3),
        'updated_at' => now()->subMinutes(3),
    ]);

    cart360PaymentAttempt($cart, $attemptStatus);
    cart360PurchaseWithTransaction($cart, $method);

    $this->actingAs($admin);

    $this->getJson(route('admin.carts.show', $cart))
        ->assertOk()
        ->assertJsonPath('data.final_payment.method', $method)
        ->assertJsonPath('data.final_payment.method_label', $label)
        ->assertJsonPath('data.journey.3.state', 'completed')
        ->assertJsonPath('data.journey.3.detail', $label)
        ->assertJsonPath('data.journey.5.state', 'completed')
        ->assertJsonPath('data.payment_history.0.type', 'payment_attempt')
        ->assertJsonPath('data.payment_history.1.type', 'final_payment')
        ->assertJsonPath('data.payment_history.1.label', 'Pago final');
})->with([
    'A error plus final PayPal approved' => [PaymentAttempt::STATUS_ERROR, 'paypal', 'PayPal'],
    'B declined plus final Efevoo approved' => [PaymentAttempt::STATUS_DECLINED, 'efevoopay', 'Efevoo'],
    'C Efevoo failed plus final Odessa' => [PaymentAttempt::STATUS_ERROR, 'odessa', 'Caja de ahorro / Odessa'],
    'D final Stripe' => [PaymentAttempt::STATUS_ERROR, 'stripe', 'Tarjeta / Stripe'],
]);

it('supports final purchase payment without payment attempts', function () {
    $admin = cart360AdminUserWithCartDetailPermission();
    $customerUser = User::factory()->withRegularCustomer()->create();
    $cart = cart360LabCart($customerUser, [
        'status' => MonitoringCartStatus::Completed->value,
        'completed_at' => now()->subMinutes(3),
        'updated_at' => now()->subMinutes(3),
    ]);
    cart360PurchaseWithTransaction($cart, 'stripe');

    $this->actingAs($admin);

    $this->getJson(route('admin.carts.show', $cart))
        ->assertOk()
        ->assertJsonCount(1, 'data.payment_history')
        ->assertJsonPath('data.payment_history.0.type', 'final_payment')
        ->assertJsonPath('data.journey.3.detail', 'Tarjeta / Stripe');
});

it('returns appointment journey for pending and confirmed appointments without payment', function () {
    $admin = cart360AdminUserWithCartDetailPermission();
    $customerUser = User::factory()->withRegularCustomer()->create();
    $cart = cart360LabCart($customerUser);

    LaboratoryAppointment::factory()->confirmed(now()->addDay(), now()->subMinutes(30))->create([
        'customer_id' => $customerUser->customer->id,
        'cart_id' => $cart->id,
        'brand' => LaboratoryBrand::OLAB->value,
        'laboratory_purchase_id' => null,
        'patient_gender' => null,
    ]);

    $this->actingAs($admin);

    $this->getJson(route('admin.carts.show', $cart))
        ->assertOk()
        ->assertJsonPath('data.appointment_journey.0.state', 'completed')
        ->assertJsonPath('data.appointment_journey.1.state', 'completed')
        ->assertJsonPath('data.appointment_journey.2.state', 'completed')
        ->assertJsonPath('data.appointment_journey.4.state', 'pending')
        ->assertJsonPath('data.appointment_journey.5.state', 'pending');
});

it('returns appointment journey completed when appointment has purchase and final payment', function () {
    $admin = cart360AdminUserWithCartDetailPermission();
    $customerUser = User::factory()->withRegularCustomer()->create();
    $cart = cart360LabCart($customerUser, [
        'status' => MonitoringCartStatus::Completed->value,
        'completed_at' => now()->subMinutes(3),
        'updated_at' => now()->subMinutes(3),
    ]);
    $purchase = cart360PurchaseWithTransaction($cart, 'paypal');
    LaboratoryAppointment::factory()->confirmed(now()->addDay(), now()->subMinutes(30))->create([
        'customer_id' => $customerUser->customer->id,
        'cart_id' => $cart->id,
        'brand' => LaboratoryBrand::OLAB->value,
        'laboratory_purchase_id' => $purchase->id,
        'patient_gender' => null,
    ]);

    $this->actingAs($admin);

    $this->getJson(route('admin.carts.show', $cart))
        ->assertOk()
        ->assertJsonPath('data.appointment_journey.4.state', 'completed')
        ->assertJsonPath('data.appointment_journey.5.state', 'completed');
});

it('returns cart events timeline and keeps legacy carts empty', function () {
    $admin = cart360AdminUserWithCartDetailPermission();
    $customerUser = User::factory()->withRegularCustomer()->create();
    $cart = cart360LabCart($customerUser);
    $legacyCart = cart360LabCart($customerUser, ['total' => 2000]);

    CartEvent::query()->create([
        'cart_id' => $cart->id,
        'event' => 'cart_created',
        'metadata' => ['step' => 'cart', 'token' => 'hidden'],
        'occurred_at' => now()->subMinutes(20),
    ]);
    CartEvent::query()->create([
        'cart_id' => $cart->id,
        'event' => 'payment_error',
        'metadata' => ['status' => 'error'],
        'occurred_at' => now()->subMinutes(10),
    ]);

    $this->actingAs($admin);

    $this->getJson(route('admin.carts.show', $cart))
        ->assertOk()
        ->assertJsonPath('data.events.0.label', 'Carrito creado')
        ->assertJsonPath('data.events.0.metadata.step', 'cart')
        ->assertJsonMissingPath('data.events.0.metadata.token')
        ->assertJsonPath('data.events.1.label', 'Error tecnico');

    $this->getJson(route('admin.carts.show', $legacyCart))
        ->assertOk()
        ->assertJsonCount(0, 'data.events');
});

it('returns cart events in deterministic chronological order for same-second activity', function () {
    $admin = cart360AdminUserWithCartDetailPermission();
    $customerUser = User::factory()->withRegularCustomer()->create();
    $cart = cart360LabCart($customerUser);
    $sameSecond = now()->subMinutes(10)->setMicrosecond(0);

    $first = CartEvent::query()->create([
        'cart_id' => $cart->id,
        'event' => 'payment_started',
        'metadata' => ['status' => 'processing'],
        'occurred_at' => $sameSecond,
    ]);
    $second = CartEvent::query()->create([
        'cart_id' => $cart->id,
        'event' => 'payment_approved',
        'metadata' => ['status' => 'approved'],
        'occurred_at' => $sameSecond,
    ]);

    $this->actingAs($admin);

    $this->getJson(route('admin.carts.show', $cart))
        ->assertOk()
        ->assertJsonPath('data.events.0.id', $first->id)
        ->assertJsonPath('data.events.0.label', 'Pago iniciado')
        ->assertJsonPath('data.events.0.occurred_at_human_with_seconds', $sameSecond->timezone('America/Monterrey')->format('d/m/Y H:i:s'))
        ->assertJsonPath('data.events.1.id', $second->id)
        ->assertJsonPath('data.events.1.label', 'Pago aprobado');
});

it('returns session context from the latest cart event with client metadata', function () {
    $admin = cart360AdminUserWithCartDetailPermission();
    $customerUser = User::factory()->withRegularCustomer()->create();
    $cart = cart360LabCart($customerUser);

    CartEvent::query()->create([
        'cart_id' => $cart->id,
        'event' => 'cart_created',
        'metadata' => [
            'client' => ['device_type' => 'mobile', 'browser' => 'Chrome', 'os' => 'Android', 'source' => 'request_user_agent'],
        ],
        'occurred_at' => now()->subMinutes(20),
    ]);
    CartEvent::query()->create([
        'cart_id' => $cart->id,
        'event' => 'payment_started',
        'metadata' => [
            'status' => 'processing',
            'client' => ['device_type' => 'desktop', 'browser' => 'Chrome', 'os' => 'Windows', 'source' => 'request_user_agent'],
        ],
        'occurred_at' => now()->subMinutes(10),
    ]);

    $this->actingAs($admin);

    $this->getJson(route('admin.carts.show', $cart))
        ->assertOk()
        ->assertJsonPath('data.client_context.has_data', true)
        ->assertJsonPath('data.client_context.last_device.device_type', 'desktop')
        ->assertJsonPath('data.client_context.last_device.device_label', 'Desktop')
        ->assertJsonPath('data.client_context.last_device.browser', 'Chrome')
        ->assertJsonPath('data.client_context.last_device.os', 'Windows')
        ->assertJsonPath('data.client_context.has_device_change', true)
        ->assertJsonPath('data.client_context.devices_seen.0', 'mobile')
        ->assertJsonPath('data.client_context.devices_seen.1', 'desktop')
        ->assertJsonPath('data.events.0.client.device_label', 'Móvil')
        ->assertJsonPath('data.events.1.client.device_label', 'Desktop');
});

it('does not flag device change when device type stays the same', function () {
    $admin = cart360AdminUserWithCartDetailPermission();
    $customerUser = User::factory()->withRegularCustomer()->create();
    $cart = cart360LabCart($customerUser);

    CartEvent::query()->create([
        'cart_id' => $cart->id,
        'event' => 'checkout_started',
        'metadata' => [
            'client' => ['device_type' => 'mobile', 'browser' => 'Safari', 'os' => 'iOS', 'source' => 'request_user_agent'],
        ],
        'occurred_at' => now()->subMinutes(20),
    ]);
    CartEvent::query()->create([
        'cart_id' => $cart->id,
        'event' => 'patient_selected',
        'metadata' => [
            'client' => ['device_type' => 'mobile', 'browser' => 'Chrome', 'os' => 'Android', 'source' => 'request_user_agent'],
        ],
        'occurred_at' => now()->subMinutes(10),
    ]);

    $this->actingAs($admin);

    $this->getJson(route('admin.carts.show', $cart))
        ->assertOk()
        ->assertJsonPath('data.client_context.has_data', true)
        ->assertJsonPath('data.client_context.has_device_change', false)
        ->assertJsonCount(1, 'data.client_context.devices_seen');
});

it('keeps drawer payload valid for legacy events without client metadata', function () {
    $admin = cart360AdminUserWithCartDetailPermission();
    $customerUser = User::factory()->withRegularCustomer()->create();
    $cart = cart360LabCart($customerUser);

    CartEvent::query()->create([
        'cart_id' => $cart->id,
        'event' => 'checkout_started',
        'metadata' => ['brand' => 'olab'],
        'occurred_at' => now()->subMinutes(20),
    ]);

    $this->actingAs($admin);

    $this->getJson(route('admin.carts.show', $cart))
        ->assertOk()
        ->assertJsonPath('data.client_context.has_data', false)
        ->assertJsonPath('data.client_context.last_device', null)
        ->assertJsonMissingPath('data.events.0.client');
});

it('returns approximate ActiveCampaign location from local customer cache', function () {
    $admin = cart360AdminUserWithCartDetailPermission();
    $customerUser = User::factory()->withRegularCustomer()->create();
    $cart = cart360LabCart($customerUser);

    $customerUser->customer->update([
        'ac_location' => [
            'city' => 'Monterrey',
            'state' => 'Nuevo Leon',
            'country' => 'Mexico',
            'timezone' => 'America/Monterrey',
            'source' => 'activecampaign',
            'geoIp4' => '187.190.1.1',
            'geoLat' => '25.686600',
            'geoLon' => '-100.316100',
        ],
        'ac_location_cached_at' => now()->subDay(),
    ]);

    $this->actingAs($admin);

    $this->getJson(route('admin.carts.show', $cart))
        ->assertOk()
        ->assertJsonPath('data.client_context.location.has_data', true)
        ->assertJsonPath('data.client_context.location.city', 'Monterrey')
        ->assertJsonPath('data.client_context.location.state', 'Nuevo Leon')
        ->assertJsonPath('data.client_context.location.country', 'Mexico')
        ->assertJsonPath('data.client_context.location.timezone', 'America/Monterrey')
        ->assertJsonPath('data.client_context.location.source', 'activecampaign')
        ->assertJsonMissingPath('data.client_context.location.geoIp4')
        ->assertJsonMissingPath('data.client_context.location.geoLat')
        ->assertJsonMissingPath('data.client_context.location.geoLon')
        ->assertJsonMissingPath('data.client_context.location.ip')
        ->assertJsonMissingPath('data.client_context.location.lat')
        ->assertJsonMissingPath('data.client_context.location.lon');
});

it('returns null approximate location when customer has no local ActiveCampaign location', function () {
    $admin = cart360AdminUserWithCartDetailPermission();
    $customerUser = User::factory()->withRegularCustomer()->create();
    $cart = cart360LabCart($customerUser);

    $this->actingAs($admin);

    $this->getJson(route('admin.carts.show', $cart))
        ->assertOk()
        ->assertJsonPath('data.client_context.location', null);
});

it('keeps approximate location copy visible in the drawer UI component', function () {
    $component = file_get_contents(resource_path('js/Components/Admin/Carts/CartDetailDrawer.jsx'));

    expect($component)->toContain('Ubicacion aproximada')
        ->and($component)->toContain('Fuente:');
});

it('keeps cart activity seconds and legacy ActiveCampaign copy visible in the drawer UI component', function () {
    $component = file_get_contents(resource_path('js/Components/Admin/Carts/CartDetailDrawer.jsx'));

    expect($component)->toContain('formatTimeWithSeconds')
        ->and($component)->toContain('showSeconds')
        ->and($component)->toContain('Evento historico')
        ->and($component)->not->toContain('Dato de contacto');
});

it('returns administrative links according to permissions', function () {
    Permission::findOrCreate('view cart details', 'web');
    Permission::findOrCreate('users.manage', 'web');
    Permission::findOrCreate('customers.manage', 'web');
    Permission::findOrCreate('laboratory-purchases.manage', 'web');

    $admin = cart360AdminUserWithCartDetailPermission();
    $customerUser = User::factory()->withRegularCustomer()->create();
    $cart = cart360LabCart($customerUser, [
        'status' => MonitoringCartStatus::Completed->value,
        'completed_at' => now()->subMinutes(3),
    ]);
    $purchase = cart360PurchaseWithTransaction($cart, 'paypal');
    $appointment = LaboratoryAppointment::factory()->confirmed(now()->addDay(), now()->subMinutes(30))->create([
        'customer_id' => $customerUser->customer->id,
        'cart_id' => $cart->id,
        'brand' => LaboratoryBrand::OLAB->value,
        'laboratory_purchase_id' => $purchase->id,
        'patient_gender' => null,
    ]);

    $this->actingAs($admin);
    $this->getJson(route('admin.carts.show', $cart))
        ->assertOk()
        ->assertJsonPath('data.links.user_url', null)
        ->assertJsonPath('data.links.customer_url', null)
        ->assertJsonPath('data.links.purchase_url', null)
        ->assertJsonPath('data.links.appointment_url', null);

    $admin->administrator->givePermissionTo('users.manage', 'customers.manage', 'laboratory-purchases.manage');
    $admin->administrator->laboratoryConcierge()->create();

    $this->getJson(route('admin.carts.show', $cart))
        ->assertOk()
        ->assertJsonPath('data.links.user_url', route('admin.users.show', $customerUser))
        ->assertJsonPath('data.links.customer_url', route('admin.customers.show', $customerUser->customer))
        ->assertJsonPath('data.links.purchase_url', route('admin.laboratory-purchases.show', $purchase))
        ->assertJsonPath('data.links.appointment_url', route('admin.laboratory-appointments.show', $appointment));
});

it('returns local ActiveCampaign dispatches and legacy tag conservatively', function () {
    $admin = cart360AdminUserWithCartDetailPermission();
    $customerUser = User::factory()->withRegularCustomer()->create();
    $cart = cart360LabCart($customerUser);

    ActiveCampaignDispatch::query()->create([
        'event_type' => 'cart_abandoned',
        'entity_type' => 'cart',
        'entity_id' => $cart->id,
        'customer_id' => $customerUser->customer->id,
        'email' => $customerUser->email,
        'idempotency_key' => 'cart:'.$cart->id.':synced',
        'status' => 'synced',
        'synced_at' => now()->subMinutes(5),
    ]);
    ActiveCampaignDispatch::query()->create([
        'event_type' => 'cart_abandoned',
        'entity_type' => 'customer',
        'entity_id' => $customerUser->customer->id,
        'customer_id' => $customerUser->customer->id,
        'email' => $customerUser->email,
        'idempotency_key' => 'customer-only-failed',
        'status' => 'failed',
        'last_error' => 'API token secret should be hidden',
    ]);

    $this->actingAs($admin);

    $this->getJson(route('admin.carts.show', $cart))
        ->assertOk()
        ->assertJsonCount(1, 'data.activecampaign.items')
        ->assertJsonPath('data.activecampaign.items.0.status', 'synced')
        ->assertJsonPath('data.activecampaign.items.0.confidence', 'explicit');

    $legacyUser = User::factory()->withRegularCustomer()->create();
    $legacyUser->customer->update(['cart_abandoned_tagged_at' => now()->subDay()]);
    $legacyCart = cart360LabCart($legacyUser);

    $this->getJson(route('admin.carts.show', $legacyCart))
        ->assertOk()
        ->assertJsonPath('data.activecampaign.items.0.label', 'Carrito abandonado marcado')
        ->assertJsonPath('data.activecampaign.items.0.source', 'customer')
        ->assertJsonPath('data.activecampaign.items.0.confidence', 'customer_legacy');
});


it('returns failed local ActiveCampaign dispatch with safe message', function () {
    $admin = cart360AdminUserWithCartDetailPermission();
    $customerUser = User::factory()->withRegularCustomer()->create();
    $cart = cart360LabCart($customerUser);

    ActiveCampaignDispatch::query()->create([
        'event_type' => 'cart_abandoned',
        'entity_type' => 'cart',
        'entity_id' => $cart->id,
        'customer_id' => $customerUser->customer->id,
        'email' => $customerUser->email,
        'idempotency_key' => 'cart:'.$cart->id.':failed',
        'status' => 'failed',
        'last_error' => 'API token secret leaked by provider',
    ]);

    $this->actingAs($admin);

    $this->getJson(route('admin.carts.show', $cart))
        ->assertOk()
        ->assertJsonPath('data.activecampaign.items.0.status', 'failed')
        ->assertJsonPath('data.activecampaign.items.0.message', 'Error de sincronizacion');
});

it('does not invent ActiveCampaign data when there is no local evidence', function () {
    $admin = cart360AdminUserWithCartDetailPermission();
    $customerUser = User::factory()->withRegularCustomer()->create();
    $cart = cart360LabCart($customerUser);

    $this->actingAs($admin);

    $this->getJson(route('admin.carts.show', $cart))
        ->assertOk()
        ->assertJsonPath('data.activecampaign.has_data', false)
        ->assertJsonCount(0, 'data.activecampaign.items');
});

it('returns correlated web activity in drawer payload without unsafe URL data', function () {
    $admin = cart360AdminUserWithCartDetailPermission();
    $customerUser = User::factory()->withRegularCustomer()->create();
    $cart = cart360LabCart($customerUser, [
        'created_at' => now()->subHour(),
        'updated_at' => now()->subMinutes(20),
    ]);

    ActiveCampaignWebActivity::query()->create([
        'customer_id' => $customerUser->customer->id,
        'ac_contact_id' => 316,
        'path' => '/laboratory/olab/checkout',
        'title' => 'Checkout OLAB',
        'label' => 'Checkout',
        'occurred_at' => now()->subMinutes(45),
        'source' => 'activecampaign_site_tracking',
        'raw_reference_type' => 'TrackingLog',
        'raw_reference_id' => 'track-drawer-1',
        'activity_hash' => hash('sha256', 'track-drawer-1'),
    ]);

    $this->actingAs($admin);

    $this->getJson(route('admin.carts.show', $cart))
        ->assertOk()
        ->assertJsonPath('data.web_activity.has_data', true)
        ->assertJsonPath('data.web_activity.count', 1)
        ->assertJsonPath('data.web_activity.items.0.path', '/laboratory/olab/checkout')
        ->assertJsonPath('data.web_activity.items.0.label', 'Checkout')
        ->assertJsonPath('data.web_activity.items.0.title', 'Checkout OLAB')
        ->assertJsonPath('data.web_activity.items.0.source', 'activecampaign_site_tracking')
        ->assertJsonMissingPath('data.web_activity.items.0.email')
        ->assertJsonMissingPath('data.web_activity.items.0.ip')
        ->assertJsonMissingPath('data.web_activity.items.0.raw_reference_id');
});

it('keeps web activity scoped to customer and cart window with max 10 chronological rows', function () {
    $admin = cart360AdminUserWithCartDetailPermission();
    $customerUser = User::factory()->withRegularCustomer()->create();
    $otherUser = User::factory()->withRegularCustomer()->create();
    $cart = cart360LabCart($customerUser, [
        'created_at' => now()->subHour(),
        'updated_at' => now()->subMinutes(20),
    ]);

    foreach (range(1, 12) as $index) {
        ActiveCampaignWebActivity::query()->create([
            'customer_id' => $customerUser->customer->id,
            'ac_contact_id' => 316,
            'path' => '/laboratories/'.$index,
            'label' => 'Pagina visitada',
            'occurred_at' => now()->subMinutes(55 - $index),
            'source' => 'activecampaign_site_tracking',
            'raw_reference_type' => 'TrackingLog',
            'raw_reference_id' => 'drawer-'.$index,
            'activity_hash' => hash('sha256', 'drawer-'.$index),
        ]);
    }

    ActiveCampaignWebActivity::query()->create([
        'customer_id' => $customerUser->customer->id,
        'ac_contact_id' => 316,
        'path' => '/laboratories/outside',
        'label' => 'Pagina visitada',
        'occurred_at' => now()->subHours(3),
        'source' => 'activecampaign_site_tracking',
        'raw_reference_type' => 'TrackingLog',
        'raw_reference_id' => 'drawer-outside',
        'activity_hash' => hash('sha256', 'drawer-outside'),
    ]);

    ActiveCampaignWebActivity::query()->create([
        'customer_id' => $otherUser->customer->id,
        'ac_contact_id' => 999,
        'path' => '/laboratories/other',
        'label' => 'Pagina visitada',
        'occurred_at' => now()->subMinutes(45),
        'source' => 'activecampaign_site_tracking',
        'raw_reference_type' => 'TrackingLog',
        'raw_reference_id' => 'drawer-other',
        'activity_hash' => hash('sha256', 'drawer-other'),
    ]);

    $this->actingAs($admin);

    $this->getJson(route('admin.carts.show', $cart))
        ->assertOk()
        ->assertJsonCount(10, 'data.web_activity.items')
        ->assertJsonPath('data.web_activity.items.0.path', '/laboratories/1')
        ->assertJsonPath('data.web_activity.items.9.path', '/laboratories/10');
});

it('returns empty web activity contract for carts without local Site Tracking evidence', function () {
    $admin = cart360AdminUserWithCartDetailPermission();
    $customerUser = User::factory()->withRegularCustomer()->create();
    $cart = cart360LabCart($customerUser);

    $this->actingAs($admin);

    $this->getJson(route('admin.carts.show', $cart))
        ->assertOk()
        ->assertJsonPath('data.web_activity.has_data', false)
        ->assertJsonPath('data.web_activity.count', 0)
        ->assertJsonCount(0, 'data.web_activity.items');
});

it('does not contaminate journey general for a pre-checkout cart when customer has completed carts', function () {
    $admin = cart360AdminUserWithCartDetailPermission();
    $customerUser = User::factory()->withRegularCustomer()->create();

    $cartA = cart360LabCart($customerUser, [
        'status' => MonitoringCartStatus::Completed->value,
        'completed_at' => now()->subDays(2),
        'created_at' => now()->subDays(3),
        'updated_at' => now()->subDays(2),
    ]);
    cart360PurchaseWithTransaction($cartA, 'paypal');
    cart360PaymentAttempt($cartA, PaymentAttempt::STATUS_APPROVED);

    $cartB = cart360LabCart($customerUser, [
        'created_at' => now()->subMinutes(30),
        'updated_at' => now()->subMinutes(5),
    ]);
    CartEvent::query()->create([
        'cart_id' => $cartB->id,
        'event' => 'cart_item_added',
        'metadata' => ['product_id' => '1'],
        'occurred_at' => now()->subMinutes(25),
    ]);

    $this->actingAs($admin);

    $this->getJson(route('admin.carts.show', $cartB))
        ->assertOk()
        ->assertJsonPath('data.journey.0.state', 'completed')
        ->assertJsonPath('data.journey.1.state', 'pending')
        ->assertJsonPath('data.journey.1.detail', 'No registrado')
        ->assertJsonPath('data.journey.2.state', 'pending')
        ->assertJsonPath('data.journey.2.detail', 'No registrada')
        ->assertJsonPath('data.journey.3.detail', 'No iniciado')
        ->assertJsonPath('data.journey.4.state', 'pending')
        ->assertJsonPath('data.journey.4.detail', 'No iniciada')
        ->assertJsonPath('data.journey.5.state', 'pending')
        ->assertJsonPath('data.journey.5.detail', 'Sin compra')
        ->assertJsonPath('data.cart.related_purchase', null)
        ->assertJsonPath('data.appointment', null)
        ->assertJsonPath('data.final_payment', null);

    expect($cartA->exists)->toBeTrue();
});

it('does not use historical purchase payment or appointment fallbacks in journey general', function () {
    $admin = cart360AdminUserWithCartDetailPermission();
    $customerUser = User::factory()->withRegularCustomer()->create();
    $customer = $customerUser->customer;

    LaboratoryPurchase::query()->create([
        'customer_id' => $customer->id,
        'brand' => LaboratoryBrand::OLAB->value,
        'gda_order_id' => 'gda-historical-no-cart',
        'name' => 'Paciente',
        'paternal_lastname' => 'Historico',
        'maternal_lastname' => 'Compra',
        'phone' => '8111111111',
        'phone_country' => 'MX',
        'birth_date' => '1990-01-01',
        'gender' => null,
        'street' => 'Calle Historica',
        'number' => '99',
        'neighborhood' => 'Centro',
        'state' => 'Nuevo Leon',
        'city' => 'Monterrey',
        'zipcode' => '64000',
        'total_cents' => 100000,
        'created_at' => now()->subMonths(3),
    ]);

    $historicalAttempt = new PaymentAttempt([
        'customer_id' => $customer->id,
        'amount_cents' => 100000,
        'gateway' => 'efevoopay',
        'status' => PaymentAttempt::STATUS_APPROVED,
        'processed_at' => now()->subMonths(3),
    ]);
    $historicalAttempt->created_at = now()->subMonths(3);
    $historicalAttempt->updated_at = now()->subMonths(3);
    $historicalAttempt->save();

    LaboratoryAppointment::factory()->confirmed(now()->subMonths(3), now()->subMonths(3))->create([
        'customer_id' => $customer->id,
        'brand' => LaboratoryBrand::OLAB->value,
        'laboratory_purchase_id' => null,
        'patient_gender' => null,
    ]);

    $cartB = cart360LabCart($customerUser, [
        'created_at' => now()->subMinutes(20),
        'updated_at' => now()->subMinutes(3),
    ]);
    CartEvent::query()->create([
        'cart_id' => $cartB->id,
        'event' => 'cart_item_added',
        'metadata' => ['product_id' => '1'],
        'occurred_at' => now()->subMinutes(18),
    ]);

    $this->actingAs($admin);

    $this->getJson(route('admin.carts.show', $cartB))
        ->assertOk()
        ->assertJsonPath('data.journey.1.detail', 'No registrado')
        ->assertJsonPath('data.journey.2.detail', 'No registrada')
        ->assertJsonPath('data.journey.3.detail', 'No iniciado')
        ->assertJsonPath('data.journey.4.detail', 'No iniciada')
        ->assertJsonPath('data.journey.5.detail', 'Sin compra')
        ->assertJsonPath('data.appointment', null)
        ->assertJsonPath('data.history.previous_purchases_count', 1);
});

it('keeps web activity drawer UI conditional and sanitized', function () {
    $component = file_get_contents(resource_path('js/Components/Admin/Carts/CartDetailDrawer.jsx'));

    expect($component)->toContain('Actividad web')
        ->and($component)->toContain('webActivity?.has_data')
        ->and($component)->toContain('WebActivityTimeline')
        ->and($component)->not->toContain('raw_reference_id')
        ->and($component)->not->toContain('activity_hash');
});
