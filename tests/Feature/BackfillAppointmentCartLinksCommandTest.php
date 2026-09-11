<?php

use App\Enums\CartEventType;
use App\Enums\LaboratoryBrand;
use App\Enums\MonitoringCartStatus;
use App\Enums\MonitoringCartType;
use App\Models\ActiveCampaignDispatch;
use App\Models\Cart;
use App\Models\CartEvent;
use App\Models\CartItem;
use App\Models\Customer;
use App\Models\LaboratoryAppointment;
use App\Models\LaboratoryTest;
use App\Models\User;
use App\Services\Carts\AppointmentCartLinkBackfillMatcher;
use Illuminate\Support\Facades\Queue;

function backfillUser(array $attributes = []): User
{
    return User::factory()->withRegularCustomer()->create($attributes);
}

function backfillLabCart(User $user, LaboratoryBrand $brand, array $attributes = []): Cart
{
    $test = LaboratoryTest::factory()->create([
        'brand' => $brand->value,
        'requires_appointment' => true,
    ]);

    $cart = Cart::query()->create(array_merge([
        'user_id' => $user->id,
        'type' => MonitoringCartType::Lab->value,
        'status' => MonitoringCartStatus::Active->value,
        'total' => 1000.00,
        'created_at' => now()->subHour(),
        'updated_at' => now()->subMinutes(10),
    ], $attributes));

    CartItem::query()->create([
        'cart_id' => $cart->id,
        'product_id' => (string) $test->id,
        'name' => 'Estudio backfill',
        'price' => 1000.00,
        'quantity' => 1,
    ]);

    return $cart;
}

function backfillLegacyAppointment(Customer $customer, LaboratoryBrand $brand, array $attributes = []): LaboratoryAppointment
{
    return LaboratoryAppointment::factory()->create(array_merge([
        'customer_id' => $customer->id,
        'brand' => $brand->value,
        'cart_id' => null,
        'created_at' => now()->subMinutes(8),
        'updated_at' => now()->subMinutes(8),
    ], $attributes));
}

function backfillCheckoutEvidence(Cart $cart, ?\Carbon\CarbonInterface $occurredAt = null): void
{
    $at = $occurredAt ?? now()->subMinutes(12);

    CartEvent::query()->create([
        'cart_id' => $cart->id,
        'event' => CartEventType::PatientSelected->value,
        'metadata' => ['contact_id' => 1],
        'occurred_at' => $at,
    ]);
    CartEvent::query()->create([
        'cart_id' => $cart->id,
        'event' => CartEventType::AddressSelected->value,
        'metadata' => ['address_id' => 1],
        'occurred_at' => $at->copy()->addMinute(),
    ]);
}

it('proposes a unique high-confidence match in dry-run without modifying the database', function () {
    $user = backfillUser();
    $cart = backfillLabCart($user, LaboratoryBrand::OLAB, [
        'updated_at' => now()->subMinutes(9),
    ]);
    backfillCheckoutEvidence($cart);

    $appointment = backfillLegacyAppointment($user->customer, LaboratoryBrand::OLAB);

    $this->artisan('carts:backfill-appointment-cart-links', ['--appointment' => $appointment->id])
        ->expectsOutputToContain('DRY RUN')
        ->expectsOutputToContain('MATCHED')
        ->assertExitCode(0);

    expect($appointment->fresh()->cart_id)->toBeNull()
        ->and(CartEvent::query()->where('cart_id', $cart->id)->where('event', CartEventType::AppointmentRequested->value)->exists())->toBeFalse();
});

