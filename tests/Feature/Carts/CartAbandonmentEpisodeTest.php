<?php

require_once __DIR__.'/cart_monitoring_helpers.php';

use App\Enums\CartEventType;
use App\Models\CartEvent;
use App\Services\Carts\CartAbandonmentService;
use App\Services\Carts\CartUserActivityResolver;
use App\Services\Monitoring\SyncMonitoringCartService;

beforeEach(function () {
    configureCartActiveCampaignTestEnvironment();
    $this->abandonmentService = app(CartAbandonmentService::class);
    $this->activityResolver = app(CartUserActivityResolver::class);
});

test('internal cart updated_at bump without user event does not reset abandonment reference', function () {
    $user = cartMonitoringUser();
    $cart = cartMonitoringActiveLabCart($user);
    cartMonitoringRecordUserActivity($cart, CartEventType::PatientSelected, now()->subHours(2));

    cartMonitoringAppointment($user->customer, confirmedAt: now()->subMinutes(45));

    $cart->update(['updated_at' => now()]);

    CartEvent::query()->create([
        'cart_id' => $cart->id,
        'event' => CartEventType::AppointmentConfirmed->value,
        'metadata' => ['appointment_id' => 1],
        'occurred_at' => now(),
        'source' => 'laboratory_appointment_confirmation',
    ]);

    $reference = $this->abandonmentService->abandonmentReferenceAt($cart->fresh());

    expect($reference->lte(now()->subMinutes(44)))->toBeTrue();
    expect($this->abandonmentService->recordAbandoned($cart->fresh()))->not->toBeNull();
});

test('recordCheckoutVisit updates abandonment reference without creating checkout_started', function () {
    $user = cartMonitoringUser();
    $cart = cartMonitoringActiveLabCart($user);
    cartMonitoringRecordUserActivity($cart, CartEventType::PatientSelected, now()->subHours(2));

    cartMonitoringAppointment($user->customer, confirmedAt: now()->subMinutes(45));

    $this->activityResolver->recordCheckoutVisit($cart, 'olab');

    expect(CartEvent::query()->where('cart_id', $cart->id)->where('event', CartEventType::CheckoutStarted->value)->count())->toBe(0)
        ->and(CartEvent::query()->where('cart_id', $cart->id)->where('event', CartEventType::CheckoutVisited->value)->count())->toBe(1)
        ->and($this->abandonmentService->abandonmentReferenceAt($cart->fresh())->gt(now()->subMinutes(5)))->toBeTrue()
        ->and($this->abandonmentService->recordAbandoned($cart->fresh()))->toBeNull();
});

test('recordAbandoned does not write user activity events', function () {
    $user = cartMonitoringUser();
    $cart = cartMonitoringActiveLabCart($user);
    cartMonitoringRecordUserActivity($cart, CartEventType::CheckoutStarted, now()->subHours(2));

    $before = CartEvent::query()->where('cart_id', $cart->id)->count();

    $this->abandonmentService->recordAbandoned($cart->fresh());

    expect(CartEvent::query()->where('cart_id', $cart->id)->count())->toBe($before + 1)
        ->and(CartEvent::query()->where('cart_id', $cart->id)->where('event', CartEventType::CartAbandoned->value)->exists())->toBeTrue();
});

test('confirmation starts a fresh abandonment window from confirmed_at', function () {
    $user = cartMonitoringUser();
    $cart = cartMonitoringActiveLabCart($user);
    cartMonitoringRecordUserActivity($cart, CartEventType::PatientSelected, now()->subHours(3));

    $appointment = cartMonitoringAppointment($user->customer, confirmedAt: now()->subMinutes(15));

    expect($this->abandonmentService->recordAbandoned($cart->fresh()))->toBeNull();

    $appointment->update(['confirmed_at' => now()->subMinutes(45)]);

    expect($this->abandonmentService->recordAbandoned($cart->fresh()))->not->toBeNull();
});

test('monitoring snapshot sync updating updated_at does not count as user activity', function () {
    $user = cartMonitoringUser();
    $cart = cartMonitoringActiveLabCart($user);
    cartMonitoringRecordUserActivity($cart, CartEventType::AppointmentRequested, now()->subHours(2));

    cartMonitoringAppointment($user->customer, confirmedAt: now()->subMinutes(45));

    $cart->update(['updated_at' => now(), 'total' => 600]);

    expect(app(CartUserActivityResolver::class)->lastUserActivityAt($cart->fresh())->lte(now()->subMinutes(119)))->toBeTrue();
});

test('cart_abandoned episode is idempotent per episode number', function () {
    $user = cartMonitoringUser();
    $cart = cartMonitoringActiveLabCart($user);
    cartMonitoringRecordUserActivity($cart, CartEventType::CheckoutStarted, now()->subHours(2));
    cartMonitoringAppointment($user->customer, confirmedAt: now()->subMinutes(45));

    $first = $this->abandonmentService->recordAbandoned($cart->fresh());
    $second = $this->abandonmentService->recordAbandoned($cart->fresh());

    expect($first)->not->toBeNull()
        ->and($second)->toBeNull()
        ->and(CartEvent::query()->where('event', CartEventType::CartAbandoned->value)->count())->toBe(1);
});
