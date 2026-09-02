<?php

use App\Enums\CartEventType;
use App\Enums\Gender;
use App\Enums\LaboratoryBrand;
use App\Enums\MonitoringCartStatus;
use App\Enums\MonitoringCartType;
use App\Models\Administrator;
use App\Models\Cart;
use App\Models\CartEvent;
use App\Models\CartItem;
use App\Models\LaboratoryAppointment;
use App\Models\LaboratoryConcierge;
use App\Models\LaboratoryPurchase;
use App\Models\LaboratoryTest;
use App\Models\Permission;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\PermissionRegistrar;

function pendingTabConciergeAdmin(array $permissions = []): User
{
    $user = User::factory()->create();
    $administrator = Administrator::factory()->for($user)->create();
    LaboratoryConcierge::factory()->create(['administrator_id' => $administrator->id]);

    foreach ($permissions as $permissionName) {
        Permission::query()->firstOrCreate([
            'name' => $permissionName,
            'guard_name' => 'web',
        ]);
    }

    if ($permissions !== []) {
        $administrator->givePermissionTo($permissions);
        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    return $user->fresh(['administrator.laboratoryConcierge']);
}

function pendingTabPurchase(int $customerId): LaboratoryPurchase
{
    return LaboratoryPurchase::create([
        'brand' => LaboratoryBrand::SWISSLAB->value,
        'gda_order_id' => (string) fake()->unique()->numberBetween(100000, 999999),
        'name' => 'Paciente',
        'paternal_lastname' => 'Prueba',
        'maternal_lastname' => 'Test',
        'phone' => '8112345678',
        'phone_country' => 'MX',
        'birth_date' => '1990-01-01',
        'gender' => Gender::MALE->value,
        'street' => 'Calle Test',
        'number' => '100',
        'neighborhood' => 'Centro',
        'state' => 'NL',
        'city' => 'Monterrey',
        'zipcode' => '64000',
        'total_cents' => 100_000,
        'customer_id' => $customerId,
    ]);
}

function pendingTabAppointment(array $overrides = []): LaboratoryAppointment
{
    $user = User::factory()->withRegularCustomer()->create();

    return pendingTabAppointmentForCustomer($user, $overrides);
}

function pendingTabAppointmentForCustomer(User $user, array $overrides = []): LaboratoryAppointment
{
    return LaboratoryAppointment::factory()->create(array_merge([
        'customer_id' => $user->customer->id,
        'brand' => LaboratoryBrand::SWISSLAB,
        'confirmed_at' => null,
        'laboratory_purchase_id' => null,
        'appointment_date' => null,
    ], $overrides));
}

function pendingTabLabCart(User $user, LaboratoryBrand $brand, array $attributes = []): Cart
{
    if (! \Illuminate\Support\Facades\Schema::hasTable('carts')) {
        test()->markTestSkipped('Tabla carts no disponible.');
    }

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
        'name' => 'Estudio pending tab',
        'price' => 1000.00,
        'quantity' => 1,
    ]);

    return $cart;
}

function pendingTabCartUserEvent(Cart $cart, ?Carbon $occurredAt = null): void
{
    if (! \Illuminate\Support\Facades\Schema::hasTable('cart_events')) {
        return;
    }

    CartEvent::query()->create([
        'cart_id' => $cart->id,
        'event' => CartEventType::CheckoutVisited->value,
        'metadata' => ['source' => 'test'],
        'occurred_at' => $occurredAt ?? now(),
    ]);
}

test('cita pendiente antigua visible sin filtro temporal', function () {
    $admin = pendingTabConciergeAdmin();
    $appointment = pendingTabAppointment([
        'created_at' => now()->subDays(90),
        'patient_name' => 'Antigua',
        'patient_paternal_lastname' => 'Pendiente',
        'patient_maternal_lastname' => 'Prueba',
    ]);

    $this->actingAs($admin)
        ->get(route('admin.laboratory-appointments.index', ['view' => 'pending']))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('Admin/LaboratoryAppointments')
            ->where('filters.view', 'pending')
            ->has('laboratoryAppointments.data', 1)
            ->where('laboratoryAppointments.data.0.id', $appointment->id));
});

test('cita pendiente sin appointment_date visible', function () {
    $admin = pendingTabConciergeAdmin();
    $appointment = pendingTabAppointment(['appointment_date' => null]);

    $this->actingAs($admin)
        ->get(route('admin.laboratory-appointments.index', ['view' => 'pending']))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('laboratoryAppointments.data.0.id', $appointment->id));
});

