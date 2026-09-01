<?php

use App\Enums\CartEventType;
use App\Enums\Gender;
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
use App\Models\LaboratoryPurchase;
use App\Models\LaboratoryStore;
use App\Models\LaboratoryTest;
use App\Models\Transaction;
use App\Models\User;
use App\Notifications\LaboratoryAppointmentConfirmedPendingPayment;
use App\Services\Carts\CartAbandonmentService;
use App\Services\Laboratory\LaboratoryAppointmentCheckoutResolver;
use App\Services\Laboratory\LaboratoryAppointmentPaymentValidity;
use App\Services\Laboratory\LaboratoryCheckoutStepGuard;
use Carbon\Carbon;

beforeEach(function () {
    $this->withoutMiddleware([
        \App\Http\Middleware\RedirectIfEmptyLaboratoryCartItems::class,
        \App\Http\Middleware\RedirectIfUserProfileIsIncomplete::class,
        \App\Http\Middleware\EnsureDocumentationIsAccepted::class,
        \App\Http\Middleware\EnsurePhoneIsVerified::class,
        \Illuminate\Auth\Middleware\EnsureEmailIsVerified::class,
    ]);

    $this->stepGuard = app(LaboratoryCheckoutStepGuard::class);
    $this->abandonmentService = app(CartAbandonmentService::class);
});

function phase5User(): User
{
    return User::factory()
        ->withCompleteProfile()
        ->withRegularCustomer()
        ->create(['documentation_accepted_at' => now()])
        ->fresh(['customer']);
}

function phase5ActiveCart(User $user, LaboratoryBrand $brand = LaboratoryBrand::OLAB): Cart
{
    $test = LaboratoryTest::factory()->create([
        'brand' => $brand->value,
        'requires_appointment' => true,
        'famedic_price_cents' => 50000,
    ]);

    LaboratoryCartItem::factory()->create([
        'customer_id' => $user->customer->id,
        'laboratory_test_id' => $test->id,
    ]);

    $cart = Cart::query()->create([
        'user_id' => $user->id,
        'type' => MonitoringCartType::Lab->value,
        'status' => MonitoringCartStatus::Active->value,
        'total' => 500,
    ]);

    CartItem::query()->create([
        'cart_id' => $cart->id,
        'product_id' => (string) $test->id,
        'name' => $test->name,
        'price' => 500,
        'quantity' => 1,
    ]);

    return $cart;
}

function phase5Store(): LaboratoryStore
{
    return LaboratoryStore::factory()->create([
        'brand' => LaboratoryBrand::OLAB->value,
        'name' => 'Sucursal Centro',
        'address' => 'Calle Test 123',
        'state' => 'NL',
        'weekly_hours' => '9-18',
        'saturday_hours' => '9-14',
        'sunday_hours' => 'Cerrado',
        'google_maps_url' => 'https://maps.example.com',
    ]);
}

function phase5Appointment(
    User $user,
    ?Carbon $appointmentAt = null,
    ?Carbon $confirmedAt = null,
    ?int $cartId = null,
): LaboratoryAppointment {
    return LaboratoryAppointment::query()->create([
        'customer_id' => $user->customer->id,
        'brand' => LaboratoryBrand::OLAB,
        'cart_id' => $cartId,
        'laboratory_store_id' => phase5Store()->id,
        'patient_name' => 'Ana',
        'patient_paternal_lastname' => 'Lopez',
        'patient_maternal_lastname' => 'Perez',
        'patient_birth_date' => '1990-01-01',
        'patient_gender' => Gender::FEMALE,
        'patient_phone' => '8111111111',
        'patient_phone_country' => 'MX',
        'appointment_date' => $appointmentAt,
        'confirmed_at' => $confirmedAt,
    ]);
}

function attachStandardFlowPurchases(User $user, int $count = 3): void
{
    for ($i = 0; $i < $count; $i++) {
        $purchase = LaboratoryPurchase::query()->create([
            'customer_id' => $user->customer->id,
            'brand' => LaboratoryBrand::OLAB->value,
            'gda_order_id' => 'gda-std5-'.fake()->unique()->numerify('######'),
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
            'reference_id' => 'ref-std5-'.$purchase->id,
            'gateway_processed_at' => now(),
        ]);

        $purchase->transactions()->attach($transaction->id);
    }
}

