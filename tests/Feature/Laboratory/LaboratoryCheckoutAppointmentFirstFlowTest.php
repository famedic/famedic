<?php

use App\Actions\Admin\LaboratoryAppointments\BuildLaboratoryAppointmentCheckoutProgressAction;
use App\Actions\Admin\LaboratoryAppointments\EnrichLaboratoryAppointmentIndexRowAction;
use App\Actions\Laboratories\SyncLaboratoryAppointmentFromContactAction;
use App\Enums\Gender;
use App\Enums\LaboratoryBrand;
use App\Models\Address;
use App\Models\Contact;
use App\Models\LaboratoryAppointment;
use App\Models\LaboratoryCartItem;
use App\Models\LaboratoryCheckoutDraft;
use App\Models\LaboratoryPurchase;
use App\Models\LaboratoryStore;
use App\Models\LaboratoryTest;
use App\Models\Transaction;
use App\Models\User;
use App\Services\Laboratory\LaboratoryAppointmentPaymentValidity;
use App\Services\Laboratory\LaboratoryCheckoutStepGuard;
use App\Services\Monitoring\SyncMonitoringCartService;

beforeEach(function () {
    $this->withoutMiddleware([
        \App\Http\Middleware\RedirectIfEmptyLaboratoryCartItems::class,
        \App\Http\Middleware\RedirectIfUserProfileIsIncomplete::class,
        \App\Http\Middleware\EnsureDocumentationIsAccepted::class,
        \App\Http\Middleware\EnsurePhoneIsVerified::class,
        \Illuminate\Auth\Middleware\EnsureEmailIsVerified::class,
    ]);

    $this->stepGuard = app(LaboratoryCheckoutStepGuard::class);
});

function appointmentFirstCheckoutUser(): User
{
    return User::factory()
        ->withCompleteProfile()
        ->withRegularCustomer()
        ->create([
            'documentation_accepted_at' => now(),
        ])
        ->fresh(['customer']);
}

function seedAppointmentFirstCart(User $user, LaboratoryBrand $brand = LaboratoryBrand::OLAB): LaboratoryTest
{
    $test = LaboratoryTest::factory()->create([
        'brand' => $brand->value,
        'requires_appointment' => true,
        'famedic_price_cents' => 80000,
    ]);

    LaboratoryCartItem::factory()->create([
        'customer_id' => $user->customer->id,
        'laboratory_test_id' => $test->id,
    ]);

    return $test;
}

function seedAppointmentFirstContactAndAddress(User $user): array
{
    $contact = Contact::factory()->create([
        'customer_id' => $user->customer->id,
    ]);

    $address = Address::factory()->create([
        'customer_id' => $user->customer->id,
    ]);

    return [$contact, $address];
}

function attachValidPurchase(User $user, LaboratoryBrand $brand = LaboratoryBrand::OLAB): LaboratoryPurchase
{
    $purchase = LaboratoryPurchase::query()->create([
        'customer_id' => $user->customer->id,
        'brand' => $brand->value,
        'gda_order_id' => 'gda-af-'.fake()->unique()->numerify('######'),
        'name' => 'Paciente',
        'paternal_lastname' => 'Historial',
        'maternal_lastname' => 'Prueba',
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
        'total_cents' => 80000,
    ]);

    $transaction = Transaction::query()->create([
        'transaction_amount_cents' => 80000,
        'payment_method' => 'efevoopay',
        'gateway' => 'efevoopay',
        'payment_status' => 'completed',
        'reference_id' => 'ref-af-'.$purchase->id,
        'gateway_processed_at' => now(),
    ]);

    $purchase->transactions()->attach($transaction->id);

    return $purchase;
}