test('cita soft-deleted no visible en pendientes', function () {
    $admin = pendingTabConciergeAdmin();
    $appointment = pendingTabAppointment();
    $appointment->delete();

    $this->actingAs($admin)
        ->get(route('admin.laboratory-appointments.index', ['view' => 'pending']))
        ->assertOk()
        ->assertInertia(fn ($page) => $page->has('laboratoryAppointments.data', 0));
});

test('cita confirmada no visible en pendientes', function () {
    $admin = pendingTabConciergeAdmin();
    pendingTabAppointment(['confirmed_at' => now()]);

    $this->actingAs($admin)
        ->get(route('admin.laboratory-appointments.index', ['view' => 'pending']))
        ->assertOk()
        ->assertInertia(fn ($page) => $page->has('laboratoryAppointments.data', 0));
});

test('cita con laboratory_purchase_id no visible en pendientes', function () {
    $admin = pendingTabConciergeAdmin();
    $user = User::factory()->withRegularCustomer()->create();
    $purchase = pendingTabPurchase($user->customer->id);

    pendingTabAppointment([
        'customer_id' => $user->customer->id,
        'laboratory_purchase_id' => $purchase->id,
    ]);

    $this->actingAs($admin)
        ->get(route('admin.laboratory-appointments.index', ['view' => 'pending']))
        ->assertOk()
        ->assertInertia(fn ($page) => $page->has('laboratoryAppointments.data', 0));
});

test('priority es el orden predeterminado en pendientes', function () {
    $admin = pendingTabConciergeAdmin();
    pendingTabAppointment();

    $this->actingAs($admin)
        ->get(route('admin.laboratory-appointments.index', ['view' => 'pending']))
        ->assertOk()
        ->assertInertia(fn ($page) => $page->where('filters.pending_sort', 'priority'));
});

test('orden antiguas primero cuando pending_sort es oldest', function () {
    $admin = pendingTabConciergeAdmin();
    $older = pendingTabAppointment(['created_at' => now()->subDays(10)]);
    $newer = pendingTabAppointment(['created_at' => now()->subDay()]);

    $this->actingAs($admin)
        ->get(route('admin.laboratory-appointments.index', [
            'view' => 'pending',
            'pending_sort' => 'oldest',
        ]))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('laboratoryAppointments.data.0.id', $older->id)
            ->where('laboratoryAppointments.data.1.id', $newer->id)
            ->where('filters.pending_sort', 'oldest'));
});

test('orden recientes primero cuando pending_sort es newest', function () {
    $admin = pendingTabConciergeAdmin();
    $older = pendingTabAppointment(['created_at' => now()->subDays(10)]);
    $newer = pendingTabAppointment(['created_at' => now()->subDay()]);

    $this->actingAs($admin)
        ->get(route('admin.laboratory-appointments.index', [
            'view' => 'pending',
            'pending_sort' => 'newest',
        ]))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('laboratoryAppointments.data.0.id', $newer->id)
            ->where('laboratoryAppointments.data.1.id', $older->id));
});

test('búsqueda por paciente en pendientes', function () {
    $admin = pendingTabConciergeAdmin();
    $match = pendingTabAppointment([
        'patient_name' => 'Eulalio',
        'patient_paternal_lastname' => 'Medina',
        'patient_maternal_lastname' => 'Barragan',
    ]);
    pendingTabAppointment([
        'patient_name' => 'Otro',
        'patient_paternal_lastname' => 'Paciente',
        'patient_maternal_lastname' => 'Distinto',
    ]);

    $this->actingAs($admin)
        ->get(route('admin.laboratory-appointments.index', [
            'view' => 'pending',
            'search' => 'Eulalio',
        ]))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->has('laboratoryAppointments.data', 1)
            ->where('laboratoryAppointments.data.0.id', $match->id));
});

test('filtro de marca en pendientes', function () {
    $admin = pendingTabConciergeAdmin();
    $swisslab = pendingTabAppointment(['brand' => LaboratoryBrand::SWISSLAB]);
    pendingTabAppointment(['brand' => LaboratoryBrand::OLAB]);

    $this->actingAs($admin)
        ->get(route('admin.laboratory-appointments.index', [
            'view' => 'pending',
            'brand' => 'swisslab',
        ]))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->has('laboratoryAppointments.data', 1)
            ->where('laboratoryAppointments.data.0.id', $swisslab->id));
});

