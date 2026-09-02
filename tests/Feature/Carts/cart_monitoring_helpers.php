<?php

use App\Enums\CartEventType;
use App\Enums\Gender;
use App\Enums\LaboratoryBrand;
use App\Enums\MonitoringCartStatus;
use App\Enums\MonitoringCartType;
use App\Models\Cart;
use App\Models\CartEvent;
use App\Models\CartItem;
use App\Models\Customer;
use App\Models\LaboratoryAppointment;
use App\Models\LaboratoryCartItem;
use App\Models\LaboratoryStore;
use App\Models\LaboratoryTest;
use App\Models\User;
use Illuminate\Support\Facades\Queue;

function configureCartActiveCampaignTestEnvironment(): void
{
    config([
        'services.activecampaign.enabled' => true,
        'services.activecampaign.cart_outbox_enabled' => true,
        'services.activecampaign.cart_site_events_enabled' => true,
        'services.activecampaign.cart_tag_remove_enabled' => true,
        'services.activecampaign.cart_appointment_signals_enabled' => true,
        'services.activecampaign.cart_call_signals_enabled' => true,
        'services.activecampaign.tags.cart.abandoned' => 9020,
        'services.activecampaign.tags.cart.added' => 9019,
        'services.activecampaign.tags.cart.appointment_pending' => 9021,
        'services.activecampaign.tag_abandoned_carts_enabled' => true,
        'services.activecampaign.site_events.cart.abandoned' => 'famedic_cart_abandoned',
        'services.activecampaign.site_events.cart.resumed' => 'famedic_cart_resumed',
        'services.activecampaign.site_events.cart.recovered' => 'famedic_cart_recovered',
        'services.activecampaign.site_events.appointment.pending_5m' => 'famedic_appointment_pending_5m',
        'services.activecampaign.site_events.appointment.confirmed' => 'famedic_appointment_confirmed',
        'carts.abandoned_after_minutes' => 30,
        'carts.appointment_pending_after_minutes' => 5,
    ]);

    Queue::fake();
}

function cartMonitoringUser(): User
{
    return User::factory()
        ->withCompleteProfile()
        ->withRegularCustomer()
        ->create(['documentation_accepted_at' => now()])
        ->fresh(['customer']);
}

function cartMonitoringActiveLabCart(User $user, LaboratoryBrand $brand = LaboratoryBrand::OLAB): Cart
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

    $cart = app(\App\Services\Monitoring\SyncMonitoringCartService::class)
        ->activeLaboratoryCart($user->customer->fresh(), $brand);

    if ($cart === null) {
        throw new \RuntimeException('No se pudo resolver el carrito activo de laboratorio en tests.');
    }

    $cart->update([
        'created_at' => now()->subHours(3),
        'updated_at' => now()->subHours(3),
    ]);

    return $cart->fresh(['items']);
}

function cartMonitoringStore(): LaboratoryStore
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

function cartMonitoringRecordUserActivity(Cart $cart, CartEventType $type, ?\Carbon\CarbonInterface $at = null): CartEvent
{
    return CartEvent::query()->create([
        'cart_id' => $cart->id,
        'event' => $type->value,
        'metadata' => ['brand' => LaboratoryBrand::OLAB->value],
        'occurred_at' => $at ?? now()->subHours(2),
        'source' => 'test_user_activity',
    ]);
}

function cartMonitoringAppointment(
    Customer $customer,
    ?Cart $cart = null,
    ?\Carbon\CarbonInterface $confirmedAt = null,
    ?\Carbon\CarbonInterface $appointmentAt = null,
): LaboratoryAppointment {
    return LaboratoryAppointment::query()->create([
        'customer_id' => $customer->id,
        'brand' => LaboratoryBrand::OLAB,
        'cart_id' => $cart?->id,
        'laboratory_store_id' => cartMonitoringStore()->id,
        'patient_name' => 'Ana',
        'patient_paternal_lastname' => 'Lopez',
        'patient_maternal_lastname' => 'Perez',
        'patient_birth_date' => '1990-01-01',
        'patient_gender' => Gender::FEMALE,
        'patient_phone' => '8111111111',
        'patient_phone_country' => 'MX',
        'appointment_date' => $appointmentAt ?? now('America/Monterrey')->addDays(2),
        'confirmed_at' => $confirmedAt,
    ]);
}

function cartMonitoringCheckoutMiddlewareBypass(): void
{
    test()->withoutMiddleware([
        \App\Http\Middleware\RedirectIfEmptyLaboratoryCartItems::class,
        \App\Http\Middleware\RedirectIfUserProfileIsIncomplete::class,
        \App\Http\Middleware\EnsureDocumentationIsAccepted::class,
        \App\Http\Middleware\EnsurePhoneIsVerified::class,
        \Illuminate\Auth\Middleware\EnsureEmailIsVerified::class,
    ]);
}
