<?php

require_once __DIR__.'/cart_monitoring_helpers.php';

use App\Enums\CartEventType;
use App\Enums\LaboratoryBrand;
use App\Enums\MonitoringCartStatus;
use App\Enums\MonitoringCartType;
use App\Models\Address;
use App\Models\Cart;
use App\Models\CartEvent;
use App\Models\Contact;
use App\Models\LaboratoryCheckoutDraft;
use App\Services\Carts\CartAbandonmentService;
use App\Services\Carts\CartUserActivityResolver;
use App\Services\Carts\LaboratoryAppointmentConfirmationSignalService;

beforeEach(function () {
    configureCartActiveCampaignTestEnvironment();
    cartMonitoringCheckoutMiddlewareBypass();
    $this->abandonmentService = app(CartAbandonmentService::class);
});

test('full checkout get with open episode records one cart_resumed', function () {
    $user = cartMonitoringUser();
    $cart = cartMonitoringActiveLabCart($user);
    $contact = Contact::factory()->create(['customer_id' => $user->customer->id]);
    $address = Address::factory()->create(['customer_id' => $user->customer->id]);
    cartMonitoringRecordUserActivity($cart, CartEventType::CheckoutStarted, now()->subHours(3));
    cartMonitoringAppointment($user->customer, confirmedAt: now()->subMinutes(45));

    $abandoned = $this->abandonmentService->recordAbandoned($cart->fresh());
    expect($abandoned)->not->toBeNull();

    $this->actingAs($user)->get(route('laboratory.checkout', [
        'laboratory_brand' => LaboratoryBrand::OLAB,
        'step' => 'payment',
        'contact' => $contact->id,
        'address' => $address->id,
    ]))->assertOk();

    expect(CartEvent::query()->where('event', CartEventType::CartResumed->value)->count())->toBe(1);
});

test('second full checkout reload does not duplicate cart_resumed', function () {
    $user = cartMonitoringUser();
    $cart = cartMonitoringActiveLabCart($user);
    $contact = Contact::factory()->create(['customer_id' => $user->customer->id]);
    $address = Address::factory()->create(['customer_id' => $user->customer->id]);
    cartMonitoringRecordUserActivity($cart, CartEventType::CheckoutStarted, now()->subHours(3));
    cartMonitoringAppointment($user->customer, confirmedAt: now()->subMinutes(45));
    $this->abandonmentService->recordAbandoned($cart->fresh());

    $url = route('laboratory.checkout', [
        'laboratory_brand' => LaboratoryBrand::OLAB,
        'step' => 'payment',
        'contact' => $contact->id,
        'address' => $address->id,
    ]);

    $this->actingAs($user)->get($url)->assertOk();
    $this->actingAs($user)->get($url)->assertOk();

    expect(CartEvent::query()->where('event', CartEventType::CartResumed->value)->count())->toBe(1);
});

test('partial inertia reload header does not record checkout visit activity', function () {
    $user = cartMonitoringUser();
    $cart = cartMonitoringActiveLabCart($user);
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

    $before = CartEvent::query()->where('cart_id', $cart->id)->where('event', CartEventType::CheckoutVisited->value)->count();

    $this->actingAs($user)
        ->withHeader('X-Inertia-Partial-Data', 'laboratoryAppointment,pendingLaboratoryAppointment')
        ->get(route('laboratory.checkout', [
            'laboratory_brand' => LaboratoryBrand::OLAB,
            'step' => 'payment',
            'contact' => $contact->id,
            'address' => $address->id,
        ]))
        ->assertOk();

    expect(CartEvent::query()->where('cart_id', $cart->id)->where('event', CartEventType::CheckoutVisited->value)->count())->toBe($before);
});

test('admin appointment confirmation does not emit cart_resumed', function () {
    $user = cartMonitoringUser();
    $cart = cartMonitoringActiveLabCart($user);
    $appointment = cartMonitoringAppointment($user->customer, $cart, confirmedAt: now(), appointmentAt: now()->addDay());

    app(LaboratoryAppointmentConfirmationSignalService::class)->handleNewlyConfirmed($appointment);

    expect(CartEvent::query()->where('event', CartEventType::CartResumed->value)->count())->toBe(0);
});

test('purchase recovery records cart_recovered once', function () {
    $user = cartMonitoringUser();
    $cart = cartMonitoringActiveLabCart($user);
    cartMonitoringRecordUserActivity($cart, CartEventType::CheckoutStarted, now()->subHours(3));
    cartMonitoringAppointment($user->customer, confirmedAt: now()->subMinutes(45));
    $this->abandonmentService->recordAbandoned($cart->fresh());
    $this->abandonmentService->maybeRecordResumed($cart->fresh());

    $first = $this->abandonmentService->recordRecoveredIfEligible($cart->fresh(), 999);
    $second = $this->abandonmentService->recordRecoveredIfEligible($cart->fresh(), 999);

    expect($first)->not->toBeNull()
        ->and($second?->id)->toBe($first->id)
        ->and(CartEvent::query()->where('event', CartEventType::CartRecovered->value)->count())->toBe(1);
});

test('checkout visit without open episode does not emit cart_resumed', function () {
    $user = cartMonitoringUser();
    cartMonitoringActiveLabCart($user);
    $contact = Contact::factory()->create(['customer_id' => $user->customer->id]);
    $address = Address::factory()->create(['customer_id' => $user->customer->id]);
    cartMonitoringAppointment($user->customer, confirmedAt: now(), appointmentAt: now()->addDay());

    $this->actingAs($user)->get(route('laboratory.checkout', [
        'laboratory_brand' => LaboratoryBrand::OLAB,
        'step' => 'payment',
        'contact' => $contact->id,
        'address' => $address->id,
    ]))->assertOk();

    expect(CartEvent::query()->where('event', CartEventType::CartResumed->value)->count())->toBe(0);
});

test('full checkout get records checkout visit user activity', function () {
    $user = cartMonitoringUser();
    $cart = cartMonitoringActiveLabCart($user);
    $contact = Contact::factory()->create(['customer_id' => $user->customer->id]);
    $address = Address::factory()->create(['customer_id' => $user->customer->id]);
    cartMonitoringRecordUserActivity($cart, CartEventType::PatientSelected, now()->subHours(3));
    cartMonitoringAppointment($user->customer, confirmedAt: now()->subMinutes(45));

    $this->actingAs($user)->get(route('laboratory.checkout', [
        'laboratory_brand' => LaboratoryBrand::OLAB,
        'step' => 'payment',
        'contact' => $contact->id,
        'address' => $address->id,
    ]))->assertOk();

    expect(app(CartUserActivityResolver::class)->lastUserActivityAt($cart->fresh())->gt(now()->subMinutes(5)))->toBeTrue()
        ->and(CartEvent::query()->where('cart_id', $cart->id)->where('event', CartEventType::CheckoutStarted->value)->count())->toBe(0)
        ->and(CartEvent::query()->where('cart_id', $cart->id)->where('event', CartEventType::CheckoutVisited->value)->count())->toBeGreaterThanOrEqual(1);
});