test('paginación en pendientes', function () {
    $admin = pendingTabConciergeAdmin();

    for ($i = 0; $i < 16; $i++) {
        pendingTabAppointment(['created_at' => now()->subDays($i + 1)]);
    }

    $this->actingAs($admin)
        ->get(route('admin.laboratory-appointments.index', ['view' => 'pending']))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('laboratoryAppointments.per_page', 15)
            ->where('laboratoryAppointments.total', 16)
            ->has('laboratoryAppointments.data', 15));

    $this->actingAs($admin)
        ->get(route('admin.laboratory-appointments.index', [
            'view' => 'pending',
            'page' => 2,
        ]))
        ->assertOk()
        ->assertInertia(fn ($page) => $page->has('laboratoryAppointments.data', 1));
});

test('badge total global de pendientes', function () {
    $admin = pendingTabConciergeAdmin();
    pendingTabAppointment();
    pendingTabAppointment();
    pendingTabAppointment(['confirmed_at' => now()]);

    $this->actingAs($admin)
        ->get(route('admin.laboratory-appointments.index', ['view' => 'pending']))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('pendingCount', 2)
            ->where('laboratoryAppointments.total', 2));
});

test('concierge autorizado puede abrir view pending', function () {
    $admin = pendingTabConciergeAdmin();

    $this->actingAs($admin)
        ->get(route('admin.laboratory-appointments.index', ['view' => 'pending']))
        ->assertOk();
});

test('concierge puede abrir detalle de cita pendiente', function () {
    $admin = pendingTabConciergeAdmin();
    $appointment = pendingTabAppointment();

    $this->actingAs($admin)
        ->get(route('admin.laboratory-appointments.show', $appointment))
        ->assertOk();
});

test('concierge sin view carts recibe 403 en admin carts', function () {
    Permission::query()->firstOrCreate([
        'name' => 'view carts',
        'guard_name' => 'web',
    ]);
    app(PermissionRegistrar::class)->forgetCachedPermissions();

    $admin = pendingTabConciergeAdmin();

    $this->actingAs($admin)
        ->get(route('admin.carts.index'))
        ->assertForbidden();
});

test('administrador sin relación concierge no accede al módulo de citas', function () {
    $user = User::factory()->create();
    Administrator::factory()->for($user)->create();

    $this->actingAs($user)
        ->get(route('admin.laboratory-appointments.index', ['view' => 'pending']))
        ->assertForbidden();
});

test('cita legacy sin cart_id sigue visible en pendientes', function () {
    $admin = pendingTabConciergeAdmin();
    $attributes = [
        'patient_name' => 'Legacy',
        'patient_paternal_lastname' => 'Sin',
        'patient_maternal_lastname' => 'Carrito',
    ];

    if (\Illuminate\Support\Facades\Schema::hasColumn('laboratory_appointments', 'cart_id')) {
        $attributes['cart_id'] = null;
    }

    $appointment = pendingTabAppointment($attributes);

    $response = $this->actingAs($admin)
        ->get(route('admin.laboratory-appointments.index', ['view' => 'pending']))
        ->assertOk();

    $response->assertInertia(fn ($page) => $page
        ->where('laboratoryAppointments.data.0.id', $appointment->id));

    if (\Illuminate\Support\Facades\Schema::hasColumn('laboratory_appointments', 'cart_id')) {
        $response->assertInertia(fn ($page) => $page
            ->where('laboratoryAppointments.data.0.admin_cart_status_label', 'Sin carrito relacionado'));
    }
});

test('dos clientes no mezclan información en búsqueda pendiente', function () {
    $admin = pendingTabConciergeAdmin();

    $userA = User::factory()->withRegularCustomer()->create(['email' => 'cliente-a@example.test']);
    $userB = User::factory()->withRegularCustomer()->create(['email' => 'cliente-b@example.test']);

    $appointmentA = LaboratoryAppointment::factory()->create([
        'customer_id' => $userA->customer->id,
        'brand' => LaboratoryBrand::SWISSLAB,
        'confirmed_at' => null,
        'laboratory_purchase_id' => null,
        'patient_name' => 'Cliente',
        'patient_paternal_lastname' => 'Alpha',
        'patient_maternal_lastname' => 'Uno',
    ]);

    LaboratoryAppointment::factory()->create([
        'customer_id' => $userB->customer->id,
        'brand' => LaboratoryBrand::SWISSLAB,
        'confirmed_at' => null,
        'laboratory_purchase_id' => null,
        'patient_name' => 'Cliente',
        'patient_paternal_lastname' => 'Beta',
        'patient_maternal_lastname' => 'Dos',
    ]);

    $this->actingAs($admin)
        ->get(route('admin.laboratory-appointments.index', [
            'view' => 'pending',
            'search' => 'cliente-a@example.test',
        ]))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->has('laboratoryAppointments.data', 1)
            ->where('laboratoryAppointments.data.0.id', $appointmentA->id)
            ->where('laboratoryAppointments.data.0.customer.user.email', 'cliente-a@example.test'));
});

