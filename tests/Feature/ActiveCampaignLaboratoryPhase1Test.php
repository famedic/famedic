<?php

use App\DTOs\Orders\OrderAutomationContext;
use App\Enums\Gender;
use App\Enums\LaboratoryBrand;
use App\Enums\MonitoringCartStatus;
use App\Enums\MonitoringCartType;
use App\Exceptions\ActiveCampaignSyncException;
use App\Jobs\ActiveCampaign\DispatchActiveCampaignOutboundJob;
use App\Jobs\SendSampleCollectedToActiveCampaignJob;
use App\Models\ActiveCampaignDispatch;
use App\Models\Cart;
use App\Models\LaboratoryAppointment;
use App\Models\LaboratoryPurchase;
use App\Models\LaboratoryPurchaseItem;
use App\Models\User;
use App\Services\ActiveCampaign\ActiveCampaignService;
use App\Services\Laboratory\LabOrderNotificationGateService;
use App\Services\Orders\Drivers\ActiveCampaignOrderDriver;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;

beforeEach(function () {
    config([
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
        'services.activecampaign.tag_lab_sample_collected' => 32,
        'services.activecampaign.tag_lab_results_available' => 33,
    ]);
});

function phase1LabUser(array $attributes = []): User
{
    return User::factory()
        ->withRegularCustomer()
        ->withCompleteProfile()
        ->create($attributes);
}

function phase1LabCart(User $user): Cart
{
    return Cart::query()->create([
        'user_id' => $user->id,
        'type' => MonitoringCartType::Lab->value,
        'status' => MonitoringCartStatus::Active->value,
        'total' => 1500,
    ]);
}

function phase1LabPurchase(int $items = 1, ?User $user = null): LaboratoryPurchase
{
    $user ??= phase1LabUser(['email' => 'lab-phase1@example.com']);

    $purchase = LaboratoryPurchase::query()->create([
        'brand' => LaboratoryBrand::OLAB->value,
        'gda_order_id' => 'GDA-PHASE1-'.fake()->unique()->numberBetween(1000, 9999),
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
    ]);

    LaboratoryPurchaseItem::factory()
        ->count($items)
        ->create(['laboratory_purchase_id' => $purchase->id]);

    return $purchase->fresh(['customer.user', 'laboratoryPurchaseItems']);
}

