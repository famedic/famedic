<?php

use App\Actions\Laboratories\SyncLaboratoryAppointmentFromContactAction;
use App\Actions\Laboratories\SyncLaboratoryCheckoutDraftAction;
use App\Enums\CartEventType;
use App\Enums\LaboratoryBrand;
use App\Enums\MonitoringCartStatus;
use App\Enums\MonitoringCartType;
use App\Models\Address;
use App\Models\Cart;
use App\Models\CartEvent;
use App\Models\CartItem;
use App\Models\Contact;
use App\Models\LaboratoryAppointment;
use App\Models\LaboratoryCartItem;
use App\Models\LaboratoryCheckoutDraft;
use App\Models\LaboratoryPurchase;
use App\Models\LaboratoryTest;
use App\Models\PaymentAttempt;
use App\Models\Transaction;
use App\Models\User;
use App\Enums\Gender;
use App\Services\Carts\CartAppointmentContactSignalService;
use App\Services\Carts\AppointmentPendingDetectionService;
use App\Services\Monitoring\SyncMonitoringCartService;
use Spatie\Permission\Models\Permission;
use Illuminate\Support\Facades\Config;
use Tests\TestCase;

function journeyFlowAdmin(): User
{
    Permission::findOrCreate('view cart details', 'web');
    $user = User::factory()->withAdministrator()->create();
    $user->administrator->givePermissionTo('view cart details');

    return $user;
}

function journeyFlowCustomer(): User
{
    return User::factory()->withRegularCustomer()->create();
}

function journeyFlowTest(): LaboratoryTest
{
    return LaboratoryTest::factory()->create([
        'brand' => LaboratoryBrand::OLAB->value,
        'requires_appointment' => true,
    ]);
}

function journeyFlowActiveCart(User $customerUser, LaboratoryTest $test, array $attributes = []): Cart
{
    LaboratoryCartItem::factory()->create([
        'customer_id' => $customerUser->customer->id,
        'laboratory_test_id' => $test->id,
    ]);

    app(SyncMonitoringCartService::class)->syncLaboratory($customerUser->customer);

    $cart = Cart::query()
        ->where('user_id', $customerUser->id)
        ->where('type', MonitoringCartType::Lab)
        ->where('status', MonitoringCartStatus::Active)
        ->firstOrFail();

    $cart->update(array_merge([
        'total' => 1000.00,
        'updated_at' => now()->subMinutes(5),
    ], $attributes));

    CartItem::query()->updateOrCreate(
        ['cart_id' => $cart->id, 'product_id' => (string) $test->id],
        ['name' => 'Biometría hemática', 'price' => 1000.00, 'quantity' => 1],
    );

    return $cart->fresh(['items', 'events']);
}

function journeyFlowShowCart(Cart $cart): \Illuminate\Testing\TestResponse
{
    return test()->actingAs(journeyFlowAdmin())
        ->getJson(route('admin.carts.show', $cart));
}

function journeyFlowAttachStandardPurchases(User $user): void
{
    for ($i = 0; $i < 3; $i++) {
        $purchase = LaboratoryPurchase::query()->create([
            'customer_id' => $user->customer->id,
            'brand' => LaboratoryBrand::OLAB->value,
            'gda_order_id' => 'gda-journey-'.fake()->unique()->numerify('######'),
            'name' => 'Paciente',
            'paternal_lastname' => 'Test',
            'maternal_lastname' => 'Test',
            'phone' => '8111111111',
            'phone_country' => 'MX',
            'birth_date' => '1990-01-01',
            'gender' => Gender::MALE,
            'street' => 'Calle',
            'number' => '1',
            'neighborhood' => 'Centro',
            'state' => 'Nuevo Leon',
            'city' => 'Monterrey',
            'zipcode' => '64000',
            'total_cents' => 50000,
        ]);
        $transaction = Transaction::query()->create([
            'transaction_amount_cents' => 50000,
            'payment_method' => 'efevoopay',
            'gateway' => 'efevoopay',
            'payment_status' => 'completed',
            'reference_id' => 'ref-journey-'.$purchase->id,
            'gateway_processed_at' => now(),
        ]);
        $purchase->transactions()->attach($transaction->id);
    }
}

