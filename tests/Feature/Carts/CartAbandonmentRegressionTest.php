<?php

require_once __DIR__.'/cart_monitoring_helpers.php';

use App\Enums\CartEventType;
use App\Enums\LaboratoryBrand;
use App\Enums\MonitoringCartStatus;
use App\Enums\MonitoringCartType;
use App\Models\Cart;
use App\Models\CartItem;
use App\Models\LaboratoryPurchase;
use App\Models\Transaction;
use App\Enums\Gender;
use App\Services\Carts\CartAbandonmentService;

beforeEach(function () {
    configureCartActiveCampaignTestEnvironment();
    $this->abandonmentService = app(CartAbandonmentService::class);
});

test('appointment-first pending appointment excludes abandonment', function () {
    $user = cartMonitoringUser();
    $cart = cartMonitoringActiveLabCart($user);
    cartMonitoringRecordUserActivity($cart, CartEventType::AppointmentRequested, now()->subHours(2));
    cartMonitoringAppointment($user->customer, confirmedAt: null, appointmentAt: now()->addDay());

    expect($this->abandonmentService->recordAbandoned($cart->fresh()))->toBeNull();
});

test('appointment-first confirmed under threshold excludes abandonment', function () {
    $user = cartMonitoringUser();
    $cart = cartMonitoringActiveLabCart($user);
    cartMonitoringRecordUserActivity($cart, CartEventType::AppointmentRequested, now()->subHours(2));
    cartMonitoringAppointment($user->customer, confirmedAt: now()->subMinutes(10), appointmentAt: now()->addDay());

    expect($this->abandonmentService->recordAbandoned($cart->fresh()))->toBeNull();
});

test('appointment-first confirmed after threshold abandons at payment stage', function () {
    $user = cartMonitoringUser();
    $cart = cartMonitoringActiveLabCart($user);
    cartMonitoringRecordUserActivity($cart, CartEventType::AppointmentRequested, now()->subHours(2));
    cartMonitoringAppointment($user->customer, confirmedAt: now()->subMinutes(45), appointmentAt: now()->addDay());

    $event = $this->abandonmentService->recordAbandoned($cart->fresh());

    expect($event)->not->toBeNull()
        ->and($event->metadata['checkout_stage'] ?? null)->toBe('payment');
});

test('standard flow customer with three purchases still abandons normally', function () {
    $user = cartMonitoringUser();
    $cart = cartMonitoringActiveLabCart($user);
    cartMonitoringRecordUserActivity($cart, CartEventType::CheckoutStarted, now()->subHours(2));

    for ($i = 0; $i < 3; $i++) {
        $purchase = LaboratoryPurchase::query()->create([
            'customer_id' => $user->customer->id,
            'brand' => LaboratoryBrand::OLAB->value,
            'gda_order_id' => 'gda-reg-'.fake()->unique()->numerify('######'),
            'name' => 'Paciente', 'paternal_lastname' => 'T', 'maternal_lastname' => 'T',
            'phone' => '8111111111', 'phone_country' => 'MX', 'birth_date' => '1990-01-01',
            'gender' => Gender::MALE, 'street' => 'C', 'number' => '1', 'neighborhood' => 'C',
            'state' => 'NL', 'city' => 'MTY', 'zipcode' => '64000', 'total_cents' => 50000,
        ]);
        $tx = Transaction::query()->create([
            'transaction_amount_cents' => 50000, 'payment_method' => 'efevoopay', 'gateway' => 'efevoopay',
            'payment_status' => 'completed', 'reference_id' => 'ref-'.$purchase->id, 'gateway_processed_at' => now(),
        ]);
        $purchase->transactions()->attach($tx->id);
    }

    cartMonitoringAppointment($user->customer, confirmedAt: now()->subMinutes(45), appointmentAt: now()->addDay());

    expect($this->abandonmentService->recordAbandoned($cart->fresh()))->not->toBeNull();
});

test('lab cart without appointment requirement abandons normally', function () {
    $user = cartMonitoringUser();
    $cart = cartMonitoringActiveLabCart($user);
    cartMonitoringRecordUserActivity($cart, CartEventType::CheckoutStarted, now()->subHours(2));

    expect($this->abandonmentService->recordAbandoned($cart->fresh()))->not->toBeNull();
});

test('pharmacy cart abandons normally', function () {
    $user = cartMonitoringUser();
    $cart = Cart::query()->create([
        'user_id' => $user->id,
        'type' => MonitoringCartType::Pharmacy->value,
        'status' => MonitoringCartStatus::Active->value,
        'total' => 100,
    ]);
    CartItem::query()->create([
        'cart_id' => $cart->id,
        'product_id' => 'ph-1',
        'name' => 'Producto',
        'price' => 100,
        'quantity' => 1,
    ]);
    cartMonitoringRecordUserActivity($cart, CartEventType::CheckoutStarted, now()->subHours(2));

    expect($this->abandonmentService->recordAbandoned($cart->fresh()))->not->toBeNull();
});

test('pending appointment on another cart does not exclude current cart', function () {
    $user = cartMonitoringUser();
    $cart = cartMonitoringActiveLabCart($user);
    cartMonitoringRecordUserActivity($cart, CartEventType::CheckoutStarted, now()->subHours(2));

    $other = Cart::query()->create([
        'user_id' => $user->id,
        'type' => MonitoringCartType::Lab->value,
        'status' => MonitoringCartStatus::Active->value,
        'total' => 100,
    ]);
    cartMonitoringAppointment($user->customer, $other, confirmedAt: null, appointmentAt: now()->addDay());

    expect($this->abandonmentService->recordAbandoned($cart->fresh()))->not->toBeNull();
});

test('soft deleted pending appointment does not exclude abandonment', function () {
    $user = cartMonitoringUser();
    $cart = cartMonitoringActiveLabCart($user);
    cartMonitoringRecordUserActivity($cart, CartEventType::CheckoutStarted, now()->subHours(2));

    $appointment = cartMonitoringAppointment($user->customer, confirmedAt: null, appointmentAt: now()->addDay());
    $appointment->delete();

    expect($this->abandonmentService->recordAbandoned($cart->fresh()))->not->toBeNull();
});

test('empty active cart is not eligible for abandonment', function () {
    $user = cartMonitoringUser();
    $cart = Cart::query()->create([
        'user_id' => $user->id,
        'type' => MonitoringCartType::Lab->value,
        'status' => MonitoringCartStatus::Active->value,
        'total' => 0,
    ]);

    expect($this->abandonmentService->recordAbandoned($cart))->toBeNull();
});

test('completed cart is not eligible for abandonment', function () {
    $user = cartMonitoringUser();
    $cart = cartMonitoringActiveLabCart($user);
    $cart->update(['status' => MonitoringCartStatus::Completed, 'completed_at' => now()]);

    expect($this->abandonmentService->recordAbandoned($cart->fresh()))->toBeNull();
});