test('confirmed appointment without scheduled date blocks payment', function () {
    $user = phase5User();
    phase5ActiveCart($user);

    $appointment = phase5Appointment(
        $user,
        appointmentAt: null,
        confirmedAt: now(),
    );

    expect(app(LaboratoryAppointmentPaymentValidity::class)->isValidForPayment($appointment))->toBeFalse()
        ->and(app(LaboratoryAppointmentCheckoutResolver::class)
            ->payableConfirmedAppointment($user->customer, LaboratoryBrand::OLAB))->toBeNull()
        ->and($this->stepGuard->canInitiatePayment($user->customer, LaboratoryBrand::OLAB))->toBeFalse();
});

test('confirmed appointment with future date allows payment', function () {
    $user = phase5User();
    phase5ActiveCart($user);

    phase5Appointment(
        $user,
        appointmentAt: now('America/Monterrey')->addDays(2)->setTime(10, 0),
        confirmedAt: now(),
    );

    expect(app(LaboratoryAppointmentCheckoutResolver::class)
        ->payableConfirmedAppointment($user->customer, LaboratoryBrand::OLAB))->not->toBeNull()
        ->and($this->stepGuard->canInitiatePayment($user->customer, LaboratoryBrand::OLAB))->toBeTrue();
});

test('paypal uses the same payment validity rule for missing schedule', function () {
    $user = phase5User();
    phase5ActiveCart($user);
    [$contact, $address] = [
        Contact::factory()->create(['customer_id' => $user->customer->id]),
        Address::factory()->create(['customer_id' => $user->customer->id]),
    ];

    phase5Appointment(
        $user,
        appointmentAt: null,
        confirmedAt: now(),
    );

    expect($this->stepGuard->canInitiatePayment($user->customer, LaboratoryBrand::OLAB))->toBeFalse();

    $this->actingAs($user)
        ->postJson(route('paypal.create-order'), [
            'patient_id' => $contact->id,
            'address_id' => $address->id,
            'laboratory_brand' => LaboratoryBrand::OLAB->value,
            'total' => 50000,
        ])
        ->assertStatus(422)
        ->assertJsonValidationErrors(['patient_id']);
});

test('checkout redirects to appointment when confirmed without scheduled date', function () {
    $user = phase5User();
    phase5ActiveCart($user);
    $contact = Contact::factory()->create(['customer_id' => $user->customer->id]);
    $address = Address::factory()->create(['customer_id' => $user->customer->id]);

    phase5Appointment(
        $user,
        appointmentAt: null,
        confirmedAt: now(),
    );

    $this->actingAs($user)
        ->get(route('laboratory.checkout', [
            'laboratory_brand' => LaboratoryBrand::OLAB,
            'step' => 'payment',
            'contact' => $contact->id,
            'address' => $address->id,
        ]))
        ->assertRedirect()
        ->assertSessionHas('checkout_step_notice', function ($message) {
            return str_contains($message, 'fecha programada');
        });
});

test('appointment first email keeps confirmed copy', function () {
    $user = phase5User();
    $appointment = phase5Appointment(
        $user,
        appointmentAt: now('America/Monterrey')->addDay(),
        confirmedAt: now(),
    );

    $notification = new LaboratoryAppointmentConfirmedPendingPayment(
        $appointment,
        'https://example.test/checkout',
        true,
    );

    $mail = $notification->toMail($user);
    $rendered = app(\Illuminate\Mail\Markdown::class)->render(
        $mail->markdown,
        $mail->viewData,
    );
    $html = is_array($rendered) ? ($rendered['html'] ?? '') : (string) $rendered;

    expect($mail->subject)->toBe('Tu cita de laboratorio fue confirmada')
        ->and($html)->toContain('Tu cita ya fue confirmada')
        ->and($html)->toContain('continuar con el último paso');
});

