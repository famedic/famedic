<?php

use App\Actions\Admin\LaboratoryAppointments\UpdateLaboratoryAppointmentAction;
use App\Enums\ActiveCampaignSiteEvent;
use App\Enums\CartEventType;
use App\Enums\Gender;
use App\Enums\LaboratoryBrand;
use App\Enums\MonitoringCartStatus;
use App\Enums\MonitoringCartType;
use App\Jobs\ActiveCampaign\DispatchActiveCampaignOutboundJob;
use App\Jobs\Carts\CheckAppointmentPendingJob;
use App\Models\ActiveCampaignDispatch;
use App\Models\Cart;
use App\Models\CartEvent;
use App\Models\CartItem;
use App\Models\LaboratoryAppointment;
use App\Models\LaboratoryCartItem;
use App\Models\LaboratoryStore;
use App\Models\LaboratoryTest;
use App\Models\User;
use App\Services\Carts\AppointmentPendingDetectionService;
use App\Services\Carts\CartAppointmentContactSignalService;
use App\Services\Monitoring\SyncMonitoringCartService;
use Carbon\Carbon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Spatie\Permission\Models\Permission;

beforeEach(function () {
    config([
        'services.activecampaign.enabled' => true,
        'services.activecampaign.cart_outbox_enabled' => true,
        'services.activecampaign.cart_site_events_enabled' => true,
        'services.activecampaign.cart_tag_remove_enabled' => true,
        'services.activecampaign.cart_appointment_signals_enabled' => true,
        'services.activecampaign.cart_call_signals_enabled' => true,
        'services.activecampaign.account_id' => '12345',
        'services.activecampaign.event_key' => 'event-key-test',
        'services.activecampaign.endpoint' => 'https://ac.test',
        'services.activecampaign.token' => 'token-test',
        'services.activecampaign.tags.cart.appointment_pending' => 'Cita pendiente',
        'services.activecampaign.tags.call.requested' => 'Solicito llamada',
        'services.activecampaign.tags.call.attempted' => 'Intento llamar',
        'carts.appointment_pending_after_minutes' => 5,
    ]);

    Http::fake([
        'https://ac.test/api/3/contacts*' => Http::response([
            'contacts' => [['id' => 42, 'email' => 'user@example.com']],
        ], 200),
        'https://ac.test/api/3/contact/sync' => Http::response(['contact' => ['id' => 42]], 200),
        'https://ac.test/api/3/contactTags' => Http::response(['contactTag' => ['id' => 1]], 201),
        'https://ac.test/api/3/contacts/*/contactTags' => Http::response(['contactTags' => []], 200),
        'https://trackcmp.net/event' => Http::response(['success' => 1], 200),
    ]);
});

function phase4User(array $attributes = []): User
{
    return User::factory()
        ->withRegularCustomer()
        ->withCompleteProfile()
        ->create($attributes);
}

function phase4Cart(User $user): Cart
{
    $test = LaboratoryTest::factory()->create([
        'brand' => LaboratoryBrand::OLAB->value,
        'requires_appointment' => true,
    ]);

    LaboratoryCartItem::factory()->create([
        'customer_id' => $user->customer->id,
        'laboratory_test_id' => $test->id,
    ]);

    app(SyncMonitoringCartService::class)->syncLaboratory($user->customer);

    return Cart::query()
        ->where('user_id', $user->id)
        ->where('type', MonitoringCartType::Lab)
        ->firstOrFail()
        ->fresh(['items', 'user.customer']);
}

function phase4PendingAppointment(Cart $cart, int $minutesAgo = 6): LaboratoryAppointment
{
    $appointment = LaboratoryAppointment::factory()->create([
        'customer_id' => $cart->user->customer->id,
        'cart_id' => $cart->id,
        'brand' => LaboratoryBrand::OLAB->value,
        'confirmed_at' => null,
        'created_at' => now()->subMinutes($minutesAgo),
        'updated_at' => now()->subMinutes($minutesAgo),
    ]);

    CartEvent::query()->create([
        'cart_id' => $cart->id,
        'event' => CartEventType::AppointmentRequested->value,
        'idempotency_key' => "laboratory_appointment:{$appointment->id}:requested",
        'metadata' => ['appointment_id' => $appointment->id],
        'occurred_at' => $appointment->created_at,
        'source' => 'test',
    ]);

    return $appointment->fresh(['cart.items', 'cart.user.customer']);
}

