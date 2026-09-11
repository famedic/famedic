<?php

use App\Actions\Admin\LaboratoryAppointments\UpdateLaboratoryAppointmentAction;
use App\Actions\Laboratories\PrepareLaboratoryCheckoutPaymentLinkAction;
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
use App\Models\LaboratoryCheckoutDraft;
use App\Models\LaboratoryPurchase;
use App\Models\LaboratoryStore;
use App\Models\LaboratoryTest;
use App\Models\Transaction;
use App\Models\User;
use App\Notifications\LaboratoryAppointmentConfirmedPendingPayment;
use App\Services\Laboratory\LaboratoryAppointmentCheckoutResolver;
use App\Services\Laboratory\LaboratoryAppointmentPaymentValidity;
use App\Services\Laboratory\LaboratoryCheckoutFlowEligibility;
use Carbon\Carbon;
use Illuminate\Support\Facades\Notification;

beforeEach(function () {
    $this->withoutMiddleware([
        \App\Http\Middleware\RedirectIfEmptyLaboratoryCartItems::class,
        \App\Http\Middleware\RedirectIfUserProfileIsIncomplete::class,
        \App\Http\Middleware\EnsureDocumentationIsAccepted::class,
        \App\Http\Middleware\EnsurePhoneIsVerified::class,
        \Illuminate\Auth\Middleware\EnsureEmailIsVerified::class,
    ]);

    Notification::fake();
});

function phase4User(): User
{
    return User::factory()
        ->withCompleteProfile()
        ->withRegularCustomer()
        ->create(['documentation_accepted_at' => now()])
        ->fresh(['customer']);
}

function phase4Cart(User $user, LaboratoryBrand $brand = LaboratoryBrand::OLAB): Cart
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

function phase4Store(): LaboratoryStore
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

function phase4ConfirmedAppointment(
    User $user,
    ?Cart $cart = null,
    ?Carbon $appointmentAt = null,
    ?Carbon $confirmedAt = null,
    bool $withConfirmedAt = true,
): LaboratoryAppointment {
    $store = phase4Store();

    return LaboratoryAppointment::query()->create([
        'customer_id' => $user->customer->id,
        'brand' => LaboratoryBrand::OLAB,
        'cart_id' => $cart?->id,
        'laboratory_store_id' => $store->id,
        'patient_name' => 'Ana',
        'patient_paternal_lastname' => 'Lopez',
        'patient_maternal_lastname' => 'Perez',
        'patient_birth_date' => '1990-01-01',
        'patient_gender' => Gender::FEMALE,
        'patient_phone' => '8111111111',
        'patient_phone_country' => 'MX',
        'appointment_date' => $appointmentAt ?? now('America/Monterrey')->addDays(2)->setTime(10, 0),
        'confirmed_at' => $withConfirmedAt ? ($confirmedAt ?? now()->subHours(30)) : null,
    ]);
}

function attachStandardFlowPurchase(User $user, LaboratoryBrand $brand = LaboratoryBrand::OLAB): void
{
    $purchase = LaboratoryPurchase::query()->create([
        'customer_id' => $user->customer->id,
        'brand' => $brand->value,
        'gda_order_id' => 'gda-std-'.fake()->unique()->numerify('######'),
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
        'reference_id' => 'ref-std-'.$purchase->id,
        'gateway_processed_at' => now(),
    ]);

    $purchase->transactions()->attach($transaction->id);
}

test('appointment first payment link uses step payment', function () {
    $user = phase4User();
    $cart = phase4Cart($user);
    $contact = Contact::factory()->create(['customer_id' => $user->customer->id]);

    LaboratoryCheckoutDraft::query()->create([
        'customer_id' => $user->customer->id,
        'laboratory_brand' => LaboratoryBrand::OLAB,
        'contact_id' => $contact->id,
        'checkout_step' => 'appointment',
    ]);

    $appointment = phase4ConfirmedAppointment($user);

    $url = app(PrepareLaboratoryCheckoutPaymentLinkAction::class)($appointment);

    expect($url)->toContain('step=payment')
        ->and($url)->toContain('/laboratory/'.LaboratoryBrand::OLAB->value.'/checkout');
});

