<?php

use App\Enums\CartEventType;
use App\Enums\Gender;
use App\Enums\LaboratoryBrand;
use App\Enums\MonitoringCartStatus;
use App\Enums\MonitoringCartType;
use App\Exceptions\ActiveCampaignSyncException;
use App\Jobs\ActiveCampaign\DispatchActiveCampaignOutboundJob;
use App\Models\ActiveCampaignDispatch;
use App\Models\Cart;
use App\Models\CartEvent;
use App\Models\LaboratoryAppointment;
use App\Models\LaboratoryCartItem;
use App\Models\LaboratoryCheckoutDraft;
use App\Models\LaboratoryPurchase;
use App\Models\LaboratoryPurchaseItem;
use App\Models\LaboratoryStore;
use App\Models\LaboratoryTest;
use App\Models\User;
use App\Services\ActiveCampaign\ActiveCampaignOutboundDispatcher;
use App\Services\ActiveCampaign\ActiveCampaignService;
use App\Services\Laboratory\LabOrderNotificationGateService;
use App\Services\Monitoring\SyncMonitoringCartService;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;

beforeEach(function () {
    config([
        'app.url' => 'https://famedic.test',
        'services.activecampaign.enabled' => true,
        'services.activecampaign.cart_outbox_enabled' => true,
        'services.activecampaign.cart_site_events_enabled' => true,
        'services.activecampaign.cart_tag_remove_enabled' => true,
        'services.activecampaign.cart_appointment_signals_enabled' => true,
        'services.activecampaign.endpoint' => 'https://ac.test',
        'services.activecampaign.token' => 'token-test',
        'services.activecampaign.account_id' => '12345',
        'services.activecampaign.event_key' => 'event-key-test',
        'services.activecampaign.tag_laboratory_purchase_completed' => 18,
        'services.activecampaign.fields.lab.url_finalizar_compra' => 101,
        'services.activecampaign.fields.lab.paciente_lab' => 102,
        'services.activecampaign.fields.lab.sucursal_lab' => 103,
        'services.activecampaign.fields.lab.google_maps_lab' => 104,
        'services.activecampaign.fields.lab.direccion_lab' => 105,
        'services.activecampaign.fields.lab.fecha_cita_lab' => 106,
        'services.activecampaign.fields.lab.horario_cita_lab' => 107,
        'services.activecampaign.fields.lab.folio_famedic' => 108,
        'services.activecampaign.fields.lab.gda_consecutivo' => 109,
        'services.activecampaign.fields.lab.mapa_sucursales_labs' => 110,
        'services.activecampaign.fields.lab.toma_de_muestra_lab' => 111,
        'services.activecampaign.fields.lab.resultados_lab' => 112,
    ]);
});

function phase3LabUser(array $attributes = []): User
{
    return User::factory()
        ->withRegularCustomer()
        ->withCompleteProfile()
        ->create(array_merge([
            'documentation_accepted_at' => now(),
        ], $attributes))
        ->fresh(['customer']);
}

function phase3ActiveLabCart(User $user, LaboratoryBrand $brand = LaboratoryBrand::OLAB): Cart
{
    $test = LaboratoryTest::factory()->create([
        'brand' => $brand->value,
        'requires_appointment' => false,
        'famedic_price_cents' => 50000,
    ]);

    LaboratoryCartItem::factory()->create([
        'customer_id' => $user->customer->id,
        'laboratory_test_id' => $test->id,
    ]);

    app(SyncMonitoringCartService::class)->syncLaboratory($user->customer);

    return app(SyncMonitoringCartService::class)
        ->activeLaboratoryCart($user->customer->fresh(), $brand)
        ->fresh(['items']);
}

function phase3CartEvent(Cart $cart, CartEventType $type, array $metadata = []): CartEvent
{
    return CartEvent::query()->create([
        'cart_id' => $cart->id,
        'event' => $type->value,
        'source' => 'test',
        'metadata' => $metadata,
        'idempotency_key' => fake()->uuid(),
        'occurred_at' => now(),
    ]);
}

