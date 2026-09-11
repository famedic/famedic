<?php

use App\Actions\Laboratories\SyncLaboratoryCheckoutDraftAction;
use App\Enums\CartCheckoutFlowType;
use App\Enums\CartEventType;
use App\Enums\Gender;
use App\Enums\LaboratoryBrand;
use App\Enums\MonitoringCartStatus;
use App\Enums\MonitoringCartType;
use App\Exports\Carts\CartsSheet;
use App\Models\Address;
use App\Models\Cart;
use App\Models\CartEvent;
use App\Models\CartItem;
use App\Models\Contact;
use App\Models\LaboratoryAppointment;
use App\Models\LaboratoryCartItem;
use App\Models\LaboratoryPurchase;
use App\Models\LaboratoryTest;
use App\Models\Transaction;
use App\Models\User;
use App\Services\Carts\CartAdminStageInterpreter;
use App\Services\Carts\CartCheckoutFlowResolver;
use App\Services\Monitoring\SyncMonitoringCartService;
use Spatie\Permission\Models\Permission;

function phase6Admin(): User
{
    Permission::findOrCreate('view cart details', 'web');
    $user = User::factory()->withAdministrator()->create();
    $user->administrator->givePermissionTo('view cart details');

    return $user;
}

function phase6Customer(): User
{
    return User::factory()->withRegularCustomer()->create();
}

function phase6Test(): LaboratoryTest
{
    return LaboratoryTest::factory()->create([
        'brand' => LaboratoryBrand::OLAB->value,
        'requires_appointment' => true,
    ]);
}

function phase6ActiveCart(User $user, LaboratoryTest $test, array $attributes = []): Cart
{
    LaboratoryCartItem::factory()->create([
        'customer_id' => $user->customer->id,
        'laboratory_test_id' => $test->id,
    ]);

    app(SyncMonitoringCartService::class)->syncLaboratory($user->customer);

    $cart = Cart::query()
        ->where('user_id', $user->id)
        ->where('type', MonitoringCartType::Lab)
        ->where('status', MonitoringCartStatus::Active)
        ->firstOrFail();

    $cart->update(array_merge(['total' => 1000.00], $attributes));

    CartItem::query()->updateOrCreate(
        ['cart_id' => $cart->id, 'product_id' => (string) $test->id],
        ['name' => 'Biometría', 'price' => 1000.00, 'quantity' => 1],
    );

    return $cart->fresh(['items', 'events']);
}