test('pestaña citas conserva filtros históricos y excluye citas antiguas', function () {
    $admin = pendingTabConciergeAdmin();
    $oldPending = pendingTabAppointment(['created_at' => now()->subDays(90)]);

    $this->actingAs($admin)
        ->get(route('admin.laboratory-appointments.index', [
            'view' => 'list',
            'date_range' => 'last_30_days',
        ]))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('filters.view', 'list')
            ->where('filters.date_range', 'last_30_days')
            ->where('laboratoryAppointments.total', 0));

    $this->actingAs($admin)
        ->get(route('admin.laboratory-appointments.index', ['view' => 'pending']))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('laboratoryAppointments.data.0.id', $oldPending->id));
});

test('antigüedad operativa usa America Monterrey', function () {
    Carbon::setTestNow(Carbon::parse('2026-09-01 12:00:00', 'America/Monterrey'));

    $admin = pendingTabConciergeAdmin();
    pendingTabAppointment([
        'created_at' => Carbon::parse('2026-09-01 11:45:00', 'America/Monterrey'),
    ]);

    $this->actingAs($admin)
        ->get(route('admin.laboratory-appointments.index', ['view' => 'pending']))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('laboratoryAppointments.data.0.concierge_operational_age.status', 'waiting')
            ->where('laboratoryAppointments.data.0.concierge_operational_age.label', 'En espera'));

    Carbon::setTestNow();
});

test('cita mayor a 24 horas aparece como atrasada con duración en días', function () {
    Carbon::setTestNow(Carbon::parse('2026-09-01 12:00:00', 'America/Monterrey'));

    $admin = pendingTabConciergeAdmin();
    pendingTabAppointment([
        'created_at' => Carbon::parse('2026-07-07 10:00:00', 'America/Monterrey'),
    ]);

    $this->actingAs($admin)
        ->get(route('admin.laboratory-appointments.index', ['view' => 'pending']))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('laboratoryAppointments.data.0.concierge_operational_age.status', 'overdue')
            ->where('laboratoryAppointments.data.0.concierge_operational_age.label', 'Atrasada · 56 días'));

    Carbon::setTestNow();
});

test('serialización pendiente no provoca lazy loading en relaciones de cita', function () {
    $admin = pendingTabConciergeAdmin();

    for ($i = 0; $i < 5; $i++) {
        pendingTabAppointment(['created_at' => now()->subDays($i + 1)]);
    }

    DB::enableQueryLog();
    $this->actingAs($admin)
        ->get(route('admin.laboratory-appointments.index', ['view' => 'pending']))
        ->assertOk();

    $queries = collect(DB::getQueryLog())->pluck('query');
    $customerQueries = $queries->filter(
        fn (string $query) => str_contains($query, 'from "customers"')
            && str_contains($query, 'where "customers"."id"')
    )->count();
    $userQueries = $queries->filter(
        fn (string $query) => str_contains($query, 'from "users"')
            && str_contains($query, 'where "users"."id"')
    )->count();

    DB::disableQueryLog();

    expect($customerQueries)->toBeLessThanOrEqual(2)
        ->and($userQueries)->toBeLessThanOrEqual(2);
});

test('vista y filtros permanecen en query string', function () {
    $admin = pendingTabConciergeAdmin();
    pendingTabAppointment([
        'patient_name' => 'Persistencia',
        'patient_paternal_lastname' => 'Query',
        'patient_maternal_lastname' => 'String',
        'brand' => LaboratoryBrand::SWISSLAB,
    ]);

    $url = route('admin.laboratory-appointments.index', [
        'view' => 'pending',
        'search' => 'Persistencia',
        'brand' => 'swisslab',
        'pending_sort' => 'newest',
        'page' => 1,
    ]);

    $this->actingAs($admin)
        ->get($url)
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('filters.view', 'pending')
            ->where('filters.search', 'Persistencia')
            ->where('filters.brand', 'swisslab')
            ->where('filters.pending_sort', 'newest')
            ->where('laboratoryAppointments.current_page', 1));

    expect($url)->toContain('view=pending')
        ->and($url)->toContain('search=Persistencia')
        ->and($url)->toContain('brand=swisslab')
        ->and($url)->toContain('pending_sort=newest');
});

test('estado vacío correcto en pendientes', function () {
    $admin = pendingTabConciergeAdmin();

    $this->actingAs($admin)
        ->get(route('admin.laboratory-appointments.index', ['view' => 'pending']))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->has('laboratoryAppointments.data', 0)
            ->where('pendingCount', 0));
});