test('standard flow payment link uses step confirmation', function () {
    $user = phase4User();
    $cart = phase4Cart($user);
    attachStandardFlowPurchase($user);
    attachStandardFlowPurchase($user);
    attachStandardFlowPurchase($user);

    $appointment = phase4ConfirmedAppointment($user);

    $url = app(PrepareLaboratoryCheckoutPaymentLinkAction::class)($appointment);

    expect($url)->toContain('step=confirmation');
});

test('can send pending payment email only when appointment is payable', function () {
    $user = phase4User();
    $cart = phase4Cart($user);
    $appointment = phase4ConfirmedAppointment($user, $cart);
    $action = app(PrepareLaboratoryCheckoutPaymentLinkAction::class);

    expect($action->canSendPendingPaymentEmail($appointment))->toBeTrue();

    $appointment->delete();

    expect($action->canSendPendingPaymentEmail($appointment->fresh()))->toBeFalse();
});

test('paid purchase suppresses pending payment email eligibility', function () {
    $user = phase4User();
    $cart = phase4Cart($user);
    $appointment = phase4ConfirmedAppointment($user);

    $purchase = LaboratoryPurchase::query()->create([
        'customer_id' => $user->customer->id,
        'brand' => LaboratoryBrand::OLAB->value,
        'gda_order_id' => 'gda-paid-'.fake()->unique()->numerify('######'),
        'name' => 'Ana',
        'paternal_lastname' => 'Lopez',
        'maternal_lastname' => 'Perez',
        'phone' => '8111111111',
        'phone_country' => 'MX',
        'birth_date' => '1990-01-01',
        'gender' => Gender::FEMALE,
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
        'reference_id' => 'ref-paid-'.$purchase->id,
        'gateway_processed_at' => now(),
    ]);

    $purchase->transactions()->attach($transaction->id);

    $appointment->update(['laboratory_purchase_id' => $purchase->id]);

    expect(app(PrepareLaboratoryCheckoutPaymentLinkAction::class)->canSendPendingPaymentEmail($appointment->fresh()))->toBeFalse();
});

test('appointment first notification uses confirmed subject', function () {
    $user = phase4User();
    $cart = phase4Cart($user);
    $appointment = phase4ConfirmedAppointment($user, $cart);

    $user->notify(new LaboratoryAppointmentConfirmedPendingPayment(
        $appointment,
        'https://example.test/checkout',
        true,
    ));

    Notification::assertSentTo(
        $user,
        LaboratoryAppointmentConfirmedPendingPayment::class,
        function (LaboratoryAppointmentConfirmedPendingPayment $notification) use ($user) {
            $mail = $notification->toMail($user);

            return $mail->subject === 'Tu cita de laboratorio fue confirmada';
        },
    );
});

test('first confirmation transition is detected only once', function () {
    $user = phase4User();
    $cart = phase4Cart($user);
    $store = phase4Store();
    $appointment = phase4ConfirmedAppointment($user, $cart, withConfirmedAt: false);

    $wasConfirmedBefore = $appointment->confirmed_at !== null;

    app(UpdateLaboratoryAppointmentAction::class)(
        appointment_date: now('America/Monterrey')->addDays(2)->format('Y-m-d'),
        appointment_time: '10:00',
        patient_name: $appointment->patient_name,
        patient_paternal_lastname: $appointment->patient_paternal_lastname,
        patient_maternal_lastname: $appointment->patient_maternal_lastname,
        patient_birth_date: Carbon::parse($appointment->patient_birth_date),
        patient_gender: Gender::FEMALE,
        patient_phone: '8111111111',
        patient_phone_country: 'MX',
        laboratory_store: $store->id,
        notes: null,
        laboratoryAppointment: $appointment,
    );

    $appointment->refresh();

    expect($wasConfirmedBefore)->toBeFalse()
        ->and($appointment->confirmed_at)->not->toBeNull();

    $wasConfirmedBeforeSecondSave = $appointment->confirmed_at !== null;

    app(UpdateLaboratoryAppointmentAction::class)(
        appointment_date: now('America/Monterrey')->addDays(4)->format('Y-m-d'),
        appointment_time: '12:00',
        patient_name: $appointment->patient_name,
        patient_paternal_lastname: $appointment->patient_paternal_lastname,
        patient_maternal_lastname: $appointment->patient_maternal_lastname,
        patient_birth_date: Carbon::parse($appointment->patient_birth_date),
        patient_gender: Gender::FEMALE,
        patient_phone: '8111111111',
        patient_phone_country: 'MX',
        laboratory_store: $store->id,
        notes: 'Reprogramada',
        laboratoryAppointment: $appointment,
    );

    expect($wasConfirmedBeforeSecondSave)->toBeTrue();
});