function phase3LabPurchase(?User $user = null, ?Cart $cart = null, int $items = 1): LaboratoryPurchase
{
    $user ??= phase3LabUser(['email' => 'lab-phase3@example.com']);
    $cart ??= Cart::query()->create([
        'user_id' => $user->id,
        'type' => MonitoringCartType::Lab->value,
        'status' => MonitoringCartStatus::Completed->value,
        'total' => 1500,
    ]);

    $purchase = LaboratoryPurchase::query()->create([
        'brand' => LaboratoryBrand::OLAB->value,
        'gda_order_id' => 'GDA-PHASE3-'.fake()->unique()->numberBetween(1000, 9999),
        'gda_consecutivo' => fake()->unique()->numberBetween(10000, 99999),
        'name' => 'Paciente',
        'paternal_lastname' => 'Uno',
        'maternal_lastname' => 'Dos',
        'phone' => '8111111111',
        'phone_country' => 'MX',
        'birth_date' => '1990-01-01',
        'gender' => Gender::MALE->value,
        'street' => 'Calle',
        'number' => '123',
        'neighborhood' => 'Centro',
        'state' => 'Nuevo Leon',
        'city' => 'Monterrey',
        'zipcode' => '64000',
        'total_cents' => 150000,
        'status' => 'pending',
        'paid_at' => now(),
        'customer_id' => $user->customer->id,
        'cart_id' => $cart->id,
    ]);

    LaboratoryPurchaseItem::factory()
        ->count($items)
        ->create(['laboratory_purchase_id' => $purchase->id]);

    return $purchase->fresh(['customer.user', 'cart', 'laboratoryPurchaseItems']);
}

function phase3Store(): LaboratoryStore
{
    return LaboratoryStore::query()->create([
        'name' => 'Sucursal Centro',
        'brand' => LaboratoryBrand::OLAB->value,
        'state' => 'Nuevo Leon',
        'address' => 'Av. Siempre Viva 123, Monterrey',
        'weekly_hours' => '08:00-18:00',
        'saturday_hours' => '08:00-13:00',
        'sunday_hours' => 'Cerrado',
        'google_maps_url' => 'https://maps.example/sucursal-centro',
    ]);
}

function phase3GatePayload(string $study, string $acuse): array
{
    return [
        'id' => 'GDA-PHASE3',
        'status' => 'completed',
        'code' => [
            'coding' => [[
                'code' => $study,
                'display' => 'Study '.$study,
            ]],
        ],
        'GDA_menssage' => [
            'acuse' => $acuse,
        ],
    ];
}

it('freezes checkout resume URL in abandoned cart lab field payload', function () {
    Queue::fake();

    $user = phase3LabUser(['email' => 'abandoned-phase3@example.com']);
    $cart = phase3ActiveLabCart($user);
    LaboratoryCheckoutDraft::query()->create([
        'customer_id' => $user->customer->id,
        'laboratory_brand' => LaboratoryBrand::OLAB->value,
        'checkout_step' => 'payment',
        'payment_method' => 'paypal',
    ]);
    $event = phase3CartEvent($cart, CartEventType::CartAbandoned, ['episode' => 1]);

    app(ActiveCampaignOutboundDispatcher::class)->enqueueFromCartEvent($cart, $event);

    $dispatch = ActiveCampaignDispatch::query()
        ->where('idempotency_key', "cart:{$cart->id}:abandoned:episode:1:lab_fields")
        ->firstOrFail();

    $fields = $dispatch->payload['custom_fields'];

    expect($fields['url_finalizar_compra'])->toContain('/laboratory/checkout/resume/')
        ->and($fields['url_finalizar_compra'])->not->toContain('contact=')
        ->and($fields['url_finalizar_compra'])->not->toContain('address=')
        ->and($fields['url_finalizar_compra'])->not->toContain('customer=')
        ->and($fields['url_finalizar_compra'])->not->toContain('cart=')
        ->and($fields['mapa_sucursales_labs'])->toBe(route('laboratory-stores.index', ['brand' => LaboratoryBrand::OLAB->value]));

    $url = $fields['url_finalizar_compra'];
    $service = Mockery::mock(ActiveCampaignService::class);
    $service->shouldReceive('handleOutboundLaboratoryCustomFields')
        ->once()
        ->with(Mockery::on(fn (array $payload) => $payload['custom_fields']['url_finalizar_compra'] === $url))
        ->ordered()
        ->andThrow(new ActiveCampaignSyncException('AC timeout'));
    $service->shouldReceive('handleOutboundLaboratoryCustomFields')
        ->once()
        ->with(Mockery::on(fn (array $payload) => $payload['custom_fields']['url_finalizar_compra'] === $url))
        ->ordered()
        ->andReturnNull();

    expect(fn () => (new DispatchActiveCampaignOutboundJob($dispatch->id))->handle($service))
        ->toThrow(ActiveCampaignSyncException::class);

    expect(fn () => (new DispatchActiveCampaignOutboundJob($dispatch->id))->handle($service))
        ->not->toThrow(ActiveCampaignSyncException::class);

    expect($dispatch->fresh()->payload['custom_fields']['url_finalizar_compra'])->toBe($url);
});