function confirmedLaboratoryAppointment(User $user, LaboratoryBrand $brand = LaboratoryBrand::OLAB): LaboratoryAppointment
{
    $store = LaboratoryStore::query()->create([
        'brand' => $brand->value,
        'name' => 'Sucursal Centro',
        'address' => 'Av. Constitución 100, Monterrey',
        'state' => 'Nuevo León',
        'weekly_hours' => '8:00 - 18:00',
        'saturday_hours' => '8:00 - 14:00',
        'sunday_hours' => 'Cerrado',
        'google_maps_url' => 'https://maps.example.com/store',
    ]);

    return LaboratoryAppointment::factory()->create([
        'customer_id' => $user->customer->id,
        'brand' => $brand->value,
        'laboratory_store_id' => $store->id,
        'patient_name' => 'Ana',
        'patient_paternal_lastname' => 'López',
        'patient_maternal_lastname' => 'García',
        'patient_phone' => '8112345678',
        'patient_phone_country' => 'MX',
        'patient_birth_date' => '1990-05-01',
        'confirmed_at' => now(),
        'appointment_date' => now()->addDays(2),
        'laboratory_purchase_id' => null,
    ]);
}

function activeAppointmentFirstCart(User $user, LaboratoryBrand $brand = LaboratoryBrand::OLAB)
{
    app(SyncMonitoringCartService::class)->syncLaboratory($user->customer);

    return app(SyncMonitoringCartService::class)
        ->activeLaboratoryCart($user->customer->fresh(), $brand);
}

function activeAppointmentCount(User $user, LaboratoryBrand $brand = LaboratoryBrand::OLAB): int
{
    return LaboratoryAppointment::query()
        ->where('customer_id', $user->customer->id)
        ->where('brand', $brand->value)
        ->whereNull('laboratory_purchase_id')
        ->count();
}

test('appointment first draft sync advances address to appointment', function () {
    $user = appointmentFirstCheckoutUser();
    seedAppointmentFirstCart($user);
    [$contact, $address] = seedAppointmentFirstContactAndAddress($user);

    $this->actingAs($user)
        ->post(route('laboratory.checkout.draft.sync', ['laboratory_brand' => LaboratoryBrand::OLAB]), [
            'step' => 'address',
            'contact_id' => $contact->id,
            'address_id' => $address->id,
        ])
        ->assertRedirect(route('laboratory.checkout', [
            'laboratory_brand' => LaboratoryBrand::OLAB,
            'step' => 'appointment',
            'contact' => $contact->id,
            'address' => $address->id,
        ]));

    expect(LaboratoryCheckoutDraft::query()
        ->where('customer_id', $user->customer->id)
        ->value('checkout_step'))
        ->toBe('appointment');
});

test('appointment first checkout rejects payment step before confirmed appointment', function () {
    $user = appointmentFirstCheckoutUser();
    seedAppointmentFirstCart($user);
    [$contact, $address] = seedAppointmentFirstContactAndAddress($user);

    LaboratoryCheckoutDraft::query()->create([
        'customer_id' => $user->customer->id,
        'laboratory_brand' => LaboratoryBrand::OLAB,
        'contact_id' => $contact->id,
        'address_id' => $address->id,
        'checkout_step' => 'payment',
        'payment_method' => '1',
    ]);

    $this->actingAs($user)
        ->get(route('laboratory.checkout', [
            'laboratory_brand' => LaboratoryBrand::OLAB,
            'step' => 'payment',
        ]))
        ->assertRedirect(route('laboratory.checkout', [
            'laboratory_brand' => LaboratoryBrand::OLAB,
            'step' => 'appointment',
            'contact' => $contact->id,
            'address' => $address->id,
        ]));
});

test('appointment first checkout rejects confirmation deep link before confirmed appointment', function () {
    $user = appointmentFirstCheckoutUser();
    seedAppointmentFirstCart($user);
    [$contact, $address] = seedAppointmentFirstContactAndAddress($user);

    $this->actingAs($user)
        ->get(route('laboratory.checkout', [
            'laboratory_brand' => LaboratoryBrand::OLAB,
            'step' => 'confirmation',
            'contact' => $contact->id,
            'address' => $address->id,
        ]))
        ->assertRedirect(route('laboratory.checkout', [
            'laboratory_brand' => LaboratoryBrand::OLAB,
            'step' => 'appointment',
            'contact' => $contact->id,
            'address' => $address->id,
        ]));
});

