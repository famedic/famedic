<?php

require_once __DIR__.'/cart_monitoring_helpers.php';

use App\Enums\CartEventType;
use App\Enums\LaboratoryBrand;
use App\Models\Address;
use App\Models\CartEvent;
use App\Models\Contact;
use App\Models\LaboratoryCheckoutDraft;
use App\Models\LaboratoryPurchase;
use App\Models\Transaction;
use App\Enums\Gender;

beforeEach(function () {
    configureCartActiveCampaignTestEnvironment();
    cartMonitoringCheckoutMiddlewareBypass();
});

function attachThreeStandardPurchases($user): void
{
    for ($i = 0; $i < 3; $i++) {
        $purchase = LaboratoryPurchase::query()->create([
            'customer_id' => $user->customer->id,
            'brand' => LaboratoryBrand::OLAB->value,
            'gda_order_id' => 'gda-pms-'.fake()->unique()->numerify('######'),
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
            'reference_id' => 'ref-pms-'.$purchase->id,
            'gateway_processed_at' => now(),
        ]);
        $purchase->transactions()->attach($tx->id);
    }
}

test('appointment-first before confirmed appointment does not emit payment_method_selected', function () {
    $user = cartMonitoringUser();
    cartMonitoringActiveLabCart($user);
    $contact = Contact::factory()->create(['customer_id' => $user->customer->id]);
    $address = Address::factory()->create(['customer_id' => $user->customer->id]);

    $this->actingAs($user)->post(route('laboratory.checkout.draft.sync', [
        'laboratory_brand' => LaboratoryBrand::OLAB,
    ]), [
        'step' => 'payment',
        'contact_id' => $contact->id,
        'address_id' => $address->id,
        'payment_method' => 'paypal',
    ])->assertRedirect();

    expect(CartEvent::query()->where('event', CartEventType::PaymentMethodSelected->value)->count())->toBe(0);
});

test('appointment-first after confirmed appointment records one payment_method_selected', function () {
    $user = cartMonitoringUser();
    cartMonitoringActiveLabCart($user);
    $contact = Contact::factory()->create(['customer_id' => $user->customer->id]);
    $address = Address::factory()->create(['customer_id' => $user->customer->id]);
    cartMonitoringAppointment($user->customer, confirmedAt: now(), appointmentAt: now()->addDay());

    $this->actingAs($user)->post(route('laboratory.checkout.draft.sync', [
        'laboratory_brand' => LaboratoryBrand::OLAB,
    ]), [
        'step' => 'payment',
        'contact_id' => $contact->id,
        'address_id' => $address->id,
        'payment_method' => 'paypal',
    ])->assertRedirect();

    expect(CartEvent::query()->where('event', CartEventType::PaymentMethodSelected->value)->count())->toBe(1);
});

test('second sync with same payment method does not duplicate payment_method_selected', function () {
    $user = cartMonitoringUser();
    cartMonitoringActiveLabCart($user);
    $contact = Contact::factory()->create(['customer_id' => $user->customer->id]);
    $address = Address::factory()->create(['customer_id' => $user->customer->id]);
    cartMonitoringAppointment($user->customer, confirmedAt: now(), appointmentAt: now()->addDay());

    $payload = [
        'step' => 'payment',
        'contact_id' => $contact->id,
        'address_id' => $address->id,
        'payment_method' => 'paypal',
    ];

    $this->actingAs($user)->post(route('laboratory.checkout.draft.sync', ['laboratory_brand' => LaboratoryBrand::OLAB]), $payload);
    $this->actingAs($user)->post(route('laboratory.checkout.draft.sync', ['laboratory_brand' => LaboratoryBrand::OLAB]), $payload);

    expect(CartEvent::query()->where('event', CartEventType::PaymentMethodSelected->value)->count())->toBe(1);
});