test('scope awaitingConcierge excluye eliminadas confirmadas y con compra', function () {
    pendingTabAppointment();
    pendingTabAppointment(['confirmed_at' => now()]);
    $deleted = pendingTabAppointment();
    $deleted->delete();

    $user = User::factory()->withRegularCustomer()->create();
    $purchase = pendingTabPurchase($user->customer->id);
    pendingTabAppointment([
        'customer_id' => $user->customer->id,
        'laboratory_purchase_id' => $purchase->id,
    ]);

    expect(LaboratoryAppointment::query()->awaitingConcierge()->count())->toBe(1);
});

test('date_range en URL no excluye pendientes antiguos', function () {
    $admin = pendingTabConciergeAdmin();
    $old = pendingTabAppointment(['created_at' => now()->subDays(120)]);

    $this->actingAs($admin)
        ->get(route('admin.laboratory-appointments.index', [
            'view' => 'pending',
            'date_range' => 'last_30_days',
            'completed' => 'true',
        ]))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('laboratoryAppointments.data.0.id', $old->id)
            ->where('laboratoryAppointments.total', 1));
});

test('consulta pendiente no escala linealmente con N+1 evidente', function () {
    $admin = pendingTabConciergeAdmin();

    pendingTabAppointment(['created_at' => now()->subDays(3)]);

    DB::flushQueryLog();
    DB::enableQueryLog();
    $this->actingAs($admin)->get(route('admin.laboratory-appointments.index', ['view' => 'pending']));
    $baselineUserQueries = collect(DB::getQueryLog())
        ->pluck('query')
        ->filter(fn (string $query) => str_contains($query, 'from "users"'))
        ->count();

    for ($i = 0; $i < 4; $i++) {
        pendingTabAppointment(['created_at' => now()->subDays($i + 4)]);
    }

    DB::flushQueryLog();
    DB::enableQueryLog();
    $this->actingAs($admin)->get(route('admin.laboratory-appointments.index', ['view' => 'pending']));
    $expandedUserQueries = collect(DB::getQueryLog())
        ->pluck('query')
        ->filter(fn (string $query) => str_contains($query, 'from "users"'))
        ->count();
    DB::disableQueryLog();

    expect($expandedUserQueries)->toBe($baselineUserQueries);
});

test('carrito con actividad menor a 24 horas aparece primero en priority', function () {
    if (! \Illuminate\Support\Facades\Schema::hasColumn('laboratory_appointments', 'cart_id')) {
        test()->markTestSkipped('Sin columna cart_id en laboratory_appointments.');
    }

    Carbon::setTestNow(Carbon::parse('2026-09-01 12:00:00', 'America/Monterrey'));
    $admin = pendingTabConciergeAdmin();

    $userRecent = User::factory()->withRegularCustomer()->create();
    $cartRecent = pendingTabLabCart($userRecent, LaboratoryBrand::SWISSLAB, [
        'updated_at' => Carbon::parse('2026-09-01 11:50:00', 'America/Monterrey'),
    ]);
    pendingTabCartUserEvent($cartRecent, Carbon::parse('2026-09-01 11:50:00', 'America/Monterrey'));
    $recentActivityAppointment = pendingTabAppointmentForCustomer($userRecent, [
        'brand' => LaboratoryBrand::SWISSLAB,
        'cart_id' => $cartRecent->id,
        'created_at' => Carbon::parse('2026-01-01 10:00:00', 'America/Monterrey'),
    ]);

    pendingTabAppointment(['created_at' => now()->subDays(2)]);

    $this->actingAs($admin)
        ->get(route('admin.laboratory-appointments.index', ['view' => 'pending']))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('laboratoryAppointments.data.0.id', $recentActivityAppointment->id)
            ->where('laboratoryAppointments.data.0.pending_priority_tier', 1));

    Carbon::setTestNow();
});

