<?php

use App\Enums\LaboratoryBrand;
use App\Enums\MonitoringCartStatus;
use App\Enums\MonitoringCartType;
use App\Exports\Carts\CartItemsSheet;
use App\Exports\Carts\CartsSheet;
use App\Exports\Carts\CartsSummarySheet;
use App\Exports\CartsExport;
use App\Jobs\ProcessCartsSpreadsheetExport;
use App\Models\Address;
use App\Models\Cart;
use App\Models\CartItem;
use App\Models\Contact;
use App\Models\LaboratoryAppointment;
use App\Models\LaboratoryCheckoutDraft;
use App\Models\LaboratoryPurchase;
use App\Models\LaboratoryStore;
use App\Models\LaboratoryTest;
use App\Models\PaymentAttempt;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Support\Facades\Queue;
use Spatie\Permission\Models\Permission;

function cartsExportAdmin(): User
{
    Permission::findOrCreate('view carts', 'web');

    $user = User::factory()->withAdministrator()->create();
    $user->administrator->givePermissionTo('view carts');

    return $user;
}

function cartsExportUser(): User
{
    return User::factory()->withRegularCustomer()->create();
}

function cartsExportCart(User $user, LaboratoryBrand $brand, array $attributes = [], array $tests = []): Cart
{
    $tests = $tests ?: [
        LaboratoryTest::factory()->create([
            'brand' => $brand->value,
            'name' => 'Estudio '.$brand->value,
            'famedic_price_cents' => 120000,
            'requires_appointment' => true,
        ]),
    ];

    $cart = Cart::query()->create(array_merge([
        'user_id' => $user->id,
        'type' => MonitoringCartType::Lab->value,
        'status' => MonitoringCartStatus::Active->value,
        'total' => collect($tests)->sum(fn (LaboratoryTest $test) => numberCents($test->famedic_price_cents)),
        'created_at' => now()->subDays(2),
        'updated_at' => now()->subHours(3),
    ], $attributes));

    foreach ($tests as $test) {
        CartItem::query()->create([
            'cart_id' => $cart->id,
            'product_id' => (string) $test->id,
            'name' => $test->name,
            'price' => numberCents($test->famedic_price_cents),
            'quantity' => 1,
        ]);
    }

    return $cart;
}

function cartsExportRows(array $filters = []): array
{
    return iterator_to_array((new CartsSheet(CartsExport::normalizeFilters($filters)))->generator(), false);
}

function cartsExportItemsRows(array $filters = []): array
{
    return iterator_to_array((new CartItemsSheet(CartsExport::normalizeFilters($filters)))->generator(), false);
}

function cartsExportAssoc(array $row): array
{
    return array_combine((new CartsSheet)->headings(), $row);
}

it('exports one cart row per brand and individual rows in estudios sheet', function () {
    $user = cartsExportUser();
    $olabA = LaboratoryTest::factory()->create(['brand' => LaboratoryBrand::OLAB->value, 'name' => 'OLAB A', 'famedic_price_cents' => 100000]);
    $olabB = LaboratoryTest::factory()->create(['brand' => LaboratoryBrand::OLAB->value, 'name' => 'OLAB B', 'famedic_price_cents' => 200000]);
    $swiss = LaboratoryTest::factory()->create(['brand' => LaboratoryBrand::SWISSLAB->value, 'name' => 'Swiss C', 'famedic_price_cents' => 90000]);

    $olab = cartsExportCart($user, LaboratoryBrand::OLAB, [], [$olabA, $olabB]);
    $swisslab = cartsExportCart($user, LaboratoryBrand::SWISSLAB, ['total' => 900.00], [$swiss]);

    $rows = collect(cartsExportRows());
    $byId = $rows->mapWithKeys(fn (array $row) => [cartsExportAssoc($row)['ID carrito'] => cartsExportAssoc($row)]);
    $itemRows = collect(cartsExportItemsRows());

    expect($rows)->toHaveCount(2)
        ->and($byId[$olab->id]['Marca'])->toBe(LaboratoryBrand::OLAB->label())
        ->and($byId[$swisslab->id]['Marca'])->toBe(LaboratoryBrand::SWISSLAB->label())
        ->and($byId[$olab->id]['Estudios'])->toBe('OLAB A | OLAB B')
        ->and($itemRows)->toHaveCount(3)
        ->and($itemRows->pluck(0)->unique()->sort()->values()->all())->toBe([$olab->id, $swisslab->id]);
});