it('shows payment method selected in journey and timeline without marking payment as started', function () {
    $customerUser = journeyFlowCustomer();
    $test = journeyFlowTest();
    $cart = journeyFlowActiveCart($customerUser, $test);
    $contact = Contact::factory()->create(['customer_id' => $customerUser->customer->id]);
    $address = Address::factory()->create(['customer_id' => $customerUser->customer->id]);

    app(SyncLaboratoryCheckoutDraftAction::class)(
        $customerUser->customer,
        LaboratoryBrand::OLAB,
        ['step' => 'patient', 'contact_id' => $contact->id],
    );
    app(SyncLaboratoryCheckoutDraftAction::class)(
        $customerUser->customer,
        LaboratoryBrand::OLAB,
        ['step' => 'address', 'contact_id' => $contact->id, 'address_id' => $address->id],
    );
    LaboratoryAppointment::factory()->confirmed(now()->addDay(), now())->create([
        'customer_id' => $customerUser->customer->id,
        'cart_id' => $cart->id,
        'brand' => LaboratoryBrand::OLAB->value,
        'patient_gender' => null,
    ]);
    app(SyncLaboratoryCheckoutDraftAction::class)(
        $customerUser->customer,
        LaboratoryBrand::OLAB,
        ['step' => 'payment', 'payment_method' => 'paypal'],
    );

    $response = journeyFlowShowCart($cart->fresh(['events']))->assertOk();

    expect($response->json('data.journey.4.state'))->toBe('current')
        ->and($response->json('data.journey.4.detail'))->toBe('Método seleccionado: PayPal')
        ->and(collect($response->json('data.events'))->pluck('label'))
        ->toContain('Método de pago seleccionado');

    $event = CartEvent::query()
        ->where('cart_id', $cart->id)
        ->where('event', CartEventType::PaymentMethodSelected->value)
        ->first();

    expect($event)->not->toBeNull()
        ->and($event->metadata['payment_method_type'])->toBe('paypal')
        ->and($event->metadata['gateway'])->toBe('paypal')
        ->and($event->metadata)->not->toHaveKey('card_token');
});

it('shows appointment requested via checkout contact sync as waiting confirmation in journey', function () {
    $customerUser = journeyFlowCustomer();
    $test = journeyFlowTest();
    $cart = journeyFlowActiveCart($customerUser, $test);
    $contact = Contact::factory()->create(['customer_id' => $customerUser->customer->id]);

    app(SyncLaboratoryAppointmentFromContactAction::class)(
        $customerUser->customer,
        LaboratoryBrand::OLAB,
        $contact,
    );

    $response = journeyFlowShowCart($cart->fresh(['events', 'laboratoryAppointments']))->assertOk();

    expect($response->json('data.journey.3.state'))->toBe('current')
        ->and($response->json('data.journey.3.detail'))->toBe('Esperando confirmación del concierge')
        ->and($response->json('data.appointment.status_label'))->toBe('Pendiente')
        ->and(collect($response->json('data.events'))->pluck('label'))
        ->toContain('Cita solicitada');

    $appointment = LaboratoryAppointment::query()->where('cart_id', $cart->id)->first();
    expect($appointment)->not->toBeNull();
});

it('shows confirmed appointment in journey for explicit cart appointment', function () {
    $customerUser = journeyFlowCustomer();
    $test = journeyFlowTest();
    $cart = journeyFlowActiveCart($customerUser, $test);

    LaboratoryAppointment::factory()->confirmed(now()->addDay(), now()->subMinutes(20))->create([
        'customer_id' => $customerUser->customer->id,
        'cart_id' => $cart->id,
        'brand' => LaboratoryBrand::OLAB->value,
        'laboratory_purchase_id' => null,
        'patient_gender' => null,
    ]);

    CartEvent::query()->create([
        'cart_id' => $cart->id,
        'event' => CartEventType::AppointmentConfirmed->value,
        'metadata' => ['appointment_id' => 1],
        'occurred_at' => now()->subMinutes(20),
    ]);

    $response = journeyFlowShowCart($cart->fresh(['events', 'laboratoryAppointments']))->assertOk();

    expect($response->json('data.journey.3.state'))->toBe('completed')
        ->and($response->json('data.journey.3.detail'))->toBe('Cita confirmada · pago disponible');
});