it('applies cart_id and creates appointment_requested on apply', function () {
    Queue::fake();
    $user = backfillUser();
    $cart = backfillLabCart($user, LaboratoryBrand::OLAB, [
        'updated_at' => now()->subMinutes(9),
    ]);
    backfillCheckoutEvidence($cart);
    $appointment = backfillLegacyAppointment($user->customer, LaboratoryBrand::OLAB);

    $this->artisan('carts:backfill-appointment-cart-links', [
        '--appointment' => $appointment->id,
        '--apply' => true,
    ])->expectsOutputToContain('Applied: 1')
        ->assertExitCode(0);

    $appointment->refresh();
    $event = CartEvent::query()
        ->where('cart_id', $cart->id)
        ->where('event', CartEventType::AppointmentRequested->value)
        ->first();

    expect($appointment->cart_id)->toBe($cart->id)
        ->and($event)->not->toBeNull()
        ->and($event->idempotency_key)->toBe("appointment:{$appointment->id}:requested")
        ->and($event->metadata['backfilled'])->toBeTrue()
        ->and($event->metadata['source'])->toBe('legacy_backfill')
        ->and($event->metadata['appointment_id'])->toBe($appointment->id)
        ->and($event->metadata)->not->toHaveKey('client');

    expect(ActiveCampaignDispatch::query()->count())->toBe(0);
});

it('is idempotent on second apply run', function () {
    $user = backfillUser();
    $cart = backfillLabCart($user, LaboratoryBrand::OLAB, ['updated_at' => now()->subMinutes(9)]);
    backfillCheckoutEvidence($cart);
    $appointment = backfillLegacyAppointment($user->customer, LaboratoryBrand::OLAB);

    $this->artisan('carts:backfill-appointment-cart-links', ['--appointment' => $appointment->id, '--apply' => true])
        ->assertExitCode(0);

    $eventsAfterFirst = CartEvent::query()
        ->where('cart_id', $cart->id)
        ->where('event', CartEventType::AppointmentRequested->value)
        ->count();

    $this->artisan('carts:backfill-appointment-cart-links', ['--appointment' => $appointment->id, '--apply' => true])
        ->expectsOutputToContain('Already linked: 1')
        ->assertExitCode(0);

    expect(CartEvent::query()
        ->where('cart_id', $cart->id)
        ->where('event', CartEventType::AppointmentRequested->value)
        ->count())->toBe($eventsAfterFirst);
});

it('does not modify appointments that already have cart_id', function () {
    $user = backfillUser();
    $cart = backfillLabCart($user, LaboratoryBrand::OLAB);
    $appointment = LaboratoryAppointment::factory()->create([
        'customer_id' => $user->customer->id,
        'brand' => LaboratoryBrand::OLAB->value,
        'cart_id' => $cart->id,
    ]);

    $this->artisan('carts:backfill-appointment-cart-links', ['--appointment' => $appointment->id, '--apply' => true])
        ->assertExitCode(0);

    expect($appointment->fresh()->cart_id)->toBe($cart->id);
});

it('classifies two similar candidate carts as ambiguous', function () {
    $user = backfillUser();
    $at = now()->subMinutes(8);

    $cartA = backfillLabCart($user, LaboratoryBrand::OLAB, [
        'created_at' => now()->subHour(),
        'updated_at' => $at->copy()->subMinute(),
    ]);
    $cartB = backfillLabCart($user, LaboratoryBrand::OLAB, [
        'created_at' => now()->subHour(),
        'updated_at' => $at,
    ]);
    backfillCheckoutEvidence($cartA, $at->copy()->subMinutes(3));
    backfillCheckoutEvidence($cartB, $at->copy()->subMinutes(2));

    $appointment = backfillLegacyAppointment($user->customer, LaboratoryBrand::OLAB, [
        'created_at' => $at,
        'updated_at' => $at,
    ]);

    $assessment = app(AppointmentCartLinkBackfillMatcher::class)->assess($appointment);

    expect($assessment['action'])->toBe(AppointmentCartLinkBackfillMatcher::STATUS_AMBIGUOUS)
        ->and($appointment->fresh()->cart_id)->toBeNull();
});

it('does not match when brand differs from cart items', function () {
    $user = backfillUser();
    $cart = backfillLabCart($user, LaboratoryBrand::OLAB, ['updated_at' => now()->subMinutes(9)]);
    backfillCheckoutEvidence($cart);

    $appointment = backfillLegacyAppointment($user->customer, LaboratoryBrand::SWISSLAB);

    $assessment = app(AppointmentCartLinkBackfillMatcher::class)->assess($appointment);

    expect($assessment['action'])->toBe(AppointmentCartLinkBackfillMatcher::STATUS_NO_MATCH);
});