it('exports appointment, callback, phone intent and checkout fields from the same cart brand', function () {
    $user = cartsExportUser();
    $cart = cartsExportCart($user, LaboratoryBrand::OLAB);
    $store = LaboratoryStore::factory()->create([
        'name' => 'Sucursal Centro',
        'brand' => LaboratoryBrand::OLAB->value,
        'state' => 'Nuevo Leon',
        'address' => 'Av. Centro 123',
        'weekly_hours' => '8:00-18:00',
        'saturday_hours' => '8:00-13:00',
        'sunday_hours' => 'Cerrado',
        'google_maps_url' => 'https://maps.example/store',
    ]);
    $contact = Contact::factory()->create(['customer_id' => $user->customer->id]);
    $address = Address::factory()->create(['customer_id' => $user->customer->id]);

    LaboratoryCheckoutDraft::query()->create([
        'customer_id' => $user->customer->id,
        'laboratory_brand' => LaboratoryBrand::OLAB->value,
        'contact_id' => $contact->id,
        'address_id' => $address->id,
        'payment_method' => 'card',
        'checkout_step' => 'payment',
    ]);
    LaboratoryAppointment::factory()->create([
        'customer_id' => $user->customer->id,
        'cart_id' => $cart->id,
        'brand' => LaboratoryBrand::OLAB->value,
        'laboratory_store_id' => $store->id,
        'appointment_date' => now()->addDay(),
        'phone_call_intent_at' => now()->subHour(),
        'callback_availability_starts_at' => now()->addHours(2),
        'callback_availability_ends_at' => now()->addHours(4),
        'patient_callback_comment' => '  Prefiere contacto por la tarde   con espacios   ',
        'confirmed_at' => null,
    ]);

    $row = cartsExportAssoc(cartsExportRows()[0]);

    expect($row['Etapa checkout'])->toBe('Pago')
        ->and($row['Avance checkout'])->toBe('3/5')
        ->and($row['Paciente seleccionado'])->toBe('Si')
        ->and($row['Direccion seleccionada'])->toBe('Si')
        ->and($row['Metodo de pago seleccionado'])->toBe('Si')
        ->and($row['Tiene cita'])->toBe('Si')
        ->and($row['Estado cita'])->toBe('Pendiente')
        ->and($row['Sucursal'])->toBe('Sucursal Centro')
        ->and($row['Intento llamar'])->toBe('Si')
        ->and($row['Solicito llamada'])->toBe('Si')
        ->and($row['Comentario callback'])->toBe('Prefiere contacto por la tarde con espacios');
});

it('exports explicit declined and error payment attempts without sensitive fields', function () {
    $user = cartsExportUser();
    $declined = cartsExportCart($user, LaboratoryBrand::OLAB, ['total' => 500.00]);
    $error = cartsExportCart($user, LaboratoryBrand::SWISSLAB, ['total' => 700.00]);

    PaymentAttempt::query()->create([
        'customer_id' => $user->customer->id,
        'cart_id' => $declined->id,
        'amount_cents' => 50000,
        'gateway' => 'efevoopay',
        'status' => PaymentAttempt::STATUS_DECLINED,
        'processor_code' => 'DECLINED-01',
        'processor_message' => 'card token tok_123 was declined',
        'raw_response' => ['card_token' => 'tok_123'],
        'processor_transaction_id' => 'secret-transaction',
        'processed_at' => now()->subMinutes(10),
    ]);
    PaymentAttempt::query()->create([
        'customer_id' => $user->customer->id,
        'cart_id' => $error->id,
        'amount_cents' => 70000,
        'gateway' => 'efevoopay',
        'status' => PaymentAttempt::STATUS_ERROR,
        'processor_code' => 'TIMEOUT',
        'processor_message' => 'timeout from processor',
        'processed_at' => now()->subMinutes(5),
    ]);

    $rows = collect(cartsExportRows())->map(fn (array $row) => cartsExportAssoc($row))->keyBy('ID carrito');

    expect($rows[$declined->id]['Estado ultimo intento'])->toBe('Rechazado')
        ->and($rows[$declined->id]['Tipo correlacion pago'])->toBe('Explicita')
        ->and($rows[$declined->id]['Mensaje pago'])->toBe('Transacción rechazada')
        ->and($rows[$error->id]['Estado ultimo intento'])->toBe('Error tecnico')
        ->and($rows[$error->id]['Mensaje pago'])->toBe('Tiempo de espera agotado')
        ->and(json_encode($rows->all()))->not->toContain('tok_123')
        ->and(json_encode($rows->all()))->not->toContain('secret-transaction');
});