it('does not contaminate current cart journey with historical purchase payment or appointment', function () {
    $customerUser = journeyFlowCustomer();
    $test = journeyFlowTest();

    $cartA = Cart::query()->create([
        'user_id' => $customerUser->id,
        'type' => MonitoringCartType::Lab->value,
        'status' => MonitoringCartStatus::Completed->value,
        'total' => 1000.00,
        'completed_at' => now()->subDays(2),
        'created_at' => now()->subDays(3),
        'updated_at' => now()->subDays(2),
    ]);
    CartItem::query()->create([
        'cart_id' => $cartA->id,
        'product_id' => (string) $test->id,
        'name' => 'Estudio histórico',
        'price' => 1000.00,
        'quantity' => 1,
    ]);
    LaboratoryPurchase::query()->create([
        'customer_id' => $customerUser->customer->id,
        'cart_id' => $cartA->id,
        'brand' => LaboratoryBrand::OLAB->value,
        'gda_order_id' => 'gda-hist-a',
        'name' => 'Paciente',
        'paternal_lastname' => 'Hist',
        'maternal_lastname' => 'A',
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
        'created_at' => now()->subDays(2),
    ]);
    PaymentAttempt::query()->create([
        'customer_id' => $customerUser->customer->id,
        'cart_id' => $cartA->id,
        'amount_cents' => 100000,
        'gateway' => 'efevoopay',
        'status' => PaymentAttempt::STATUS_APPROVED,
        'processed_at' => now()->subDays(2),
    ]);

    $cartB = Cart::query()->create([
        'user_id' => $customerUser->id,
        'type' => MonitoringCartType::Lab->value,
        'status' => MonitoringCartStatus::Completed->value,
        'total' => 800.00,
        'completed_at' => now()->subDay(),
        'created_at' => now()->subDays(2),
        'updated_at' => now()->subDay(),
    ]);
    $purchaseB = LaboratoryPurchase::query()->create([
        'customer_id' => $customerUser->customer->id,
        'cart_id' => $cartB->id,
        'brand' => LaboratoryBrand::OLAB->value,
        'gda_order_id' => 'gda-hist-b',
        'name' => 'Paciente',
        'paternal_lastname' => 'Hist',
        'maternal_lastname' => 'B',
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
        'total_cents' => 80000,
        'created_at' => now()->subDay(),
    ]);
    LaboratoryAppointment::factory()->confirmed(now()->subDay(), now()->subDay())->create([
        'customer_id' => $customerUser->customer->id,
        'cart_id' => $cartB->id,
        'brand' => LaboratoryBrand::OLAB->value,
        'laboratory_purchase_id' => $purchaseB->id,
        'patient_gender' => null,
    ]);

    $cartC = journeyFlowActiveCart($customerUser, $test);
    journeyFlowAttachStandardPurchases($customerUser);
    $contact = Contact::factory()->create(['customer_id' => $customerUser->customer->id]);
    $address = Address::factory()->create(['customer_id' => $customerUser->customer->id]);

    app(SyncLaboratoryCheckoutDraftAction::class)(
        $customerUser->customer,
        LaboratoryBrand::OLAB,
        ['step' => 'patient', 'contact_id' => $contact->id],
    );
    app(SyncLaboratoryCheckoutDraftAction::class)(
        $customerUser->customer,
        LaboratoryBrand::OLAB,
        ['step' => 'address', 'contact_id' => $contact->id, 'address_id' => $address->id],
    );
    app(SyncLaboratoryCheckoutDraftAction::class)(
        $customerUser->customer,
        LaboratoryBrand::OLAB,
        ['step' => 'payment', 'payment_method' => 'paypal'],
    );
    app(SyncLaboratoryAppointmentFromContactAction::class)(
        $customerUser->customer,
        LaboratoryBrand::OLAB,
        $contact,
    );

    $response = journeyFlowShowCart($cartC->fresh(['events', 'laboratoryAppointments']))->assertOk();

    expect($response->json('data.journey.0.state'))->toBe('completed')
        ->and($response->json('data.journey.1.state'))->toBe('completed')
        ->and($response->json('data.journey.2.state'))->toBe('completed')
        ->and($response->json('data.journey.3.detail'))->toBe('Método seleccionado: PayPal')
        ->and($response->json('data.journey.4.detail'))->toBe('Esperando confirmación del concierge')
        ->and($response->json('data.journey.5.detail'))->toBe('Sin compra')
        ->and($response->json('data.final_payment'))->toBeNull();
});