function phase4Admin(): User
{
    Permission::findOrCreate('view cart details', 'web');
    $user = User::factory()->withAdministrator()->create();
    $user->administrator->givePermissionTo('view cart details');

    return $user;
}

it('does not record appointment_pending_5m when appointment is confirmed before threshold', function () {
    $cart = phase4Cart(phase4User(['email' => 'confirmed@example.com']));
    $appointment = phase4PendingAppointment($cart, 2);
    $appointment->update(['confirmed_at' => now()]);

    expect(app(AppointmentPendingDetectionService::class)->detectAndRecord($appointment->fresh()))->toBeNull();

    expect(CartEvent::query()->where('event', CartEventType::AppointmentPending5m->value)->count())->toBe(0);
});

it('records appointment_pending_5m after threshold for unconfirmed appointment', function () {
    Queue::fake();
    $cart = phase4Cart(phase4User(['email' => 'pending@example.com']));
    $appointment = phase4PendingAppointment($cart, 6);

    $event = app(AppointmentPendingDetectionService::class)->detectAndRecord($appointment);

    expect($event)->not->toBeNull()
        ->and($event->event->value)->toBe(CartEventType::AppointmentPending5m->value)
        ->and($event->metadata['minutes_pending'])->toBeGreaterThanOrEqual(5);

    expect(ActiveCampaignDispatch::query()->where('idempotency_key', "appointment:{$appointment->id}:pending_5m:tag:add")->exists())->toBeTrue()
        ->and(ActiveCampaignDispatch::query()->where('idempotency_key', "appointment:{$appointment->id}:pending_5m:site_event")->exists())->toBeTrue();
});

it('records appointment_pending_5m only once when detector runs twice', function () {
    Queue::fake();
    $cart = phase4Cart(phase4User(['email' => 'once@example.com']));
    $appointment = phase4PendingAppointment($cart, 6);
    $service = app(AppointmentPendingDetectionService::class);

    $service->detectAndRecord($appointment);
    $service->detectAndRecord($appointment->fresh());

    expect(CartEvent::query()->where('event', CartEventType::AppointmentPending5m->value)->count())->toBe(1)
        ->and(ActiveCampaignDispatch::query()->where('idempotency_key', "appointment:{$appointment->id}:pending_5m:tag:add")->count())->toBe(1);
});

it('reconciles appointment pending dispatches via sync command', function () {
    Queue::fake();
    $cart = phase4Cart(phase4User(['email' => 'sync@example.com']));
    $appointment = phase4PendingAppointment($cart, 6);

    CartEvent::query()->create([
        'cart_id' => $cart->id,
        'event' => CartEventType::AppointmentPending5m->value,
        'idempotency_key' => "appointment:{$appointment->id}:pending_5m",
        'metadata' => [
            'appointment_id' => $appointment->id,
            'cart_id' => $cart->id,
            'brand' => LaboratoryBrand::OLAB->value,
            'minutes_pending' => 6,
        ],
        'occurred_at' => now(),
        'source' => 'test',
    ]);

    ActiveCampaignDispatch::query()->where('idempotency_key', 'like', "appointment:{$appointment->id}:pending_5m:%")->delete();

    Artisan::call('activecampaign:sync-cart-outbox');

    expect(ActiveCampaignDispatch::query()->where('idempotency_key', "appointment:{$appointment->id}:pending_5m:tag:add")->exists())->toBeTrue();
});