it('purchase completed payload uses GDA identifiers and clears checkout URL field', function () {
    Queue::fake();

    $purchase = phase3LabPurchase();

    app(ActiveCampaignOutboundDispatcher::class)->enqueueLaboratoryPurchaseCompleted($purchase);

    $dispatch = ActiveCampaignDispatch::query()
        ->where('idempotency_key', "laboratory_purchase:{$purchase->id}:purchase_completed")
        ->firstOrFail();

    expect($dispatch->payload['custom_fields']['folio_famedic'])->toBe($purchase->gda_order_id)
        ->and($dispatch->payload['custom_fields']['gda_consecutivo'])->toBe((string) $purchase->gda_consecutivo)
        ->and($dispatch->payload['custom_fields']['url_finalizar_compra'])->toBe('')
        ->and($dispatch->payload['custom_fields'])->not->toHaveKey('lista_estudios_lab');
});

it('appointment confirmed payload includes appointment fields without sample or results fields', function () {
    Queue::fake();

    $user = phase3LabUser(['email' => 'appointment-phase3@example.com']);
    $cart = phase3ActiveLabCart($user);
    $store = phase3Store();
    $appointmentDate = now()->addDays(3)->setTime(9, 30);
    $appointment = LaboratoryAppointment::factory()->create([
        'customer_id' => $user->customer->id,
        'cart_id' => $cart->id,
        'laboratory_store_id' => $store->id,
        'brand' => LaboratoryBrand::OLAB->value,
        'patient_name' => 'Ana',
        'patient_paternal_lastname' => 'Lopez',
        'patient_maternal_lastname' => 'Rios',
        'appointment_date' => $appointmentDate,
        'confirmed_at' => now(),
    ]);
    $event = phase3CartEvent($cart, CartEventType::AppointmentConfirmed, [
        'appointment_id' => $appointment->id,
        'brand' => LaboratoryBrand::OLAB->value,
    ]);

    app(ActiveCampaignOutboundDispatcher::class)->enqueueFromCartEvent($cart, $event);

    $dispatch = ActiveCampaignDispatch::query()
        ->where('idempotency_key', "appointment:{$appointment->id}:confirmed:lab_fields")
        ->firstOrFail();
    $fields = $dispatch->payload['custom_fields'];

    expect($fields['paciente_lab'])->toBe('Ana Lopez Rios')
        ->and($fields['sucursal_lab'])->toBe('Sucursal Centro')
        ->and($fields['google_maps_lab'])->toBe('https://maps.example/sucursal-centro')
        ->and($fields['direccion_lab'])->toBe('Av. Siempre Viva 123, Monterrey')
        ->and($fields['fecha_cita_lab'])->toBe($appointmentDate->format('Y-m-d'))
        ->and($fields['horario_cita_lab'])->toBe($appointmentDate->format('h:i A'))
        ->and($fields)->not->toHaveKey('toma_de_muestra_lab')
        ->and($fields)->not->toHaveKey('resultados_lab')
        ->and($fields)->not->toHaveKey('lista_estudios_lab');
});