function phase1GatePayload(string $study, string $acuse): array
{
    return [
        'id' => 'GDA-PHASE1',
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

it('confirmation emits appointment confirmed but does not emit sample collected', function () {
    Queue::fake();

    $user = phase1LabUser(['email' => 'appointment@example.com']);
    $cart = phase1LabCart($user);
    $appointment = LaboratoryAppointment::factory()->create([
        'customer_id' => $user->customer->id,
        'cart_id' => $cart->id,
        'brand' => LaboratoryBrand::OLAB->value,
        'confirmed_at' => null,
    ]);

    $appointment->update(['confirmed_at' => now()]);

    Queue::assertNotPushed(SendSampleCollectedToActiveCampaignJob::class);

    expect(ActiveCampaignDispatch::query()
        ->where('idempotency_key', "appointment:{$appointment->id}:confirmed:site_event")
        ->exists())->toBeTrue();
});

it('sample collection completes only when all expected studies are received and ignores duplicates', function () {
    $purchase = phase1LabPurchase(items: 3);
    $gate = app(LabOrderNotificationGateService::class);
    $sent = 0;

    foreach (['A', 'B'] as $index => $study) {
        $result = $gate->registerEvent(
            gdaOrderId: $purchase->gda_order_id,
            eventType: LabOrderNotificationGateService::EVENT_SAMPLE,
            purchase: $purchase,
            studyExternalId: $study,
            providerEventId: 'sample-'.$study,
            payload: phase1GatePayload($study, 'sample-'.$study),
        );

        expect($result['should_send_sample_email'])->toBeFalse()
            ->and($result['state']->sample_received_count)->toBe($index + 1);
    }

    $result = $gate->registerEvent(
        gdaOrderId: $purchase->gda_order_id,
        eventType: LabOrderNotificationGateService::EVENT_SAMPLE,
        purchase: $purchase,
        studyExternalId: 'C',
        providerEventId: 'sample-C',
        payload: phase1GatePayload('C', 'sample-C'),
    );

    expect($result['should_send_sample_email'])->toBeTrue();
    expect($gate->sendSampleOnce($purchase->gda_order_id, function () use (&$sent) {
        $sent++;
    }))->toBeTrue();

    $duplicate = $gate->registerEvent(
        gdaOrderId: $purchase->gda_order_id,
        eventType: LabOrderNotificationGateService::EVENT_SAMPLE,
        purchase: $purchase,
        studyExternalId: 'C',
        providerEventId: 'sample-C',
        payload: phase1GatePayload('C', 'sample-C'),
    );

    expect($duplicate['is_new_event'])->toBeFalse()
        ->and($gate->sendSampleOnce($purchase->gda_order_id, function () use (&$sent) {
            $sent++;
        }))->toBeFalse()
        ->and($sent)->toBe(1);
});

it('results complete for one expected study after one result', function () {
    $purchase = phase1LabPurchase(items: 1);
    $gate = app(LabOrderNotificationGateService::class);

    $result = $gate->registerEvent(
        gdaOrderId: $purchase->gda_order_id,
        eventType: LabOrderNotificationGateService::EVENT_RESULTS,
        purchase: $purchase,
        studyExternalId: 'A',
        providerEventId: 'result-A',
        payload: phase1GatePayload('A', 'result-A'),
    );

    expect($result['should_send_results_email'])->toBeTrue()
        ->and($gate->areResultsComplete($result['state']))->toBeTrue();
});

it('results for multiple studies notify only on the final expected result', function () {
    $purchase = phase1LabPurchase(items: 3);
    $gate = app(LabOrderNotificationGateService::class);

    foreach (['A', 'B'] as $study) {
        $result = $gate->registerEvent(
            gdaOrderId: $purchase->gda_order_id,
            eventType: LabOrderNotificationGateService::EVENT_RESULTS,
            purchase: $purchase,
            studyExternalId: $study,
            providerEventId: 'result-'.$study,
            payload: phase1GatePayload($study, 'result-'.$study),
        );

        expect($result['should_send_results_email'])->toBeFalse()
            ->and($gate->areResultsComplete($result['state']))->toBeFalse();
    }

    $result = $gate->registerEvent(
        gdaOrderId: $purchase->gda_order_id,
        eventType: LabOrderNotificationGateService::EVENT_RESULTS,
        purchase: $purchase,
        studyExternalId: 'C',
        providerEventId: 'result-C',
        payload: phase1GatePayload('C', 'result-C'),
    );

    expect($result['should_send_results_email'])->toBeTrue()
        ->and($gate->areResultsComplete($result['state']))->toBeTrue();
});

it('duplicate final result does not trigger external notification twice', function () {
    $purchase = phase1LabPurchase(items: 3);
    $gate = app(LabOrderNotificationGateService::class);
    $sent = 0;

    foreach (['A', 'B', 'C'] as $study) {
        $gate->registerEvent(
            gdaOrderId: $purchase->gda_order_id,
            eventType: LabOrderNotificationGateService::EVENT_RESULTS,
            purchase: $purchase,
            studyExternalId: $study,
            providerEventId: 'result-'.$study,
            payload: phase1GatePayload($study, 'result-'.$study),
        );
    }

    expect($gate->sendResultsOnce($purchase->gda_order_id, function () use (&$sent) {
        $sent++;
    }))->toBeTrue();

    $duplicate = $gate->registerEvent(
        gdaOrderId: $purchase->gda_order_id,
        eventType: LabOrderNotificationGateService::EVENT_RESULTS,
        purchase: $purchase,
        studyExternalId: 'C',
        providerEventId: 'result-C',
        payload: phase1GatePayload('C', 'result-C'),
    );

    expect($duplicate['is_new_event'])->toBeFalse()
        ->and($gate->sendResultsOnce($purchase->gda_order_id, function () use (&$sent) {
            $sent++;
        }))->toBeFalse()
        ->and($sent)->toBe(1);
});

it('completed laboratory purchase creates exactly one ActiveCampaign outbox dispatch and status changes do not duplicate it', function () {
    Queue::fake();

    $purchase = phase1LabPurchase(items: 2);
    $context = new OrderAutomationContext(
        order: $purchase,
        customer: $purchase->customer,
        transaction: null,
        paymentAttempt: null,
        laboratoryPurchase: $purchase,
        pharmacyOrder: null,
        membership: null,
        amountCents: $purchase->total_cents,
        reference: null,
        gateway: null,
        createdAt: now(),
        channel: OrderAutomationContext::CHANNEL_LABORATORY,
    );

    app(ActiveCampaignOrderDriver::class)->handleLaboratoryOrder($context);
    app(ActiveCampaignOrderDriver::class)->handleLaboratoryOrder($context);

    $purchase->update(['status' => 'completed']);

    expect(ActiveCampaignDispatch::query()
        ->where('idempotency_key', "laboratory_purchase:{$purchase->id}:purchase_completed")
        ->count())->toBe(1);
});

it('ActiveCampaign outage leaves dispatch failed without creating a second purchase signal', function () {
    $purchase = phase1LabPurchase(items: 1);

    $dispatch = ActiveCampaignDispatch::query()->create([
        'event_type' => 'laboratory_purchase_completed',
        'entity_type' => 'laboratory_purchase',
        'entity_id' => $purchase->id,
        'customer_id' => $purchase->customer_id,
        'email' => $purchase->customer->user->email,
        'idempotency_key' => "laboratory_purchase:{$purchase->id}:purchase_completed",
        'status' => ActiveCampaignDispatch::STATUS_PENDING,
        'payload' => [
            'operation' => 'laboratory_purchase_completed',
            'laboratory_purchase_id' => $purchase->id,
        ],
    ]);

    $service = Mockery::mock(ActiveCampaignService::class);
    $service->shouldReceive('handleOutboundLaboratoryPurchaseCompleted')
        ->once()
        ->andThrow(new ActiveCampaignSyncException('AC timeout'));

    expect(fn () => (new DispatchActiveCampaignOutboundJob($dispatch->id))->handle($service))
        ->toThrow(ActiveCampaignSyncException::class);

    expect($dispatch->fresh()->status)->toBe(ActiveCampaignDispatch::STATUS_FAILED)
        ->and($dispatch->fresh()->attempts)->toBe(1)
        ->and(ActiveCampaignDispatch::query()->where('idempotency_key', $dispatch->idempotency_key)->count())->toBe(1);
});

it('laboratory purchase dispatch processes the single canonical AC operation', function () {
    $purchase = phase1LabPurchase(items: 1, user: phase1LabUser(['email' => 'process@example.com']));

    Http::fake([
        'https://ac.test/api/3/ecomOrders' => Http::response(['ecomOrder' => ['id' => 100]], 201),
        'https://ac.test/api/3/contacts*' => Http::response([
            'contacts' => [['id' => 42, 'email' => 'process@example.com']],
        ], 200),
        'https://ac.test/api/3/contactTags' => Http::response(['contactTag' => ['id' => 1]], 201),
    ]);

    $dispatch = ActiveCampaignDispatch::query()->create([
        'event_type' => 'laboratory_purchase_completed',
        'entity_type' => 'laboratory_purchase',
        'entity_id' => $purchase->id,
        'customer_id' => $purchase->customer_id,
        'email' => 'process@example.com',
        'idempotency_key' => "laboratory_purchase:{$purchase->id}:purchase_completed",
        'status' => ActiveCampaignDispatch::STATUS_PENDING,
        'payload' => [
            'operation' => 'laboratory_purchase_completed',
            'laboratory_purchase_id' => $purchase->id,
        ],
    ]);

    (new DispatchActiveCampaignOutboundJob($dispatch->id))->handle(app(ActiveCampaignService::class));

    expect($dispatch->fresh()->status)->toBe(ActiveCampaignDispatch::STATUS_SYNCED);
});
