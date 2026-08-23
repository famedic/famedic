<?php

use App\Actions\Laboratories\SyncLaboratoryCheckoutDraftAction;
use App\Enums\CartEventType;
use App\Enums\LaboratoryBrand;
use App\Enums\MonitoringCartStatus;
use App\Enums\MonitoringCartType;
use App\Models\Address;
use App\Models\Cart;
use App\Models\Contact;
use App\Models\LaboratoryAppointment;
use App\Models\LaboratoryCartItem;
use App\Models\LaboratoryPurchase;
use App\Models\LaboratoryTest;
use App\Models\PaymentAttempt;
use App\Models\User;
use App\Services\Carts\CartEventRecorder;
use App\Services\Monitoring\SyncMonitoringCartService;
use App\Support\ClientContext;

function cartTraceabilityUser(): User
{
    return User::factory()->withRegularCustomer()->create();
}

function traceabilityCart(User $user, array $attributes = []): Cart
{
    return Cart::query()->create(array_merge([
        'user_id' => $user->id,
        'type' => MonitoringCartType::Lab->value,
        'status' => MonitoringCartStatus::Active->value,
        'total' => 1000.00,
        'created_at' => now()->subHour(),
        'updated_at' => now()->subMinutes(20),
    ], $attributes));
}

function traceabilityPurchase(User $user, array $attributes = []): LaboratoryPurchase
{
    return LaboratoryPurchase::query()->create(array_merge([
        'brand' => LaboratoryBrand::OLAB->value,
        'gda_order_id' => uniqid('gda-', true),
        'name' => 'Paciente',
        'paternal_lastname' => 'Prueba',
        'maternal_lastname' => 'Trace',
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
        'customer_id' => $user->customer->id,
    ], $attributes));
}

it('records idempotent cart events and removes sensitive metadata', function () {
    $cart = traceabilityCart(cartTraceabilityUser());

    $recorder = app(CartEventRecorder::class);
    $recorder->recordOnce(
        $cart,
        CartEventType::PaymentDeclined,
        'attempt-1-declined',
        [
            'payment_attempt_id' => 123,
            'processor_code' => '87',
            'raw_response' => ['secret' => 'hidden'],
            'card_token' => 'tok_hidden',
        ],
        source: 'test',
    );
    $recorder->recordOnce($cart, CartEventType::PaymentDeclined, 'attempt-1-declined', source: 'test');

    $event = $cart->events()->first();

    expect($cart->events()->count())->toBe(1)
        ->and($event->event)->toBe(CartEventType::PaymentDeclined)
        ->and($event->metadata)->toHaveKey('payment_attempt_id')
        ->and($event->metadata)->toHaveKey('processor_code')
        ->and($event->metadata)->not->toHaveKey('raw_response')
        ->and($event->metadata)->not->toHaveKey('card_token');
});

it('captures mobile client context on checkout cart events and preserves functional metadata', function () {
    $user = cartTraceabilityUser();
    $test = LaboratoryTest::factory()->create([
        'brand' => LaboratoryBrand::OLAB->value,
        'requires_appointment' => true,
    ]);
    LaboratoryCartItem::factory()->create([
        'customer_id' => $user->customer->id,
        'laboratory_test_id' => $test->id,
    ]);
    app(SyncMonitoringCartService::class)->syncLaboratory($user->customer);
    $cart = Cart::query()->where('user_id', $user->id)->where('type', MonitoringCartType::Lab)->first();
    Contact::factory()->create(['customer_id' => $user->customer->id]);

    app(SyncLaboratoryCheckoutDraftAction::class)(
        $user->customer,
        LaboratoryBrand::OLAB,
        [
            'step' => 'patient',
            'contact_id' => $user->customer->contacts()->first()->id,
        ],
        ClientContext::fromUserAgent('Mozilla/5.0 (iPhone; CPU iPhone OS 17_0 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/17.0 Mobile/15E148 Safari/604.1'),
    );

    $event = $cart->events()->where('event', CartEventType::PatientSelected->value)->first();

    expect($event)->not->toBeNull()
        ->and($event->metadata['brand'])->toBe(LaboratoryBrand::OLAB->value)
        ->and($event->metadata['contact_id'])->toBe($user->customer->contacts()->first()->id)
        ->and($event->metadata['client']['device_type'])->toBe('mobile')
        ->and($event->metadata['client']['browser'])->toBe('Safari')
        ->and($event->metadata['client']['os'])->toBe('iOS');
});

it('captures desktop client context on checkout cart events', function () {
    $user = cartTraceabilityUser();
    $test = LaboratoryTest::factory()->create([
        'brand' => LaboratoryBrand::OLAB->value,
        'requires_appointment' => true,
    ]);
    LaboratoryCartItem::factory()->create([
        'customer_id' => $user->customer->id,
        'laboratory_test_id' => $test->id,
    ]);
    app(SyncMonitoringCartService::class)->syncLaboratory($user->customer);
    $cart = Cart::query()->where('user_id', $user->id)->where('type', MonitoringCartType::Lab)->first();
    $contact = Contact::factory()->create(['customer_id' => $user->customer->id]);
    $address = Address::factory()->create(['customer_id' => $user->customer->id]);

    app(SyncLaboratoryCheckoutDraftAction::class)(
        $user->customer,
        LaboratoryBrand::OLAB,
        [
            'step' => 'address',
            'contact_id' => $contact->id,
            'address_id' => $address->id,
        ],
        ClientContext::fromUserAgent('Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36'),
    );

    $event = $cart->events()->where('event', CartEventType::AddressSelected->value)->first();

    expect($event)->not->toBeNull()
        ->and($event->metadata['client']['device_type'])->toBe('desktop')
        ->and($event->metadata['client']['browser'])->toBe('Chrome')
        ->and($event->metadata['client']['os'])->toBe('Windows');
});