test('actividad entre 1 y 7 dias aparece despues de actividad reciente en priority', function () {
    if (! \Illuminate\Support\Facades\Schema::hasColumn('laboratory_appointments', 'cart_id')) {
        test()->markTestSkipped('Sin columna cart_id en laboratory_appointments.');
    }

    Carbon::setTestNow(Carbon::parse('2026-09-01 12:00:00', 'America/Monterrey'));
    $admin = pendingTabConciergeAdmin();

    $userWeek = User::factory()->withRegularCustomer()->create();
    $cartWeek = pendingTabLabCart($userWeek, LaboratoryBrand::SWISSLAB);
    pendingTabCartUserEvent($cartWeek, Carbon::parse('2026-08-28 10:00:00', 'America/Monterrey'));
    $weekAppointment = pendingTabAppointmentForCustomer($userWeek, [
        'cart_id' => $cartWeek->id,
        'created_at' => Carbon::parse('2026-01-01 10:00:00', 'America/Monterrey'),
    ]);

    $userRecent = User::factory()->withRegularCustomer()->create();
    $cartRecent = pendingTabLabCart($userRecent, LaboratoryBrand::SWISSLAB);
    pendingTabCartUserEvent($cartRecent, Carbon::parse('2026-09-01 11:00:00', 'America/Monterrey'));
    $recentAppointment = pendingTabAppointmentForCustomer($userRecent, [
        'cart_id' => $cartRecent->id,
        'created_at' => Carbon::parse('2026-01-02 10:00:00', 'America/Monterrey'),
    ]);

    $this->actingAs($admin)
        ->get(route('admin.laboratory-appointments.index', ['view' => 'pending']))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('laboratoryAppointments.data.0.id', $recentAppointment->id)
            ->where('laboratoryAppointments.data.1.id', $weekAppointment->id));

    Carbon::setTestNow();
});

test('carrito activo con actividad antigua aparece antes que cita sin carrito en priority', function () {
    if (! \Illuminate\Support\Facades\Schema::hasColumn('laboratory_appointments', 'cart_id')) {
        test()->markTestSkipped('Sin columna cart_id en laboratory_appointments.');
    }

    Carbon::setTestNow(Carbon::parse('2026-09-01 12:00:00', 'America/Monterrey'));
    $admin = pendingTabConciergeAdmin();

    $noCart = pendingTabAppointment(['created_at' => now()->subDay()]);

    $userOldActivity = User::factory()->withRegularCustomer()->create();
    $cartOld = pendingTabLabCart($userOldActivity, LaboratoryBrand::SWISSLAB);
    pendingTabCartUserEvent($cartOld, Carbon::parse('2026-08-01 10:00:00', 'America/Monterrey'));
    $oldActivityAppointment = pendingTabAppointmentForCustomer($userOldActivity, [
        'cart_id' => $cartOld->id,
        'created_at' => Carbon::parse('2026-01-01 10:00:00', 'America/Monterrey'),
    ]);

    $this->actingAs($admin)
        ->get(route('admin.laboratory-appointments.index', ['view' => 'pending']))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('laboratoryAppointments.data.0.id', $oldActivityAppointment->id)
            ->where('laboratoryAppointments.data.0.pending_priority_tier', 3)
            ->where('laboratoryAppointments.data.1.id', $noCart->id)
            ->where('laboratoryAppointments.data.1.pending_priority_tier', 4));

    Carbon::setTestNow();
});

test('cita sin carrito queda al final en priority', function () {
    if (! \Illuminate\Support\Facades\Schema::hasColumn('laboratory_appointments', 'cart_id')) {
        test()->markTestSkipped('Sin columna cart_id en laboratory_appointments.');
    }

    $admin = pendingTabConciergeAdmin();

    $user = User::factory()->withRegularCustomer()->create();
    $cart = pendingTabLabCart($user, LaboratoryBrand::SWISSLAB);
    pendingTabCartUserEvent($cart, now()->subMinutes(20));
    $withCart = pendingTabAppointmentForCustomer($user, [
        'cart_id' => $cart->id,
        'created_at' => now()->subDays(300),
    ]);

    $withoutCart = pendingTabAppointment(['created_at' => now()->subDay()]);

    $this->actingAs($admin)
        ->get(route('admin.laboratory-appointments.index', ['view' => 'pending']))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('laboratoryAppointments.data.0.id', $withCart->id)
            ->where('laboratoryAppointments.data.1.id', $withoutCart->id));
});

test('cita antigua con actividad reciente supera a cita reciente sin actividad', function () {
    if (! \Illuminate\Support\Facades\Schema::hasColumn('laboratory_appointments', 'cart_id')) {
        test()->markTestSkipped('Sin columna cart_id en laboratory_appointments.');
    }

    Carbon::setTestNow(Carbon::parse('2026-09-01 12:00:00', 'America/Monterrey'));
    $admin = pendingTabConciergeAdmin();

    $userHot = User::factory()->withRegularCustomer()->create();
    $hotCart = pendingTabLabCart($userHot, LaboratoryBrand::SWISSLAB);
    pendingTabCartUserEvent($hotCart, Carbon::parse('2026-09-01 11:55:00', 'America/Monterrey'));
    $hotOldAppointment = pendingTabAppointmentForCustomer($userHot, [
        'cart_id' => $hotCart->id,
        'created_at' => Carbon::parse('2025-11-01 10:00:00', 'America/Monterrey'),
    ]);

    $coldRecent = pendingTabAppointment([
        'created_at' => Carbon::parse('2026-08-30 10:00:00', 'America/Monterrey'),
    ]);

    $this->actingAs($admin)
        ->get(route('admin.laboratory-appointments.index', ['view' => 'pending']))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('laboratoryAppointments.data.0.id', $hotOldAppointment->id)
            ->where('laboratoryAppointments.data.1.id', $coldRecent->id)
            ->where('laboratoryAppointments.data.0.pending_priority_tier', 1)
            ->where(
                'laboratoryAppointments.data.0.concierge_cart_activity_signal.label',
                fn (string $label) => str_starts_with($label, 'Actividad reciente ·'),
            ));

    Carbon::setTestNow();
});