test('changing payment method from paypal to token creates a new payment_method_selected event', function () {
    $user = cartMonitoringUser();
    cartMonitoringActiveLabCart($user);
    $contact = Contact::factory()->create(['customer_id' => $user->customer->id]);
    $address = Address::factory()->create(['customer_id' => $user->customer->id]);
    cartMonitoringAppointment($user->customer, confirmedAt: now(), appointmentAt: now()->addDay());

    $this->actingAs($user)->post(route('laboratory.checkout.draft.sync', ['laboratory_brand' => LaboratoryBrand::OLAB]), [
        'step' => 'payment',
        'contact_id' => $contact->id,
        'address_id' => $address->id,
        'payment_method' => 'paypal',
    ]);

    $this->actingAs($user)->post(route('laboratory.checkout.draft.sync', ['laboratory_brand' => LaboratoryBrand::OLAB]), [
        'step' => 'payment',
        'contact_id' => $contact->id,
        'address_id' => $address->id,
        'payment_method' => '42',
    ]);

    expect(CartEvent::query()->where('event', CartEventType::PaymentMethodSelected->value)->count())->toBe(2);
});

test('standard flow still records payment_method_selected on payment step before appointment', function () {
    $user = cartMonitoringUser();
    cartMonitoringActiveLabCart($user);
    attachThreeStandardPurchases($user);
    $contact = Contact::factory()->create(['customer_id' => $user->customer->id]);
    $address = Address::factory()->create(['customer_id' => $user->customer->id]);

    $this->actingAs($user)->post(route('laboratory.checkout.draft.sync', [
        'laboratory_brand' => LaboratoryBrand::OLAB,
    ]), [
        'step' => 'payment',
        'contact_id' => $contact->id,
        'address_id' => $address->id,
        'payment_method' => 'paypal',
    ])->assertRedirect();

    expect(CartEvent::query()->where('event', CartEventType::PaymentMethodSelected->value)->count())->toBe(1);
});

test('partial checkout polling does not emit payment_method_selected', function () {
    $user = cartMonitoringUser();
    cartMonitoringActiveLabCart($user);
    $contact = Contact::factory()->create(['customer_id' => $user->customer->id]);
    $address = Address::factory()->create(['customer_id' => $user->customer->id]);
    cartMonitoringAppointment($user->customer, confirmedAt: now(), appointmentAt: now()->addDay());

    LaboratoryCheckoutDraft::query()->create([
        'customer_id' => $user->customer->id,
        'laboratory_brand' => LaboratoryBrand::OLAB,
        'contact_id' => $contact->id,
        'address_id' => $address->id,
        'checkout_step' => 'payment',
        'payment_method' => 'paypal',
    ]);

    $this->actingAs($user)
        ->withHeader('X-Inertia-Partial-Data', 'laboratoryAppointment,pendingLaboratoryAppointment')
        ->get(route('laboratory.checkout', [
            'laboratory_brand' => LaboratoryBrand::OLAB,
            'step' => 'payment',
            'contact' => $contact->id,
            'address' => $address->id,
        ]))
        ->assertOk();

    expect(CartEvent::query()->where('event', CartEventType::PaymentMethodSelected->value)->count())->toBe(0);
});

test('checkout get without draft sync does not emit payment_method_selected', function () {
    $user = cartMonitoringUser();
    cartMonitoringActiveLabCart($user);
    $contact = Contact::factory()->create(['customer_id' => $user->customer->id]);
    $address = Address::factory()->create(['customer_id' => $user->customer->id]);
    cartMonitoringAppointment($user->customer, confirmedAt: now(), appointmentAt: now()->addDay());

    LaboratoryCheckoutDraft::query()->create([
        'customer_id' => $user->customer->id,
        'laboratory_brand' => LaboratoryBrand::OLAB,
        'contact_id' => $contact->id,
        'address_id' => $address->id,
        'checkout_step' => 'payment',
    ]);

    $this->actingAs($user)->get(route('laboratory.checkout', [
        'laboratory_brand' => LaboratoryBrand::OLAB,
        'step' => 'payment',
        'contact' => $contact->id,
        'address' => $address->id,
    ]))->assertOk();

    expect(CartEvent::query()->where('event', CartEventType::PaymentMethodSelected->value)->count())->toBe(0);
});