test('confirmed more than 24 hours ago but future appointment remains payable', function () {
    $user = phase4User();
    phase4Cart($user);

    $appointment = phase4ConfirmedAppointment(
        $user,
        null,
        now('America/Monterrey')->addDays(3)->setTime(15, 0),
        now('America/Monterrey')->subHours(30),
    );

    $payable = app(LaboratoryAppointmentCheckoutResolver::class)
        ->payableConfirmedAppointment($user->customer, LaboratoryBrand::OLAB);

    expect($payable?->id)->toBe($appointment->id)
        ->and(app(LaboratoryAppointmentPaymentValidity::class)->isValidForPayment($appointment))->toBeTrue();
});

test('expired scheduled appointment blocks payment', function () {
    $user = phase4User();
    $cart = phase4Cart($user);

    $appointment = phase4ConfirmedAppointment(
        $user,
        $cart,
        now('America/Monterrey')->subDay()->setTime(10, 0),
        now('America/Monterrey')->subDays(2),
    );

    expect(app(LaboratoryAppointmentPaymentValidity::class)->isValidForPayment($appointment))->toBeFalse()
        ->and(app(LaboratoryAppointmentCheckoutResolver::class)
            ->payableConfirmedAppointment($user->customer, LaboratoryBrand::OLAB))->toBeNull()
        ->and(app(PrepareLaboratoryCheckoutPaymentLinkAction::class)($appointment))->toBeNull();
});

test('soft deleted appointment blocks payment link', function () {
    $user = phase4User();
    phase4Cart($user);
    $appointment = phase4ConfirmedAppointment($user);
    $appointment->delete();

    expect(app(PrepareLaboratoryCheckoutPaymentLinkAction::class)($appointment->fresh()))->toBeNull();
});

test('appointment from another cart does not unlock payment', function () {
    $user = phase4User();
    $activeCart = phase4Cart($user);

    $otherCart = Cart::query()->create([
        'user_id' => $user->id,
        'type' => MonitoringCartType::Lab->value,
        'status' => MonitoringCartStatus::Active->value,
        'total' => 500,
    ]);

    phase4ConfirmedAppointment(
        $user,
        $otherCart,
        now('America/Monterrey')->addDays(2)->setTime(10, 0),
    );

    expect($activeCart->id)->not->toBe($otherCart->id);

    expect(app(LaboratoryAppointmentCheckoutResolver::class)
        ->payableConfirmedAppointment($user->customer, LaboratoryBrand::OLAB))->toBeNull();
});

test('legacy appointment without cart id remains payable when it is the only candidate', function () {
    $user = phase4User();
    phase4Cart($user);
    $store = phase4Store();

    $appointment = LaboratoryAppointment::query()->create([
        'customer_id' => $user->customer->id,
        'brand' => LaboratoryBrand::OLAB,
        'cart_id' => null,
        'laboratory_store_id' => $store->id,
        'patient_name' => 'Ana',
        'patient_paternal_lastname' => 'Lopez',
        'patient_maternal_lastname' => 'Perez',
        'patient_birth_date' => '1990-01-01',
        'patient_gender' => Gender::FEMALE,
        'patient_phone' => '8111111111',
        'patient_phone_country' => 'MX',
        'appointment_date' => now('America/Monterrey')->addDays(2)->setTime(10, 0),
        'confirmed_at' => now(),
    ]);

    $payable = app(LaboratoryAppointmentCheckoutResolver::class)
        ->payableConfirmedAppointment($user->customer, LaboratoryBrand::OLAB);

    expect($payable?->id)->toBe($appointment->id);
});