test('solo actualizar carts.updated_at sin eventos no eleva prioridad', function () {
    if (! \Illuminate\Support\Facades\Schema::hasColumn('laboratory_appointments', 'cart_id')
        || ! \Illuminate\Support\Facades\Schema::hasTable('cart_events')) {
        test()->markTestSkipped('Requiere cart_id y cart_events.');
    }

    Carbon::setTestNow(Carbon::parse('2026-09-01 12:00:00', 'America/Monterrey'));
    $admin = pendingTabConciergeAdmin();

    $user = User::factory()->withRegularCustomer()->create();
    $cart = pendingTabLabCart($user, LaboratoryBrand::SWISSLAB, [
        'updated_at' => Carbon::parse('2026-09-01 11:59:00', 'America/Monterrey'),
    ]);
    $appointment = pendingTabAppointmentForCustomer($user, [
        'cart_id' => $cart->id,
        'created_at' => Carbon::parse('2026-01-01 10:00:00', 'America/Monterrey'),
    ]);

    $this->actingAs($admin)
        ->get(route('admin.laboratory-appointments.index', ['view' => 'pending']))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('laboratoryAppointments.data.0.pending_priority_tier', 4)
            ->where('laboratoryAppointments.data.0.concierge_cart_activity_signal.label', 'Sin actividad reciente'));

    Carbon::setTestNow();
});

test('desempate estable por fecha e id en priority', function () {
    if (! \Illuminate\Support\Facades\Schema::hasColumn('laboratory_appointments', 'cart_id')) {
        test()->markTestSkipped('Sin columna cart_id en laboratory_appointments.');
    }

    $admin = pendingTabConciergeAdmin();
    $createdAt = now()->subDays(300);

    $user = User::factory()->withRegularCustomer()->create();
    $cart = pendingTabLabCart($user, LaboratoryBrand::SWISSLAB);
    pendingTabCartUserEvent($cart, now()->subMinutes(15));

    $first = pendingTabAppointmentForCustomer($user, [
        'cart_id' => $cart->id,
        'created_at' => $createdAt,
    ]);
    $second = pendingTabAppointmentForCustomer($user, [
        'cart_id' => $cart->id,
        'created_at' => $createdAt,
    ]);

    if ($first->id > $second->id) {
        [$first, $second] = [$second, $first];
    }

    $this->actingAs($admin)
        ->get(route('admin.laboratory-appointments.index', ['view' => 'pending']))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('laboratoryAppointments.data.0.id', $first->id)
            ->where('laboratoryAppointments.data.1.id', $second->id));
});

test('carrito completado no recibe prioridad operativa', function () {
    if (! \Illuminate\Support\Facades\Schema::hasColumn('laboratory_appointments', 'cart_id')) {
        test()->markTestSkipped('Sin columna cart_id en laboratory_appointments.');
    }

    $admin = pendingTabConciergeAdmin();
    $user = User::factory()->withRegularCustomer()->create();
    $cart = pendingTabLabCart($user, LaboratoryBrand::SWISSLAB, [
        'status' => MonitoringCartStatus::Completed->value,
        'completed_at' => now()->subHour(),
    ]);
    pendingTabCartUserEvent($cart, now()->subMinutes(5));

    $completedCartAppointment = pendingTabAppointmentForCustomer($user, [
        'cart_id' => $cart->id,
    ]);
    $plain = pendingTabAppointment(['created_at' => now()->subDays(400)]);

    $this->actingAs($admin)
        ->get(route('admin.laboratory-appointments.index', ['view' => 'pending']))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('laboratoryAppointments.data.0.id', $plain->id)
            ->where('laboratoryAppointments.data.1.id', $completedCartAppointment->id));
});