test('appointment first checkout allows payment after confirmed appointment', function () {
    $user = appointmentFirstCheckoutUser();
    seedAppointmentFirstCart($user);
    [$contact, $address] = seedAppointmentFirstContactAndAddress($user);
    confirmedLaboratoryAppointment($user);

    $this->actingAs($user)
        ->get(route('laboratory.checkout', [
            'laboratory_brand' => LaboratoryBrand::OLAB,
            'step' => 'payment',
            'contact' => $contact->id,
            'address' => $address->id,
        ]))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('usesAppointmentFirstFlow', true)
            ->where('requiresAppointment', true)
        );
});

test('appointment first checkout advances appointment step to payment after concierge confirms appointment', function () {
    $user = appointmentFirstCheckoutUser();
    seedAppointmentFirstCart($user);
    [$contact, $address] = seedAppointmentFirstContactAndAddress($user);
    confirmedLaboratoryAppointment($user);

    LaboratoryCheckoutDraft::query()->create([
        'customer_id' => $user->customer->id,
        'laboratory_brand' => LaboratoryBrand::OLAB,
        'contact_id' => $contact->id,
        'address_id' => $address->id,
        'checkout_step' => 'appointment',
    ]);

    $this->actingAs($user)
        ->get(route('laboratory.checkout', [
            'laboratory_brand' => LaboratoryBrand::OLAB,
            'step' => 'appointment',
            'contact' => $contact->id,
            'address' => $address->id,
        ]))
        ->assertRedirect(route('laboratory.checkout', [
            'laboratory_brand' => LaboratoryBrand::OLAB,
            'step' => 'payment',
            'contact' => $contact->id,
            'address' => $address->id,
        ]));

    expect(LaboratoryCheckoutDraft::query()
        ->where('customer_id', $user->customer->id)
        ->value('checkout_step'))
        ->toBe('payment');
});

test('legacy draft at payment without appointment is normalized to appointment', function () {
    $user = appointmentFirstCheckoutUser();
    seedAppointmentFirstCart($user);
    [$contact, $address] = seedAppointmentFirstContactAndAddress($user);

    LaboratoryCheckoutDraft::query()->create([
        'customer_id' => $user->customer->id,
        'laboratory_brand' => LaboratoryBrand::OLAB,
        'contact_id' => $contact->id,
        'address_id' => $address->id,
        'checkout_step' => 'payment',
        'payment_method' => 'paypal',
    ]);

    $this->actingAs($user)
        ->get(route('laboratory.checkout', ['laboratory_brand' => LaboratoryBrand::OLAB]))
        ->assertRedirect(route('laboratory.checkout', [
            'laboratory_brand' => LaboratoryBrand::OLAB,
            'step' => 'appointment',
            'contact' => $contact->id,
            'address' => $address->id,
        ]));

    expect(LaboratoryCheckoutDraft::query()
        ->where('customer_id', $user->customer->id)
        ->value('checkout_step'))
        ->toBe('appointment');
});

test('legacy draft with confirmed appointment opens payment step', function () {
    $user = appointmentFirstCheckoutUser();
    seedAppointmentFirstCart($user);
    [$contact, $address] = seedAppointmentFirstContactAndAddress($user);
    confirmedLaboratoryAppointment($user);

    LaboratoryCheckoutDraft::query()->create([
        'customer_id' => $user->customer->id,
        'laboratory_brand' => LaboratoryBrand::OLAB,
        'contact_id' => $contact->id,
        'address_id' => $address->id,
        'checkout_step' => 'confirmation',
        'payment_method' => 'paypal',
    ]);

    $this->actingAs($user)
        ->get(route('laboratory.checkout', ['laboratory_brand' => LaboratoryBrand::OLAB]))
        ->assertRedirect(route('laboratory.checkout', [
            'laboratory_brand' => LaboratoryBrand::OLAB,
            'step' => 'payment',
            'contact' => $contact->id,
            'address' => $address->id,
        ]));
});