function phase6AttachStandardPurchases(User $user, int $count = 3): void
{
    for ($i = 0; $i < $count; $i++) {
        $purchase = LaboratoryPurchase::query()->create([
            'customer_id' => $user->customer->id,
            'brand' => LaboratoryBrand::OLAB->value,
            'gda_order_id' => 'gda-p6-'.fake()->unique()->numerify('######'),
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
        $tx = Transaction::query()->create([
            'transaction_amount_cents' => 50000,
            'payment_method' => 'efevoopay',
            'gateway' => 'efevoopay',
            'payment_status' => 'completed',
            'reference_id' => 'ref-p6-'.$purchase->id,
            'gateway_processed_at' => now(),
        ]);
        $purchase->transactions()->attach($tx->id);
    }
}

test('persists appointment-first flow durably even after customer completes third purchase', function () {
    $user = phase6Customer();
    $test = phase6Test();
    $cart = phase6ActiveCart($user, $test);

    app(SyncLaboratoryCheckoutDraftAction::class)(
        $user->customer,
        LaboratoryBrand::OLAB,
        ['step' => 'patient', 'contact_id' => Contact::factory()->create(['customer_id' => $user->customer->id])->id],
    );

    phase6AttachStandardPurchases($user, 1);

    $resolved = app(CartCheckoutFlowResolver::class)->resolve($cart->fresh(['events']), LaboratoryBrand::OLAB);

    expect($resolved['flow'])->toBe(CartCheckoutFlowType::AppointmentFirst)
        ->and($resolved['confidence'])->toBe('stored')
        ->and(CartEvent::query()->where('cart_id', $cart->id)->where('event', CartEventType::CheckoutFlowDetermined->value)->count())->toBe(1);
});

test('standard flow with three purchases remains standard', function () {
    $user = phase6Customer();
    phase6AttachStandardPurchases($user, 3);
    $test = phase6Test();
    $cart = phase6ActiveCart($user, $test);

    app(SyncLaboratoryCheckoutDraftAction::class)(
        $user->customer,
        LaboratoryBrand::OLAB,
        ['step' => 'patient', 'contact_id' => Contact::factory()->create(['customer_id' => $user->customer->id])->id],
    );

    $resolved = app(CartCheckoutFlowResolver::class)->resolve($cart->fresh(['events']), LaboratoryBrand::OLAB);

    expect($resolved['flow'])->toBe(CartCheckoutFlowType::Standard)
        ->and($resolved['confidence'])->toBe('stored');
});

test('infers appointment-first from event order for historical carts', function () {
    $user = phase6Customer();
    $cart = Cart::query()->create([
        'user_id' => $user->id,
        'type' => MonitoringCartType::Lab,
        'status' => MonitoringCartStatus::Completed,
        'total' => 800,
        'completed_at' => now()->subDay(),
    ]);
    CartItem::query()->create([
        'cart_id' => $cart->id,
        'product_id' => '1',
        'name' => 'Estudio',
        'price' => 800,
        'quantity' => 1,
    ]);

    CartEvent::query()->create([
        'cart_id' => $cart->id,
        'event' => CartEventType::AppointmentRequested->value,
        'metadata' => ['brand' => LaboratoryBrand::OLAB->value],
        'occurred_at' => now()->subDays(2),
        'source' => 'test',
    ]);
    CartEvent::query()->create([
        'cart_id' => $cart->id,
        'event' => CartEventType::PaymentMethodSelected->value,
        'metadata' => ['brand' => LaboratoryBrand::OLAB->value, 'gateway' => 'paypal'],
        'occurred_at' => now()->subDay(),
        'source' => 'test',
    ]);

    $resolved = app(CartCheckoutFlowResolver::class)->resolve($cart->fresh(['events']), LaboratoryBrand::OLAB);

    expect($resolved['flow'])->toBe(CartCheckoutFlowType::AppointmentFirst)
        ->and($resolved['confidence'])->toBe('inferred');
});

test('returns unknown when historical cart lacks flow evidence', function () {
    $user = phase6Customer();
    $cart = Cart::query()->create([
        'user_id' => $user->id,
        'type' => MonitoringCartType::Lab,
        'status' => MonitoringCartStatus::Completed,
        'total' => 500,
        'completed_at' => now()->subWeek(),
    ]);

    $resolved = app(CartCheckoutFlowResolver::class)->resolve($cart, LaboratoryBrand::OLAB);

    expect($resolved['flow'])->toBe(CartCheckoutFlowType::Unknown)
        ->and($resolved['confidence'])->toBe('unknown');
});

test('drawer 360 shows appointment-first journey order with blocked payment for pending appointment', function () {
    $admin = phase6Admin();
    $user = phase6Customer();
    $test = phase6Test();
    $cart = phase6ActiveCart($user, $test);
    $contact = Contact::factory()->create(['customer_id' => $user->customer->id]);
    $address = Address::factory()->create(['customer_id' => $user->customer->id]);

    app(SyncLaboratoryCheckoutDraftAction::class)($user->customer, LaboratoryBrand::OLAB, ['step' => 'patient', 'contact_id' => $contact->id]);
    app(SyncLaboratoryCheckoutDraftAction::class)($user->customer, LaboratoryBrand::OLAB, ['step' => 'address', 'contact_id' => $contact->id, 'address_id' => $address->id]);
    LaboratoryAppointment::factory()->create([
        'customer_id' => $user->customer->id,
        'cart_id' => $cart->id,
        'brand' => LaboratoryBrand::OLAB,
        'confirmed_at' => null,
        'patient_gender' => null,
    ]);

    $response = test()->actingAs($admin)->getJson(route('admin.carts.show', $cart->fresh(['events', 'laboratoryAppointments'])));

    $response->assertOk();
    $journey = collect($response->json('data.journey'));
    expect($response->json('data.checkout.flow.value'))->toBe('appointment_first')
        ->and($journey->pluck('key')->all())->toBe(['items', 'patient', 'address', 'appointment', 'payment', 'purchase'])
        ->and($journey->firstWhere('key', 'appointment')['detail'])->toBe('Esperando confirmación del concierge')
        ->and($journey->firstWhere('key', 'payment')['detail'])->toBe('Pago bloqueado');
});

test('drawer 360 shows standard journey with payment before appointment', function () {
    $admin = phase6Admin();
    $user = phase6Customer();
    phase6AttachStandardPurchases($user, 3);
    $test = phase6Test();
    $cart = phase6ActiveCart($user, $test);
    $contact = Contact::factory()->create(['customer_id' => $user->customer->id]);
    $address = Address::factory()->create(['customer_id' => $user->customer->id]);

    app(SyncLaboratoryCheckoutDraftAction::class)($user->customer, LaboratoryBrand::OLAB, ['step' => 'patient', 'contact_id' => $contact->id]);
    app(SyncLaboratoryCheckoutDraftAction::class)($user->customer, LaboratoryBrand::OLAB, ['step' => 'address', 'contact_id' => $contact->id, 'address_id' => $address->id]);
    LaboratoryAppointment::factory()->confirmed(now()->addDay(), now())->create([
        'customer_id' => $user->customer->id,
        'cart_id' => $cart->id,
        'brand' => LaboratoryBrand::OLAB->value,
        'patient_gender' => null,
    ]);
    app(SyncLaboratoryCheckoutDraftAction::class)($user->customer, LaboratoryBrand::OLAB, ['step' => 'payment', 'payment_method' => 'paypal']);

    $response = test()->actingAs($admin)->getJson(route('admin.carts.show', $cart->fresh(['events', 'laboratoryAppointments'])));

    expect(collect($response->json('data.journey'))->pluck('key')->all())
        ->toBe(['items', 'patient', 'address', 'payment', 'appointment', 'purchase']);
});

test('pending appointment cart is not displayed as abandoned in admin context', function () {
    $user = phase6Customer();
    $test = phase6Test();
    $cart = phase6ActiveCart($user, $test, ['updated_at' => now()->subHours(3)]);
    LaboratoryAppointment::factory()->create([
        'customer_id' => $user->customer->id,
        'cart_id' => $cart->id,
        'brand' => LaboratoryBrand::OLAB,
        'confirmed_at' => null,
        'patient_gender' => null,
    ]);

    $context = app(CartAdminStageInterpreter::class)->context($cart->fresh(['events', 'laboratoryAppointments']));

    expect($context['display_status'])->toBe('active');
});

test('export appends phase 6 columns without changing legacy headers', function () {
    $headings = (new CartsSheet)->headings();

    expect($headings[0])->toBe('ID carrito')
        ->and($headings)->toContain('Tipo de flujo')
        ->and($headings)->toContain('Ultima actividad real')
        ->and(end($headings))->toBe('Cart ID correlacionado');
});

test('checkout flow recording is idempotent on repeated draft sync', function () {
    $user = phase6Customer();
    $test = phase6Test();
    $cart = phase6ActiveCart($user, $test);
    $contact = Contact::factory()->create(['customer_id' => $user->customer->id]);

    app(SyncLaboratoryCheckoutDraftAction::class)($user->customer, LaboratoryBrand::OLAB, ['step' => 'patient', 'contact_id' => $contact->id]);
    app(SyncLaboratoryCheckoutDraftAction::class)($user->customer, LaboratoryBrand::OLAB, ['step' => 'patient', 'contact_id' => $contact->id]);

    expect(CartEvent::query()
        ->where('cart_id', $cart->id)
        ->where('event', CartEventType::CheckoutFlowDetermined->value)
        ->count())->toBe(1);
});