it('removes pending tag and sends confirmed site event on appointment confirmation', function () {
    Queue::fake();
    $cart = phase4Cart(phase4User(['email' => 'confirm@example.com']));
    $appointment = phase4PendingAppointment($cart, 6);
    app(AppointmentPendingDetectionService::class)->detectAndRecord($appointment);

    $store = LaboratoryStore::query()->create([
        'name' => 'Sucursal Test',
        'brand' => LaboratoryBrand::OLAB->value,
        'state' => 'Nuevo Leon',
        'address' => 'Calle 1',
        'weekly_hours' => '9-18',
        'saturday_hours' => '9-13',
        'sunday_hours' => 'Cerrado',
        'google_maps_url' => 'https://maps.example.test',
    ]);

    app(UpdateLaboratoryAppointmentAction::class)(
        appointment_date: now()->addDay()->format('Y-m-d'),
        appointment_time: '10:00',
        patient_name: 'Paciente',
        patient_paternal_lastname: 'Uno',
        patient_maternal_lastname: 'Dos',
        patient_birth_date: Carbon::parse('1990-01-01'),
        patient_gender: Gender::MALE,
        patient_phone: '8111111111',
        patient_phone_country: 'MX',
        laboratory_store: $store->id,
        notes: null,
        laboratoryAppointment: $appointment->fresh(),
    );

    expect(ActiveCampaignDispatch::query()->where('idempotency_key', "appointment:{$appointment->id}:pending_5m:tag:remove")->exists())->toBeTrue()
        ->and(ActiveCampaignDispatch::query()->where('idempotency_key', "appointment:{$appointment->id}:confirmed:site_event")->exists())->toBeTrue();
});

it('does not record pending for completed or empty carts', function () {
    $cart = phase4Cart(phase4User(['email' => 'completed@example.com']));
    $appointment = phase4PendingAppointment($cart, 6);

    $cart->update([
        'status' => MonitoringCartStatus::Completed,
        'completed_at' => now(),
    ]);
    $cart->items()->delete();

    expect(app(AppointmentPendingDetectionService::class)->detectAndRecord($appointment->fresh()))->toBeNull();
});

it('does not contaminate journey with appointment pending from another cart', function () {
    $user = phase4User();
    $cartA = phase4Cart($user);
    $appointmentA = phase4PendingAppointment($cartA, 6);
    app(AppointmentPendingDetectionService::class)->detectAndRecord($appointmentA);

    $cartB = Cart::query()->create([
        'user_id' => $user->id,
        'type' => MonitoringCartType::Lab->value,
        'status' => MonitoringCartStatus::Active->value,
        'total' => 500,
        'created_at' => now()->subMinutes(10),
        'updated_at' => now()->subMinutes(2),
    ]);

    CartItem::query()->create([
        'cart_id' => $cartB->id,
        'product_id' => (string) LaboratoryTest::factory()->create([
            'brand' => LaboratoryBrand::OLAB->value,
            'requires_appointment' => true,
        ])->id,
        'name' => 'Estudio',
        'price' => 500,
        'quantity' => 1,
    ]);

    $admin = phase4Admin();
    $this->actingAs($admin);

    $this->getJson(route('admin.carts.show', $cartB))
        ->assertOk()
        ->assertJsonPath('data.journey.3.detail', 'No seleccionada')
        ->assertJsonPath('data.appointment', null);
});

it('records call_requested with tag and site event outbox entries', function () {
    Queue::fake();
    $cart = phase4Cart(phase4User(['email' => 'callback@example.com']));
    $appointment = phase4PendingAppointment($cart, 1);

    $event = app(CartAppointmentContactSignalService::class)->recordCallRequested(
        $appointment,
        interactionId: 9001,
        hasCallbackAvailability: true,
    );

    expect($event)->not->toBeNull()
        ->and($event->metadata)->not->toHaveKey('patient_callback_comment');

    expect(ActiveCampaignDispatch::query()->where('idempotency_key', 'appointment_interaction:9001:call_requested:tag:add')->exists())->toBeTrue()
        ->and(ActiveCampaignDispatch::query()->where('idempotency_key', 'appointment_interaction:9001:call_requested:site_event')->exists())->toBeTrue();
});