it('shows call_requested from current cart in timeline', function () {
    $customerUser = journeyFlowCustomer();
    $test = journeyFlowTest();
    $cart = journeyFlowActiveCart($customerUser, $test);
    $contact = Contact::factory()->create(['customer_id' => $customerUser->customer->id]);

    app(SyncLaboratoryAppointmentFromContactAction::class)(
        $customerUser->customer,
        LaboratoryBrand::OLAB,
        $contact,
    );

    $appointment = LaboratoryAppointment::query()->where('cart_id', $cart->id)->firstOrFail();
    app(CartAppointmentContactSignalService::class)->recordCallRequested($appointment, 99001, true);

    $response = journeyFlowShowCart($cart->fresh(['events']))->assertOk();

    expect(collect($response->json('data.events'))->pluck('label'))
        ->toContain('Usuario solicitó llamada');
});

it('records appointment_pending_5m for current cart appointment', function () {
    $customerUser = journeyFlowCustomer();
    $test = journeyFlowTest();
    $cart = journeyFlowActiveCart($customerUser, $test);

    $appointment = LaboratoryAppointment::factory()->create([
        'customer_id' => $customerUser->customer->id,
        'cart_id' => $cart->id,
        'brand' => LaboratoryBrand::OLAB->value,
        'created_at' => now()->subMinutes(6),
        'updated_at' => now()->subMinutes(6),
        'confirmed_at' => null,
    ]);

    app(AppointmentPendingDetectionService::class)->detectAndRecord($appointment);

    $response = journeyFlowShowCart($cart->fresh(['events']))->assertOk();

    expect(collect($response->json('data.events'))->pluck('label'))
        ->toContain('Cita pendiente por 5 min');
});

it('refuses tests when database is not the testing sqlite database', function () {
    $originalDefault = config('database.default');
    $originalDatabase = config('database.connections.sqlite.database');

    Config::set('database.default', 'sqlite');
    Config::set('database.connections.sqlite.database', 'famedic_jun_23');

    $method = new ReflectionMethod(TestCase::class, 'assertTestingDatabaseIsSafe');
    $method->setAccessible(true);

    try {
        try {
            $method->invoke($this);
            expect(false)->toBeTrue('La guarda de TestCase debió bloquear la base protegida.');
        } catch (\Throwable $exception) {
            expect($exception->getMessage())->toContain('Refusing to run tests against protected database');
        }
    } finally {
        Config::set('database.default', $originalDefault);
        Config::set('database.connections.sqlite.database', $originalDatabase);
    }
});

it('derives journey payment method from checkout draft when event is missing', function () {
    $customerUser = journeyFlowCustomer();
    journeyFlowAttachStandardPurchases($customerUser);
    $test = journeyFlowTest();
    $cart = journeyFlowActiveCart($customerUser, $test);

    LaboratoryCheckoutDraft::query()->create([
        'customer_id' => $customerUser->customer->id,
        'laboratory_brand' => LaboratoryBrand::OLAB->value,
        'checkout_step' => 'appointment',
        'payment_method' => 'odessa',
    ]);

    $response = journeyFlowShowCart($cart->fresh())->assertOk();

    expect($response->json('data.journey.3.detail'))->toBe('Método seleccionado: Saldo a la Vista (Odessa)');
});

it('does not use historical payment attempt without cart_id in current cart journey', function () {
    $customerUser = journeyFlowCustomer();
    $test = journeyFlowTest();
    $cart = journeyFlowActiveCart($customerUser, $test);

    PaymentAttempt::query()->create([
        'customer_id' => $customerUser->customer->id,
        'amount_cents' => 100000,
        'gateway' => 'efevoopay',
        'status' => PaymentAttempt::STATUS_APPROVED,
        'processed_at' => now()->subMonths(2),
        'created_at' => now()->subMonths(2),
        'updated_at' => now()->subMonths(2),
    ]);

    $response = journeyFlowShowCart($cart->fresh())->assertOk();

    expect($response->json('data.journey.4.detail'))->toBe('Pago bloqueado');
});