it('keeps recordOnce idempotent when client metadata changes', function () {
    $cart = traceabilityCart(cartTraceabilityUser());
    $recorder = app(CartEventRecorder::class);

    $recorder->recordOnce($cart, CartEventType::CheckoutStarted, 'same-key', [
        'brand' => 'olab',
        'client' => ['device_type' => 'mobile', 'browser' => 'Safari', 'os' => 'iOS', 'source' => 'request_user_agent'],
    ]);
    $recorder->recordOnce($cart, CartEventType::CheckoutStarted, 'same-key', [
        'brand' => 'olab',
        'client' => ['device_type' => 'desktop', 'browser' => 'Chrome', 'os' => 'Windows', 'source' => 'request_user_agent'],
    ]);

    $event = $cart->events()->first();

    expect($cart->events()->count())->toBe(1)
        ->and($event->metadata['client']['device_type'])->toBe('mobile');
});

it('does not invent client context for async events without request context', function () {
    $cart = traceabilityCart(cartTraceabilityUser());

    app(CartEventRecorder::class)->recordOnce(
        $cart,
        CartEventType::CartCompleted,
        'async-completed',
        ['status' => 'completed'],
        source: 'job',
    );

    expect($cart->events()->first()->metadata)->not->toHaveKey('client');
});

it('uses explicit purchase and appointment links before historical fallback', function () {
    $user = cartTraceabilityUser();
    $cart = traceabilityCart($user, [
        'status' => MonitoringCartStatus::Completed->value,
        'completed_at' => now()->subMinutes(10),
        'updated_at' => now()->subMinutes(10),
    ]);

    $explicitPurchase = traceabilityPurchase($user, [
        'cart_id' => $cart->id,
        'created_at' => now()->subMinutes(20),
        'updated_at' => now()->subMinutes(20),
    ]);
    traceabilityPurchase($user, [
        'created_at' => now()->subMinute(),
        'updated_at' => now()->subMinute(),
    ]);

    $explicitAppointment = LaboratoryAppointment::query()->create([
        'customer_id' => $user->customer->id,
        'cart_id' => $cart->id,
        'brand' => LaboratoryBrand::OLAB->value,
        'laboratory_purchase_id' => $explicitPurchase->id,
        'confirmed_at' => now()->subMinutes(15),
    ]);
    LaboratoryAppointment::query()->create([
        'customer_id' => $user->customer->id,
        'brand' => LaboratoryBrand::OLAB->value,
        'confirmed_at' => null,
    ]);

    $cart = $cart->fresh(['user.customer', 'laboratoryPurchases', 'laboratoryAppointments']);

    expect($cart->relatedLaboratoryPurchase()->id)->toBe($explicitPurchase->id)
        ->and($cart->laboratoryAppointmentsForDisplay()->pluck('id')->all())->toBe([$explicitAppointment->id]);
});

it('filters payment status by explicit attempts before legacy fallback', function () {
    $user = cartTraceabilityUser();
    $explicitCart = traceabilityCart($user);

    PaymentAttempt::query()->create([
        'customer_id' => $user->customer->id,
        'cart_id' => $explicitCart->id,
        'amount_cents' => 100000,
        'gateway' => 'efevoopay',
        'status' => PaymentAttempt::STATUS_DECLINED,
        'processed_at' => now()->subMinutes(5),
    ]);
    PaymentAttempt::query()->create([
        'customer_id' => $user->customer->id,
        'amount_cents' => 100000,
        'gateway' => 'efevoopay',
        'status' => PaymentAttempt::STATUS_APPROVED,
        'created_at' => now()->subMinutes(6),
        'updated_at' => now()->subMinutes(5),
        'processed_at' => now()->subMinutes(5),
    ]);

    $legacyUser = cartTraceabilityUser();
    $legacyCart = traceabilityCart($legacyUser, ['total' => 700.00]);
    PaymentAttempt::query()->create([
        'customer_id' => $legacyUser->customer->id,
        'amount_cents' => 70000,
        'gateway' => 'efevoopay',
        'status' => PaymentAttempt::STATUS_ERROR,
        'created_at' => now()->subMinutes(6),
        'updated_at' => now()->subMinutes(5),
        'processed_at' => now()->subMinutes(5),
    ]);

    expect(Cart::query()->relatedPaymentAttemptStatus(PaymentAttempt::STATUS_DECLINED)->pluck('id')->all())
        ->toContain($explicitCart->id)
        ->and(Cart::query()->relatedPaymentAttemptStatus(PaymentAttempt::STATUS_APPROVED)->pluck('id')->all())
        ->not->toContain($explicitCart->id)
        ->and(Cart::query()->relatedPaymentAttemptStatus(PaymentAttempt::STATUS_ERROR)->pluck('id')->all())
        ->toContain($legacyCart->id);
});