it('records call_attempted with tag and site event outbox entries', function () {
    Queue::fake();
    $cart = phase4Cart(phase4User(['email' => 'phone@example.com']));
    $appointment = phase4PendingAppointment($cart, 1);

    $event = app(CartAppointmentContactSignalService::class)->recordCallAttempted(
        $appointment,
        interactionId: 9002,
    );

    expect($event)->not->toBeNull();

    expect(ActiveCampaignDispatch::query()->where('idempotency_key', 'appointment_interaction:9002:call_attempted:tag:add')->exists())->toBeTrue()
        ->and(ActiveCampaignDispatch::query()->where('idempotency_key', 'appointment_interaction:9002:call_attempted:site_event')->exists())->toBeTrue();
});

it('allows distinct call events for repeated legitimate interactions', function () {
    Queue::fake();
    $cart = phase4Cart(phase4User(['email' => 'repeat@example.com']));
    $appointment = phase4PendingAppointment($cart, 1);
    $service = app(CartAppointmentContactSignalService::class);

    $service->recordCallAttempted($appointment, 9101);
    $service->recordCallAttempted($appointment, 9102);

    expect(CartEvent::query()->where('event', CartEventType::CallAttempted->value)->count())->toBe(2);
});

it('retries pending dispatch without duplicating rows for same interaction', function () {
    Queue::fake();
    $cart = phase4Cart(phase4User(['email' => 'retry@example.com']));
    $appointment = phase4PendingAppointment($cart, 1);
    $service = app(CartAppointmentContactSignalService::class);

    $service->recordCallRequested($appointment, 9201, true);
    Artisan::call('activecampaign:sync-cart-outbox');

    expect(ActiveCampaignDispatch::query()->where('idempotency_key', 'appointment_interaction:9201:call_requested:tag:add')->count())->toBe(1);
});

it('marks call signal dispatches skipped without http when email is missing', function () {
    Queue::fake();
    Http::fake();
    $cart = phase4Cart(phase4User(['email' => '']));
    $appointment = phase4PendingAppointment($cart, 1);

    app(CartAppointmentContactSignalService::class)->recordCallRequested($appointment, 9301, false);

    $dispatch = ActiveCampaignDispatch::query()
        ->where('idempotency_key', 'appointment_interaction:9301:call_requested:tag:add')
        ->first();

    expect($dispatch)->not->toBeNull()
        ->and($dispatch->status)->toBe(ActiveCampaignDispatch::STATUS_SKIPPED);

    Http::assertNothingSent();
});

it('processes appointment pending site event job successfully', function () {
    Queue::fake();
    $cart = phase4Cart(phase4User(['email' => 'job@example.com']));
    $appointment = phase4PendingAppointment($cart, 6);
    app(AppointmentPendingDetectionService::class)->detectAndRecord($appointment);

    $dispatch = ActiveCampaignDispatch::query()
        ->where('idempotency_key', "appointment:{$appointment->id}:pending_5m:site_event")
        ->firstOrFail();

    (new DispatchActiveCampaignOutboundJob($dispatch->id))->handle(app(\App\Services\ActiveCampaign\ActiveCampaignService::class));

    expect($dispatch->fresh()->status)->toBe(ActiveCampaignDispatch::STATUS_SYNCED)
        ->and($dispatch->fresh()->event_type)->toBe(ActiveCampaignSiteEvent::AppointmentPending5m->value);
});

it('runs delayed check appointment pending job path', function () {
    Queue::fake();
    $cart = phase4Cart(phase4User(['email' => 'delayed@example.com']));
    $appointment = phase4PendingAppointment($cart, 0);
    $appointment->update(['created_at' => now()->subMinutes(6), 'updated_at' => now()->subMinutes(6)]);

    (new CheckAppointmentPendingJob($appointment->id))->handle(app(AppointmentPendingDetectionService::class));

    expect(CartEvent::query()->where('event', CartEventType::AppointmentPending5m->value)->exists())->toBeTrue();
});