test('appointment first draft sync rejects payment step before confirmed appointment', function () {
    $user = appointmentFirstCheckoutUser();
    seedAppointmentFirstCart($user);
    [$contact, $address] = seedAppointmentFirstContactAndAddress($user);

    $this->actingAs($user)
        ->post(route('laboratory.checkout.draft.sync', ['laboratory_brand' => LaboratoryBrand::OLAB]), [
            'step' => 'payment',
            'contact_id' => $contact->id,
            'address_id' => $address->id,
            'payment_method' => 'paypal',
        ])
        ->assertRedirect(route('laboratory.checkout', [
            'laboratory_brand' => LaboratoryBrand::OLAB,
            'step' => 'appointment',
            'contact' => $contact->id,
            'address' => $address->id,
        ]));
});

test('appointment first purchase store is blocked without confirmed appointment', function () {
    $user = appointmentFirstCheckoutUser();
    seedAppointmentFirstCart($user);

    expect($this->stepGuard->canInitiatePayment($user->customer, LaboratoryBrand::OLAB))->toBeFalse();
});

test('appointment first paypal create order is blocked without confirmed appointment', function () {
    $user = appointmentFirstCheckoutUser();
    seedAppointmentFirstCart($user);
    [$contact, $address] = seedAppointmentFirstContactAndAddress($user);

    $this->actingAs($user)
        ->postJson(route('paypal.create-order'), [
            'patient_id' => $contact->id,
            'address_id' => $address->id,
            'laboratory_brand' => LaboratoryBrand::OLAB->value,
            'total' => 80000,
        ])
        ->assertStatus(422)
        ->assertJsonValidationErrors(['patient_id']);
});

test('recurrent customer with three purchases keeps standard checkout flow', function () {
    $user = appointmentFirstCheckoutUser();
    seedAppointmentFirstCart($user);
    attachValidPurchase($user);
    attachValidPurchase($user);
    attachValidPurchase($user);

    $this->actingAs($user)
        ->get(route('laboratory.checkout', ['laboratory_brand' => LaboratoryBrand::OLAB]))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('usesAppointmentFirstFlow', false)
            ->where('requiresAppointment', true)
        );
});

test('appointment first appointment sync does not overwrite saved payment method on draft', function () {
    $user = appointmentFirstCheckoutUser();
    seedAppointmentFirstCart($user);
    [$contact, $address] = seedAppointmentFirstContactAndAddress($user);

    LaboratoryCheckoutDraft::query()->create([
        'customer_id' => $user->customer->id,
        'laboratory_brand' => LaboratoryBrand::OLAB,
        'contact_id' => $contact->id,
        'address_id' => $address->id,
        'checkout_step' => 'appointment',
        'payment_method' => 'paypal',
    ]);

    $this->actingAs($user)
        ->post(route('laboratory.checkout.appointment.sync', ['laboratory_brand' => LaboratoryBrand::OLAB]), [
            'contact_id' => $contact->id,
            'address' => $address->id,
            'payment_method' => 'odessa',
        ])
        ->assertRedirect();

    $draft = LaboratoryCheckoutDraft::query()
        ->where('customer_id', $user->customer->id)
        ->first();

    expect($draft->checkout_step)->toBe('appointment')
        ->and($draft->payment_method)->toBe('paypal');
});

test('appointment sync reuses pending appointment instead of creating duplicates', function () {
    $user = appointmentFirstCheckoutUser();
    seedAppointmentFirstCart($user);
    [$contact] = seedAppointmentFirstContactAndAddress($user);

    $action = app(SyncLaboratoryAppointmentFromContactAction::class);
    $action($user->customer, LaboratoryBrand::OLAB, $contact);
    $action($user->customer, LaboratoryBrand::OLAB, $contact);

    expect(LaboratoryAppointment::query()
        ->where('customer_id', $user->customer->id)
        ->whereNull('confirmed_at')
        ->count())
        ->toBe(1);
});

test('appointment sync creates exactly one appointment when cart has none', function () {
    $user = appointmentFirstCheckoutUser();
    seedAppointmentFirstCart($user);
    [$contact] = seedAppointmentFirstContactAndAddress($user);

    $appointment = app(SyncLaboratoryAppointmentFromContactAction::class)(
        $user->customer,
        LaboratoryBrand::OLAB,
        $contact,
    );

    expect(activeAppointmentCount($user))->toBe(1)
        ->and($appointment->fresh()->cart_id)->not->toBeNull();
});