it('exports reliable legacy payments and does not assert ambiguous payments', function () {
    $user = cartsExportUser();
    $legacy = cartsExportCart($user, LaboratoryBrand::OLAB, [
        'total' => 300.00,
        'created_at' => now()->subHours(3),
        'updated_at' => now()->subHours(2),
    ]);
    $ambiguousA = cartsExportCart($user, LaboratoryBrand::SWISSLAB, [
        'total' => 900.00,
        'created_at' => now()->subHour(),
        'updated_at' => now(),
    ]);
    $ambiguousB = cartsExportCart($user, LaboratoryBrand::LIACSA, [
        'total' => 900.00,
        'created_at' => now()->subHour(),
        'updated_at' => now(),
    ]);

    PaymentAttempt::query()->forceCreate([
        'customer_id' => $user->customer->id,
        'cart_id' => null,
        'amount_cents' => 30000,
        'gateway' => 'efevoopay',
        'status' => PaymentAttempt::STATUS_APPROVED,
        'processed_at' => now()->subHours(2)->addMinutes(20),
        'created_at' => now()->subHours(2)->addMinutes(20),
        'updated_at' => now()->subHours(2)->addMinutes(20),
    ]);
    PaymentAttempt::query()->forceCreate([
        'customer_id' => $user->customer->id,
        'cart_id' => null,
        'amount_cents' => 90000,
        'gateway' => 'efevoopay',
        'status' => PaymentAttempt::STATUS_ERROR,
        'processed_at' => now()->subMinutes(20),
        'created_at' => now()->subMinutes(20),
        'updated_at' => now()->subMinutes(20),
    ]);

    $rows = collect(cartsExportRows())->map(fn (array $row) => cartsExportAssoc($row))->keyBy('ID carrito');

    expect($rows[$legacy->id]['Estado ultimo intento'])->toBe('Aprobado')
        ->and($rows[$legacy->id]['Tipo correlacion pago'])->toBe('Legacy confiable')
        ->and($rows[$ambiguousA->id]['Estado ultimo intento'])->toBe('No determinada')
        ->and($rows[$ambiguousA->id]['Tipo correlacion pago'])->toBe('No determinada')
        ->and($rows[$ambiguousB->id]['Estado ultimo intento'])->toBe('No determinada');
});

it('respects filters and uses the default seven day export period', function () {
    Carbon::setTestNow(Carbon::parse('2026-08-20 12:00:00', 'America/Monterrey'));
    Queue::fake();
    $admin = cartsExportAdmin();
    $match = cartsExportCart(cartsExportUser(), LaboratoryBrand::OLAB, ['updated_at' => now()->subDay()]);
    cartsExportCart(cartsExportUser(), LaboratoryBrand::SWISSLAB, ['updated_at' => now()->subDay()]);
    cartsExportCart(cartsExportUser(), LaboratoryBrand::OLAB, ['updated_at' => now()->subDays(10)]);

    $filteredRows = collect(cartsExportRows(['brand' => LaboratoryBrand::OLAB->value]));
    $summary = collect((new CartsSummarySheet(CartsExport::normalizeFilters(['brand' => LaboratoryBrand::OLAB->value])))->array())
        ->mapWithKeys(fn (array $row) => [$row[0] => $row[1]]);

    $this->actingAs($admin)->post(route('admin.carts.export'), [
        'brand' => LaboratoryBrand::OLAB->value,
    ]);

    Queue::assertPushed(ProcessCartsSpreadsheetExport::class, fn (ProcessCartsSpreadsheetExport $job) => $job->filters['brand'] === LaboratoryBrand::OLAB->value
        && $job->filters['start_date'] === '2026-08-14'
        && $job->filters['end_date'] === '2026-08-20');

    expect($filteredRows)->toHaveCount(1)
        ->and(cartsExportAssoc($filteredRows->first())['ID carrito'])->toBe($match->id)
        ->and($summary['Periodo utilizado'])->toBe('Ultimos 7 dias: 2026-08-14 a 2026-08-20')
        ->and($summary['Total carritos'])->toBe(1);
});