it('shows appointment and call timeline labels in cart drawer', function () {
    Queue::fake();
    $cart = phase4Cart(phase4User(['email' => 'drawer@example.com']));
    $appointment = phase4PendingAppointment($cart, 6);
    app(AppointmentPendingDetectionService::class)->detectAndRecord($appointment);
    app(CartAppointmentContactSignalService::class)->recordCallRequested($appointment, 9401, true);
    app(CartAppointmentContactSignalService::class)->recordCallAttempted($appointment, 9402);

    $admin = phase4Admin();
    $this->actingAs($admin);

    $response = $this->getJson(route('admin.carts.show', $cart))->assertOk();

    $eventLabels = collect($response->json('data.events'))->pluck('label');
    $acLabels = collect($response->json('data.activecampaign.items'))->pluck('label');

    expect($eventLabels)->toContain('Cita pendiente por 5 min')
        ->and($eventLabels)->toContain('Usuario solicitó llamada')
        ->and($eventLabels)->toContain('Usuario intentó llamar')
        ->and($acLabels)->toContain('Tag agregado — Cita pendiente')
        ->and($acLabels)->toContain(ActiveCampaignSiteEvent::AppointmentPending5m->value);
});

it('detects stale pending appointments via artisan command', function () {
    Queue::fake();
    $cart = phase4Cart(phase4User(['email' => 'command@example.com']));
    phase4PendingAppointment($cart, 8);

    Artisan::call('carts:detect-appointment-pending');

    expect(CartEvent::query()->where('event', CartEventType::AppointmentPending5m->value)->count())->toBe(1);
});

it('does not mark appointment pending at 4 minutes 59 seconds', function () {
    $now = Carbon::parse('2026-03-10 12:00:00');
    Carbon::setTestNow($now);

    $cart = phase4Cart(phase4User(['email' => 'boundary-early@example.com']));
    $appointment = phase4PendingAppointment($cart, 0);
    $appointment->update([
        'created_at' => $now->copy()->subMinutes(5)->addSecond(),
        'updated_at' => $now->copy()->subMinutes(5)->addSecond(),
    ]);

    expect(app(AppointmentPendingDetectionService::class)->isEligible($appointment->fresh()))->toBeFalse()
        ->and(CartEvent::query()->where('event', CartEventType::AppointmentPending5m->value)->count())->toBe(0);

    Carbon::setTestNow();
});

it('marks appointment pending at exactly 5 minutes', function () {
    Queue::fake();
    $now = Carbon::parse('2026-03-10 12:00:00');
    Carbon::setTestNow($now);

    $cart = phase4Cart(phase4User(['email' => 'boundary-exact@example.com']));
    $appointment = phase4PendingAppointment($cart, 0);
    $appointment->update([
        'created_at' => $now->copy()->subMinutes(5),
        'updated_at' => $now->copy()->subMinutes(5),
    ]);

    $event = app(AppointmentPendingDetectionService::class)->detectAndRecord($appointment->fresh());

    expect($event)->not->toBeNull()
        ->and($event->event->value)->toBe(CartEventType::AppointmentPending5m->value);

    Carbon::setTestNow();
});

it('emits only one confirmed site event when admin confirms twice', function () {
    Queue::fake();
    $cart = phase4Cart(phase4User(['email' => 'double-confirm@example.com']));
    $appointment = phase4PendingAppointment($cart, 6);
    $store = LaboratoryStore::query()->create([
        'name' => 'Sucursal Test',
        'brand' => LaboratoryBrand::OLAB->value,
        'state' => 'Nuevo Leon',
        'address' => 'Calle 1',
        'weekly_hours' => '9-18',
        'saturday_hours' => '9-13',
        'sunday_hours' => 'Cerrado',
        'google_maps_url' => 'https://maps.example.test',
    ]);
    $action = app(UpdateLaboratoryAppointmentAction::class);
    $args = [
        'appointment_date' => now()->addDay()->format('Y-m-d'),
        'appointment_time' => '10:00',
        'patient_name' => 'Paciente',
        'patient_paternal_lastname' => 'Uno',
        'patient_maternal_lastname' => 'Dos',
        'patient_birth_date' => Carbon::parse('1990-01-01'),
        'patient_gender' => Gender::MALE,
        'patient_phone' => '8111111111',
        'patient_phone_country' => 'MX',
        'laboratory_store' => $store->id,
        'notes' => null,
        'laboratoryAppointment' => $appointment->fresh(),
    ];

    $action(...$args);
    $args['laboratoryAppointment'] = $appointment->fresh();
    $args['notes'] = 'Segunda edición';
    $action(...$args);

    expect(CartEvent::query()->where('event', CartEventType::AppointmentConfirmed->value)->count())->toBe(1)
        ->and(ActiveCampaignDispatch::query()->where('idempotency_key', "appointment:{$appointment->id}:confirmed:site_event")->count())->toBe(1);
});