it('does not use historical appointment without cart_id in current cart journey', function () {
    $customerUser = journeyFlowCustomer();
    $test = journeyFlowTest();
    $cart = journeyFlowActiveCart($customerUser, $test);

    LaboratoryAppointment::factory()->create([
        'customer_id' => $customerUser->customer->id,
        'cart_id' => null,
        'brand' => LaboratoryBrand::OLAB->value,
        'confirmed_at' => null,
    ]);

    $response = journeyFlowShowCart($cart->fresh())->assertOk();

    expect($response->json('data.journey.3.detail'))->toBe('No iniciada')
        ->and($response->json('data.appointment'))->toBeNull();
});

it('confirms tests run only against testing sqlite database', function () {
    expect(app()->environment())->toBe('testing')
        ->and(config('database.default'))->toBe('sqlite')
        ->and(strtolower((string) config('database.connections.sqlite.database')))->toContain('test');
});

it('records checkout_started once across multiple draft sync steps', function () {
    $customerUser = journeyFlowCustomer();
    $test = journeyFlowTest();
    $cart = journeyFlowActiveCart($customerUser, $test);
    $contact = Contact::factory()->create(['customer_id' => $customerUser->customer->id]);
    $address = Address::factory()->create(['customer_id' => $customerUser->customer->id]);

    app(SyncLaboratoryCheckoutDraftAction::class)(
        $customerUser->customer,
        LaboratoryBrand::OLAB,
        ['step' => 'patient', 'contact_id' => $contact->id],
    );
    app(SyncLaboratoryCheckoutDraftAction::class)(
        $customerUser->customer,
        LaboratoryBrand::OLAB,
        ['step' => 'address', 'contact_id' => $contact->id, 'address_id' => $address->id],
    );

    expect(CartEvent::query()
        ->where('cart_id', $cart->id)
        ->where('event', CartEventType::CheckoutStarted->value)
        ->count())->toBe(1);
});

it('uses checkout_visited for return visits without duplicating checkout_started', function () {
    $customerUser = journeyFlowCustomer();
    $test = journeyFlowTest();
    $cart = journeyFlowActiveCart($customerUser, $test);

    app(\App\Services\Carts\CartUserActivityResolver::class)->recordCheckoutVisit($cart, LaboratoryBrand::OLAB->value);
    app(\App\Services\Carts\CartUserActivityResolver::class)->recordCheckoutVisit($cart->fresh(), LaboratoryBrand::OLAB->value);

    expect(CartEvent::query()->where('cart_id', $cart->id)->where('event', CartEventType::CheckoutStarted->value)->count())->toBe(0)
        ->and(CartEvent::query()->where('cart_id', $cart->id)->where('event', CartEventType::CheckoutVisited->value)->count())->toBe(1);
});

it('shows a single checkout started label in journey timeline after multi-step sync', function () {
    $customerUser = journeyFlowCustomer();
    $test = journeyFlowTest();
    $cart = journeyFlowActiveCart($customerUser, $test);
    $contact = Contact::factory()->create(['customer_id' => $customerUser->customer->id]);
    $address = Address::factory()->create(['customer_id' => $customerUser->customer->id]);

    app(SyncLaboratoryCheckoutDraftAction::class)(
        $customerUser->customer,
        LaboratoryBrand::OLAB,
        ['step' => 'patient', 'contact_id' => $contact->id],
    );
    app(SyncLaboratoryCheckoutDraftAction::class)(
        $customerUser->customer,
        LaboratoryBrand::OLAB,
        ['step' => 'address', 'contact_id' => $contact->id, 'address_id' => $address->id],
    );

    $labels = collect(journeyFlowShowCart($cart->fresh(['events']))->json('data.events'))->pluck('label');

    expect($labels->filter(fn ($label) => $label === 'Checkout iniciado')->count())->toBe(1)
        ->and($labels->filter(fn ($label) => $label === 'Visita al checkout')->count())->toBeGreaterThanOrEqual(1);
});

it('does not enqueue activecampaign dispatches for checkout_started or checkout_visited events', function () {
    $customerUser = journeyFlowCustomer();
    $cart = journeyFlowActiveCart($customerUser, journeyFlowTest());

    app(\App\Services\Carts\CartUserActivityResolver::class)->recordCheckoutVisit($cart, LaboratoryBrand::OLAB->value);

    expect(\App\Models\ActiveCampaignDispatch::query()->count())->toBe(0);
});