test('standard flow email uses confirmed not pay to confirm copy', function () {
    $user = phase5User();
    attachStandardFlowPurchases($user);

    $appointment = phase5Appointment(
        $user,
        appointmentAt: now('America/Monterrey')->addDay(),
        confirmedAt: now(),
    );

    $notification = new LaboratoryAppointmentConfirmedPendingPayment(
        $appointment,
        'https://example.test/checkout',
        false,
    );

    $mail = $notification->toMail($user);
    $rendered = app(\Illuminate\Mail\Markdown::class)->render(
        $mail->markdown,
        $mail->viewData,
    );
    $html = is_array($rendered) ? ($rendered['html'] ?? '') : (string) $rendered;

    expect($mail->subject)->toBe('Tu cita está registrada — completa el pago en Famedic')
        ->and($html)->toContain('Tu cita fue confirmada')
        ->and($html)->toContain('Completa el pago para finalizar tu compra')
        ->and($html)->not->toContain('Paga para confirmar')
        ->and($html)->not->toContain('Al finalizar, tu cita quedará confirmada');
});

test('pending appointment excludes cart from abandonment detection', function () {
    $user = phase5User();
    $cart = phase5ActiveCart($user);

    phase5Appointment(
        $user,
        appointmentAt: now('America/Monterrey')->addDay(),
        confirmedAt: null,
    );

    $cart->update(['updated_at' => now()->subHours(2)]);

    expect(app(LaboratoryAppointmentCheckoutResolver::class)
        ->isAwaitingConcierge($user->customer, LaboratoryBrand::OLAB))->toBeTrue()
        ->and($this->abandonmentService->recordAbandoned($cart->fresh()))->toBeNull();
});

test('confirmed appointment resets abandonment reference to confirmed_at', function () {
    $user = phase5User();
    $cart = phase5ActiveCart($user);

    phase5Appointment(
        $user,
        appointmentAt: now('America/Monterrey')->addDays(2),
        confirmedAt: now()->subMinutes(15),
    );

    $cart->update(['updated_at' => now()->subHours(2)]);

    expect($this->abandonmentService->recordAbandoned($cart->fresh()))->toBeNull();
});

test('confirmed unpaid appointment can be abandoned after threshold from confirmed_at', function () {
    $user = phase5User();
    $cart = phase5ActiveCart($user);

    phase5Appointment(
        $user,
        appointmentAt: now('America/Monterrey')->addDays(2),
        confirmedAt: now()->subMinutes(45),
    );

    CartEvent::query()->create([
        'cart_id' => $cart->id,
        'event' => CartEventType::CheckoutStarted->value,
        'metadata' => ['brand' => LaboratoryBrand::OLAB->value],
        'occurred_at' => now()->subHours(3),
        'source' => 'test_setup',
    ]);

    $event = $this->abandonmentService->recordAbandoned($cart->fresh());

    expect($event)->not->toBeNull()
        ->and($event->event)->toBe(CartEventType::CartAbandoned)
        ->and($event->metadata['checkout_stage'] ?? null)->toBe('payment')
        ->and($event->metadata['flow'] ?? null)->toBe('appointment_first');
});

test('cart resumed on checkout return after abandonment episode', function () {
    $user = phase5User();
    phase5ActiveCart($user);
    $contact = Contact::factory()->create(['customer_id' => $user->customer->id]);
    $address = Address::factory()->create(['customer_id' => $user->customer->id]);

    phase5Appointment(
        $user,
        appointmentAt: now('America/Monterrey')->addDays(2),
        confirmedAt: now()->subMinutes(45),
    );

    $cart = Cart::query()->where('user_id', $user->id)->first();
    CartEvent::query()->create([
        'cart_id' => $cart->id,
        'event' => CartEventType::CheckoutStarted->value,
        'metadata' => ['brand' => LaboratoryBrand::OLAB->value],
        'occurred_at' => now()->subHours(3),
        'source' => 'test_setup',
    ]);
    $this->abandonmentService->recordAbandoned($cart->fresh());

    $this->actingAs($user)
        ->get(route('laboratory.checkout', [
            'laboratory_brand' => LaboratoryBrand::OLAB,
            'step' => 'payment',
            'contact' => $contact->id,
            'address' => $address->id,
        ]))
        ->assertOk();

    expect(CartEvent::query()
        ->where('cart_id', $cart->id)
        ->where('event', CartEventType::CartResumed->value)
        ->exists())->toBeTrue();
});