it('does not match appointments from another customer', function () {
    $owner = backfillUser();
    $other = backfillUser();
    $cart = backfillLabCart($owner, LaboratoryBrand::OLAB, ['updated_at' => now()->subMinutes(9)]);
    backfillCheckoutEvidence($cart);

    $appointment = backfillLegacyAppointment($other->customer, LaboratoryBrand::OLAB);

    $assessment = app(AppointmentCartLinkBackfillMatcher::class)->assess($appointment);

    expect($assessment['action'])->toBe(AppointmentCartLinkBackfillMatcher::STATUS_NO_MATCH);
});

it('does not enqueue activecampaign dispatches during backfill', function () {
    Queue::fake();
    $user = backfillUser();
    $cart = backfillLabCart($user, LaboratoryBrand::OLAB, ['updated_at' => now()->subMinutes(9)]);
    backfillCheckoutEvidence($cart);
    $appointment = backfillLegacyAppointment($user->customer, LaboratoryBrand::OLAB);

    $this->artisan('carts:backfill-appointment-cart-links', ['--appointment' => $appointment->id, '--apply' => true])
        ->assertExitCode(0);

    expect(ActiveCampaignDispatch::query()->count())->toBe(0);
    Queue::assertNothingPushed();
});

it('limits analysis with --appointment filter', function () {
    $user = backfillUser();
    $cart = backfillLabCart($user, LaboratoryBrand::OLAB, ['updated_at' => now()->subMinutes(9)]);
    backfillCheckoutEvidence($cart);

    $target = backfillLegacyAppointment($user->customer, LaboratoryBrand::OLAB);
    $other = backfillLegacyAppointment($user->customer, LaboratoryBrand::OLAB);

    $this->artisan('carts:backfill-appointment-cart-links', ['--appointment' => $target->id])
        ->expectsOutputToContain((string) $target->id)
        ->assertExitCode(0);

    expect($target->id)->not->toBe($other->id);
});

it('blocks apply in production without force-production flag', function () {
    $restore = fn () => app()->detectEnvironment(fn () => 'testing');

    app()->detectEnvironment(fn () => 'production');

    try {
        $this->artisan('carts:backfill-appointment-cart-links', ['--apply' => true])
            ->expectsOutputToContain('Apply bloqueado en producción')
            ->assertExitCode(1);
    } finally {
        $restore();
    }
});

it('allows apply in production when force-production is provided', function () {
    $restore = fn () => app()->detectEnvironment(fn () => 'testing');

    app()->detectEnvironment(fn () => 'production');

    try {
        $this->artisan('carts:backfill-appointment-cart-links', [
            '--apply' => true,
            '--force-production' => true,
        ])->expectsOutputToContain('BACKFILL APPOINTMENT CART LINKS — APPLY')
            ->assertExitCode(0);
    } finally {
        $restore();
    }
});

it('respects forced cart filter when evidence is sufficient', function () {
    $user = backfillUser();
    $preferred = backfillLabCart($user, LaboratoryBrand::OLAB, ['updated_at' => now()->subMinutes(9)]);
    $other = backfillLabCart($user, LaboratoryBrand::OLAB, ['updated_at' => now()->subMinutes(20)]);
    backfillCheckoutEvidence($preferred);
    backfillCheckoutEvidence($other, now()->subMinutes(25));

    $appointment = backfillLegacyAppointment($user->customer, LaboratoryBrand::OLAB);

    $assessment = app(AppointmentCartLinkBackfillMatcher::class)->assess($appointment, $preferred->id);

    expect($assessment['action'])->toBe(AppointmentCartLinkBackfillMatcher::STATUS_MATCHED)
        ->and($assessment['candidate_cart_id'])->toBe($preferred->id);
});

it('runs only against testing sqlite database', function () {
    expect(app()->environment())->toBe('testing')
        ->and(config('database.default'))->toBe('sqlite')
        ->and(strtolower((string) config('database.connections.sqlite.database')))->toContain('test');
});