test('appointment sync reuses pending active cart appointment', function () {
    $user = appointmentFirstCheckoutUser();
    seedAppointmentFirstCart($user);
    [$contact] = seedAppointmentFirstContactAndAddress($user);
    $cart = activeAppointmentFirstCart($user);

    $pending = LaboratoryAppointment::factory()->create([
        'customer_id' => $user->customer->id,
        'brand' => LaboratoryBrand::OLAB->value,
        'cart_id' => $cart->id,
        'confirmed_at' => null,
        'laboratory_purchase_id' => null,
    ]);

    $appointment = app(SyncLaboratoryAppointmentFromContactAction::class)(
        $user->customer,
        LaboratoryBrand::OLAB,
        $contact,
    );

    expect($appointment->id)->toBe($pending->id)
        ->and(activeAppointmentCount($user))->toBe(1);
});

test('appointment sync reuses valid confirmed active cart appointment', function () {
    $user = appointmentFirstCheckoutUser();
    seedAppointmentFirstCart($user);
    [$contact] = seedAppointmentFirstContactAndAddress($user);
    $cart = activeAppointmentFirstCart($user);
    $confirmed = confirmedLaboratoryAppointment($user)->forceFill([
        'cart_id' => $cart->id,
        'appointment_date' => now('America/Monterrey')->addDay(),
    ]);
    $confirmed->save();

    $appointment = app(SyncLaboratoryAppointmentFromContactAction::class)(
        $user->customer,
        LaboratoryBrand::OLAB,
        $contact,
    );

    expect($appointment->id)->toBe($confirmed->id)
        ->and(activeAppointmentCount($user))->toBe(1)
        ->and(app(LaboratoryAppointmentPaymentValidity::class)->isValidForPayment($appointment, $cart->id))->toBeTrue();
});

test('checkout does not create a new appointment when confirmed active appointment is expired', function () {
    $user = appointmentFirstCheckoutUser();
    seedAppointmentFirstCart($user);
    [$contact, $address] = seedAppointmentFirstContactAndAddress($user);
    $cart = activeAppointmentFirstCart($user);
    $confirmed = confirmedLaboratoryAppointment($user)->forceFill([
        'cart_id' => $cart->id,
        'confirmed_at' => now('America/Monterrey')->subHours(2),
        'appointment_date' => now('America/Monterrey')->subHour(),
    ]);
    $confirmed->save();

    $this->actingAs($user)
        ->get(route('laboratory.checkout', [
            'laboratory_brand' => LaboratoryBrand::OLAB,
            'step' => 'appointment',
            'contact' => $contact->id,
            'address' => $address->id,
        ]))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('laboratoryAppointment.id', $confirmed->id)
            ->where('laboratoryAppointment.is_payable', false)
            ->where('pendingLaboratoryAppointment', null)
        );

    expect(activeAppointmentCount($user))->toBe(1)
        ->and($confirmed->fresh()->confirmed_at)->not->toBeNull()
        ->and($this->stepGuard->canInitiatePayment($user->customer, LaboratoryBrand::OLAB))->toBeFalse();
});

test('repeated checkout get creates no more than one active appointment', function () {
    $user = appointmentFirstCheckoutUser();
    seedAppointmentFirstCart($user);
    [$contact, $address] = seedAppointmentFirstContactAndAddress($user);

    foreach (range(1, 3) as $_) {
        $this->actingAs($user)
            ->get(route('laboratory.checkout', [
                'laboratory_brand' => LaboratoryBrand::OLAB,
                'step' => 'appointment',
                'contact' => $contact->id,
                'address' => $address->id,
            ]))
            ->assertOk();
    }

    expect(activeAppointmentCount($user))->toBe(1);
});