it('sample and results lab fields dispatch only after gates are complete and ignore duplicate final callbacks', function () {
    Queue::fake();

    $purchase = phase3LabPurchase(items: 3);
    $gate = app(LabOrderNotificationGateService::class);

    foreach (['A', 'B'] as $study) {
        $result = $gate->registerEvent(
            gdaOrderId: $purchase->gda_order_id,
            eventType: LabOrderNotificationGateService::EVENT_SAMPLE,
            purchase: $purchase,
            studyExternalId: $study,
            providerEventId: 'sample-'.$study,
            payload: phase3GatePayload($study, 'sample-'.$study),
        );

        expect($result['should_send_sample_email'])->toBeFalse();
    }

    expect(ActiveCampaignDispatch::query()->where('event_type', 'laboratory_sample_completed')->count())->toBe(0);

    $result = $gate->registerEvent(
        gdaOrderId: $purchase->gda_order_id,
        eventType: LabOrderNotificationGateService::EVENT_SAMPLE,
        purchase: $purchase,
        studyExternalId: 'C',
        providerEventId: 'sample-C',
        payload: phase3GatePayload('C', 'sample-C'),
    );

    expect($result['should_send_sample_email'])->toBeTrue()
        ->and($gate->sendSampleOnce($purchase->gda_order_id, fn () => app(ActiveCampaignOutboundDispatcher::class)->enqueueLaboratorySampleCompleted($purchase)))->toBeTrue()
        ->and($gate->sendSampleOnce($purchase->gda_order_id, fn () => app(ActiveCampaignOutboundDispatcher::class)->enqueueLaboratorySampleCompleted($purchase)))->toBeFalse()
        ->and(ActiveCampaignDispatch::query()->where('event_type', 'laboratory_sample_completed')->count())->toBe(1);

    $sample = ActiveCampaignDispatch::query()->where('event_type', 'laboratory_sample_completed')->firstOrFail();
    expect($sample->payload['custom_fields'])->toBe(['toma_de_muestra_lab' => 'Sí']);

    foreach (['A', 'B', 'C'] as $study) {
        $gate->registerEvent(
            gdaOrderId: $purchase->gda_order_id,
            eventType: LabOrderNotificationGateService::EVENT_RESULTS,
            purchase: $purchase,
            studyExternalId: $study,
            providerEventId: 'result-'.$study,
            payload: phase3GatePayload($study, 'result-'.$study),
        );
    }

    expect($gate->sendResultsOnce($purchase->gda_order_id, fn () => app(ActiveCampaignOutboundDispatcher::class)->enqueueLaboratoryResultsCompleted($purchase)))->toBeTrue()
        ->and($gate->sendResultsOnce($purchase->gda_order_id, fn () => app(ActiveCampaignOutboundDispatcher::class)->enqueueLaboratoryResultsCompleted($purchase)))->toBeFalse()
        ->and(ActiveCampaignDispatch::query()->where('event_type', 'laboratory_results_completed')->count())->toBe(1);

    $results = ActiveCampaignDispatch::query()->where('event_type', 'laboratory_results_completed')->firstOrFail();
    expect($results->payload['custom_fields'])->toBe(['resultados_lab' => 'Disponibles']);
});

it('omits unconfigured lab field ids and updates configured fields', function () {
    config(['services.activecampaign.fields.lab.google_maps_lab' => null]);

    Http::fake([
        'https://ac.test/api/3/contacts*' => Http::response([
            'contacts' => [['id' => 42, 'email' => 'fields-phase3@example.com']],
        ], 200),
        'https://ac.test/api/3/fieldValues' => Http::response(['fieldValue' => ['id' => 1]], 201),
    ]);

    app(ActiveCampaignService::class)->handleOutboundLaboratoryCustomFields([
        'email' => 'fields-phase3@example.com',
        'event_type' => 'appointment_confirmed',
        'custom_fields' => [
            'google_maps_lab' => 'https://maps.example/skipped',
            'paciente_lab' => 'Ana Lopez',
        ],
    ]);

    Http::assertSentCount(2);
    Http::assertSent(fn ($request) => $request->url() === 'https://ac.test/api/3/fieldValues'
        && (int) data_get($request->data(), 'fieldValue.field') === 102
        && data_get($request->data(), 'fieldValue.value') === 'Ana Lopez');
});

it('marks lab custom field dispatch as failed on ActiveCampaign 500 without blocking the flow', function () {
    Http::fake([
        'https://ac.test/api/3/contacts*' => Http::response([
            'contacts' => [['id' => 42, 'email' => 'failed-phase3@example.com']],
        ], 200),
        'https://ac.test/api/3/fieldValues' => Http::response(['message' => 'server error'], 500),
    ]);

    $dispatch = ActiveCampaignDispatch::query()->create([
        'event_type' => 'appointment_confirmed',
        'entity_type' => 'laboratory_appointment',
        'entity_id' => 1,
        'email' => 'failed-phase3@example.com',
        'idempotency_key' => 'appointment:1:confirmed:lab_fields',
        'status' => ActiveCampaignDispatch::STATUS_PENDING,
        'payload' => [
            'operation' => 'lab_custom_fields',
            'event_type' => 'appointment_confirmed',
            'email' => 'failed-phase3@example.com',
            'custom_fields' => [
                'paciente_lab' => 'Ana Lopez',
            ],
        ],
    ]);

    expect(fn () => (new DispatchActiveCampaignOutboundJob($dispatch->id))->handle(app(ActiveCampaignService::class)))
        ->toThrow(ActiveCampaignSyncException::class);

    expect($dispatch->fresh()->status)->toBe(ActiveCampaignDispatch::STATUS_FAILED)
        ->and($dispatch->fresh()->attempts)->toBe(1);
});