test('carrito de otro cliente no contamina la cita', function () {
    if (! \Illuminate\Support\Facades\Schema::hasColumn('laboratory_appointments', 'cart_id')) {
        test()->markTestSkipped('Sin columna cart_id en laboratory_appointments.');
    }

    $admin = pendingTabConciergeAdmin();
    $owner = User::factory()->withRegularCustomer()->create();
    $other = User::factory()->withRegularCustomer()->create();
    $otherCart = pendingTabLabCart($other, LaboratoryBrand::SWISSLAB);
    pendingTabCartUserEvent($otherCart, now()->subMinutes(5));

    $appointment = pendingTabAppointmentForCustomer($owner, [
        'cart_id' => $otherCart->id,
        'brand' => LaboratoryBrand::SWISSLAB,
    ]);

    $this->actingAs($admin)
        ->get(route('admin.laboratory-appointments.index', ['view' => 'pending']))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('laboratoryAppointments.data.0.pending_priority_tier', 4)
            ->where('laboratoryAppointments.data.0.id', $appointment->id));
});

test('carrito de otra marca no contamina la cita', function () {
    if (! \Illuminate\Support\Facades\Schema::hasColumn('laboratory_appointments', 'cart_id')) {
        test()->markTestSkipped('Sin columna cart_id en laboratory_appointments.');
    }

    $admin = pendingTabConciergeAdmin();
    $user = User::factory()->withRegularCustomer()->create();
    $olabCart = pendingTabLabCart($user, LaboratoryBrand::OLAB);
    pendingTabCartUserEvent($olabCart, now()->subMinutes(5));

    $appointment = pendingTabAppointmentForCustomer($user, [
        'cart_id' => $olabCart->id,
        'brand' => LaboratoryBrand::SWISSLAB,
    ]);

    $this->actingAs($admin)
        ->get(route('admin.laboratory-appointments.index', ['view' => 'pending']))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('laboratoryAppointments.data.0.pending_priority_tier', 4));
});

test('relacion legacy ambigua no asigna prioridad', function () {
    if (! \Illuminate\Support\Facades\Schema::hasColumn('laboratory_appointments', 'cart_id')) {
        test()->markTestSkipped('Sin columna cart_id en laboratory_appointments.');
    }

    $admin = pendingTabConciergeAdmin();
    $user = User::factory()->withRegularCustomer()->create();
    $firstCart = pendingTabLabCart($user, LaboratoryBrand::SWISSLAB, ['created_at' => now()->subHour()]);
    $secondCart = pendingTabLabCart($user, LaboratoryBrand::SWISSLAB, ['created_at' => now()->subMinutes(50)]);
    pendingTabCartUserEvent($firstCart, now()->subMinutes(30));
    pendingTabCartUserEvent($secondCart, now()->subMinutes(20));

    $appointment = pendingTabAppointmentForCustomer($user, [
        'cart_id' => null,
        'created_at' => now()->subMinutes(40),
    ]);

    $this->actingAs($admin)
        ->get(route('admin.laboratory-appointments.index', ['view' => 'pending']))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('laboratoryAppointments.data.0.pending_priority_tier', 4)
            ->where('laboratoryAppointments.data.0.id', $appointment->id));
});

test('filtro priority_filter recent limita a prioridad 1', function () {
    if (! \Illuminate\Support\Facades\Schema::hasColumn('laboratory_appointments', 'cart_id')) {
        test()->markTestSkipped('Sin columna cart_id en laboratory_appointments.');
    }

    $admin = pendingTabConciergeAdmin();

    $userRecent = User::factory()->withRegularCustomer()->create();
    $recentCart = pendingTabLabCart($userRecent, LaboratoryBrand::SWISSLAB);
    pendingTabCartUserEvent($recentCart, now()->subMinutes(10));
    $recent = pendingTabAppointmentForCustomer($userRecent, ['cart_id' => $recentCart->id]);

    pendingTabAppointment(['created_at' => now()->subDays(2)]);

    $this->actingAs($admin)
        ->get(route('admin.laboratory-appointments.index', [
            'view' => 'pending',
            'priority_filter' => 'recent',
        ]))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->has('laboratoryAppointments.data', 1)
            ->where('laboratoryAppointments.data.0.id', $recent->id));
});

test('pestaña citas permanece intacta con priority como default en pending', function () {
    $admin = pendingTabConciergeAdmin();
    $oldPending = pendingTabAppointment(['created_at' => now()->subDays(90)]);

    $this->actingAs($admin)
        ->get(route('admin.laboratory-appointments.index', [
            'view' => 'list',
            'date_range' => 'last_30_days',
        ]))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('filters.view', 'list')
            ->where('laboratoryAppointments.total', 0));

    $this->actingAs($admin)
        ->get(route('admin.laboratory-appointments.index', ['view' => 'pending']))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('filters.pending_sort', 'priority')
            ->where('laboratoryAppointments.data.0.id', $oldPending->id));
});