test('multiple consecutive appointment sync calls do not increase active appointment count', function () {
    $user = appointmentFirstCheckoutUser();
    seedAppointmentFirstCart($user);
    [$contact] = seedAppointmentFirstContactAndAddress($user);
    $action = app(SyncLaboratoryAppointmentFromContactAction::class);

    foreach (range(1, 4) as $_) {
        $action($user->customer, LaboratoryBrand::OLAB, $contact);
    }

    expect(activeAppointmentCount($user))->toBe(1);
});

test('soft deleted appointment permits exactly one new active appointment', function () {
    $user = appointmentFirstCheckoutUser();
    seedAppointmentFirstCart($user);
    [$contact] = seedAppointmentFirstContactAndAddress($user);
    $cart = activeAppointmentFirstCart($user);

    LaboratoryAppointment::factory()->create([
        'customer_id' => $user->customer->id,
        'brand' => LaboratoryBrand::OLAB->value,
        'cart_id' => $cart->id,
        'confirmed_at' => null,
        'laboratory_purchase_id' => null,
    ])->delete();

    $action = app(SyncLaboratoryAppointmentFromContactAction::class);
    $first = $action($user->customer, LaboratoryBrand::OLAB, $contact);
    $second = $action($user->customer, LaboratoryBrand::OLAB, $contact);

    expect($second->id)->toBe($first->id)
        ->and(activeAppointmentCount($user))->toBe(1)
        ->and(LaboratoryAppointment::withTrashed()
            ->where('customer_id', $user->customer->id)
            ->where('brand', LaboratoryBrand::OLAB->value)
            ->whereNull('laboratory_purchase_id')
            ->count())->toBe(2);
});

test('expired confirmed appointment progress has appointment but blocks payment', function () {
    $user = appointmentFirstCheckoutUser();
    seedAppointmentFirstCart($user);
    $cart = activeAppointmentFirstCart($user);
    $appointment = confirmedLaboratoryAppointment($user)->forceFill([
        'cart_id' => $cart->id,
        'confirmed_at' => now('America/Monterrey')->subHours(2),
        'appointment_date' => now('America/Monterrey')->subHour(),
    ]);
    $appointment->save();

    $progress = app(BuildLaboratoryAppointmentCheckoutProgressAction::class)($appointment->fresh());
    $appointmentStep = collect($progress['steps'])->firstWhere('id', 'appointment');

    expect($appointmentStep['status'])->toBe('completed')
        ->and($progress['payment_blocked_reason'])->toBe('Pago bloqueado: cita vencida');
});

test('admin row still shows expired appointment payment blocked', function () {
    $user = appointmentFirstCheckoutUser();
    seedAppointmentFirstCart($user);
    $cart = activeAppointmentFirstCart($user);
    $appointment = confirmedLaboratoryAppointment($user)->forceFill([
        'cart_id' => $cart->id,
        'confirmed_at' => now('America/Monterrey')->subHours(2),
        'appointment_date' => now('America/Monterrey')->subHour(),
    ]);
    $appointment->save();

    $row = app(EnrichLaboratoryAppointmentIndexRowAction::class)($appointment->fresh(['cart']));

    expect($row['admin_payment_status_label'])->toBe('Pago bloqueado')
        ->and($row['admin_payment_blocked'])->toBeTrue()
        ->and($row['admin_payment_blocked_reason'])->toBe('Pago bloqueado: cita vencida');
});

test('step guard next draft step uses appointment after address in appointment first flow', function () {
    $user = appointmentFirstCheckoutUser();
    seedAppointmentFirstCart($user);

    expect($this->stepGuard->nextDraftStepAfterSync($user->customer, LaboratoryBrand::OLAB, 'address'))
        ->toBe('appointment');
});

test('step guard next draft step uses payment after address in standard flow with appointment', function () {
    $user = appointmentFirstCheckoutUser();
    seedAppointmentFirstCart($user);
    attachValidPurchase($user);
    attachValidPurchase($user);
    attachValidPurchase($user);

    expect($this->stepGuard->nextDraftStepAfterSync($user->customer, LaboratoryBrand::OLAB, 'address'))
        ->toBe('payment');
});