test('checkout deep link to payment redirects when appointment cancelled', function () {
    $user = phase4User();
    $cart = phase4Cart($user);
    $contact = Contact::factory()->create(['customer_id' => $user->customer->id]);
    $address = Address::factory()->create(['customer_id' => $user->customer->id]);

    $appointment = phase4ConfirmedAppointment($user, $cart);
    $appointment->delete();

    LaboratoryCheckoutDraft::query()->create([
        'customer_id' => $user->customer->id,
        'laboratory_brand' => LaboratoryBrand::OLAB,
        'contact_id' => $contact->id,
        'address_id' => $address->id,
        'checkout_step' => 'payment',
    ]);

    $this->actingAs($user)
        ->get(route('laboratory.checkout', [
            'laboratory_brand' => LaboratoryBrand::OLAB,
            'step' => 'payment',
            'contact' => $contact->id,
            'address' => $address->id,
        ]))
        ->assertRedirect()
        ->assertSessionHas('checkout_step_notice');
});

test('confirmed appointment with future date allows checkout payment step', function () {
    $user = phase4User();
    phase4Cart($user);
    $contact = Contact::factory()->create(['customer_id' => $user->customer->id]);
    $address = Address::factory()->create(['customer_id' => $user->customer->id]);

    phase4ConfirmedAppointment($user);

    LaboratoryCheckoutDraft::query()->create([
        'customer_id' => $user->customer->id,
        'laboratory_brand' => LaboratoryBrand::OLAB,
        'contact_id' => $contact->id,
        'address_id' => $address->id,
        'checkout_step' => 'payment',
    ]);

    $this->actingAs($user)
        ->get(route('laboratory.checkout', [
            'laboratory_brand' => LaboratoryBrand::OLAB,
            'step' => 'payment',
            'contact' => $contact->id,
            'address' => $address->id,
        ]))
        ->assertOk();
});

test('reprogramming confirmed appointment does not duplicate appointment_confirmed event', function () {
    $user = phase4User();
    $cart = phase4Cart($user);
    $store = phase4Store();

    $appointment = phase4ConfirmedAppointment($user, $cart, withConfirmedAt: false);

    app(UpdateLaboratoryAppointmentAction::class)(
        appointment_date: now('America/Monterrey')->addDays(2)->format('Y-m-d'),
        appointment_time: '10:00',
        patient_name: $appointment->patient_name,
        patient_paternal_lastname: $appointment->patient_paternal_lastname,
        patient_maternal_lastname: $appointment->patient_maternal_lastname,
        patient_birth_date: Carbon::parse($appointment->patient_birth_date),
        patient_gender: Gender::FEMALE,
        patient_phone: '8111111111',
        patient_phone_country: 'MX',
        laboratory_store: $store->id,
        notes: null,
        laboratoryAppointment: $appointment,
    );

    expect(CartEvent::query()->where('event', CartEventType::AppointmentConfirmed->value)->count())->toBe(1);

    app(UpdateLaboratoryAppointmentAction::class)(
        appointment_date: now('America/Monterrey')->addDays(4)->format('Y-m-d'),
        appointment_time: '12:00',
        patient_name: $appointment->patient_name,
        patient_paternal_lastname: $appointment->patient_paternal_lastname,
        patient_maternal_lastname: $appointment->patient_maternal_lastname,
        patient_birth_date: Carbon::parse($appointment->patient_birth_date),
        patient_gender: Gender::FEMALE,
        patient_phone: '8111111111',
        patient_phone_country: 'MX',
        laboratory_store: $store->id,
        notes: 'Reprogramada',
        laboratoryAppointment: $appointment->fresh(),
    );

    expect(CartEvent::query()->where('event', CartEventType::AppointmentConfirmed->value)->count())->toBe(1);
});

test('uses appointment first flow eligibility unchanged for phase four', function () {
    $user = phase4User();
    phase4Cart($user);

    expect(app(LaboratoryCheckoutFlowEligibility::class)
        ->usesAppointmentFirstFlow($user->customer, LaboratoryBrand::OLAB))->toBeTrue();
});