it('does not record pending when check job runs after confirmation', function () {
    Queue::fake();
    $cart = phase4Cart(phase4User(['email' => 'race@example.com']));
    $appointment = phase4PendingAppointment($cart, 6);
    $appointment->update(['confirmed_at' => now()]);

    (new CheckAppointmentPendingJob($appointment->id))->handle(app(AppointmentPendingDetectionService::class));

    expect(CartEvent::query()->where('event', CartEventType::AppointmentPending5m->value)->count())->toBe(0);
});

it('does not enqueue pending tag remove on cart completion without prior pending signal', function () {
    Queue::fake();
    $user = phase4User(['email' => 'complete-no-pending@example.com']);
    $cart = phase4Cart($user);
    phase4PendingAppointment($cart, 2);

    app(SyncMonitoringCartService::class)->markLaboratoryCartCompleted($user->customer, LaboratoryBrand::OLAB);

    expect(ActiveCampaignDispatch::query()
        ->where('idempotency_key', 'like', '%:pending_5m:tag:remove')
        ->exists())->toBeFalse();
});

it('does not duplicate call_requested when callback data is unchanged', function () {
    Queue::fake();
    $user = phase4User(['email' => 'callback-no-dup@example.com']);
    $cart = phase4Cart($user);
    $appointment = phase4PendingAppointment($cart, 1);
    $appointment->update([
        'callback_availability_starts_at' => null,
        'callback_availability_ends_at' => null,
        'patient_callback_comment' => 'Llámame por la tarde',
    ]);

    $this->withoutMiddleware([
        \App\Http\Middleware\EnsurePhoneIsVerified::class,
        \Illuminate\Auth\Middleware\EnsureEmailIsVerified::class,
        \App\Http\Middleware\EnsureDocumentationIsAccepted::class,
        \App\Http\Middleware\RedirectIfUserProfileIsIncomplete::class,
    ]);
    $this->actingAs($user);

    $payload = ['patient_callback_comment' => 'Llámame por la tarde'];

    $this->patch(route('laboratory-appointments.callback-availability', [
        'laboratory_brand' => LaboratoryBrand::OLAB,
        'laboratory_appointment' => $appointment,
    ]), $payload)->assertRedirect();

    expect(CartEvent::query()->where('event', CartEventType::CallRequested->value)->count())->toBe(0)
        ->and($appointment->interactions()->count())->toBe(0);
});

it('does not duplicate call_attempted on rapid phone intent retry', function () {
    Queue::fake();
    $user = phase4User(['email' => 'phone-retry@example.com']);
    $cart = phase4Cart($user);
    $appointment = phase4PendingAppointment($cart, 1);

    $this->withoutMiddleware([
        \App\Http\Middleware\EnsurePhoneIsVerified::class,
        \Illuminate\Auth\Middleware\EnsureEmailIsVerified::class,
        \App\Http\Middleware\EnsureDocumentationIsAccepted::class,
        \App\Http\Middleware\RedirectIfUserProfileIsIncomplete::class,
    ]);
    $this->actingAs($user);

    $route = route('laboratory-appointments.phone-intent', [
        'laboratory_brand' => LaboratoryBrand::OLAB,
        'laboratory_appointment' => $appointment,
    ]);

    $this->post($route)->assertRedirect();
    $this->post($route)->assertRedirect();

    expect(CartEvent::query()->where('event', CartEventType::CallAttempted->value)->count())->toBe(1)
        ->and($appointment->interactions()->count())->toBe(1);
});
