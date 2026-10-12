<?php

use App\DTOs\Orders\OrderAutomationContext;
use App\Enums\GdaOrderStatus;
use App\Models\ActiveCampaignDispatch;
use App\Models\Customer;
use App\Models\LaboratoryPurchase;
use App\Models\LaboratoryPurchaseItem;
use App\Models\User;
use App\Services\ActiveCampaign\ActiveCampaignOutboundDispatcher;
use App\Services\ActiveCampaign\ActiveCampaignService;
use App\Services\Orders\Drivers\ActiveCampaignOrderDriver;
use Illuminate\Database\Eloquent\Collection;

test('ActiveCampaignOrderDriver laboratory sync succeeds when outbox dispatch is queued', function () {
    $user = User::factory()->make(['email' => 'lab@example.com']);
    $customer = Customer::factory()->make(['id' => 10]);
    $customer->setRelation('user', $user);

    $item = new LaboratoryPurchaseItem([
        'name' => 'Hemograma',
        'price_cents' => 50000,
    ]);

    $purchase = LaboratoryPurchase::factory()->make([
        'id' => 77,
        'total_cents' => 50000,
        'gda_status' => GdaOrderStatus::Confirmed,
    ]);
    $purchase->setRelation('customer', $customer);
    $purchase->setRelation('laboratoryPurchaseItems', new Collection([$item]));

    $context = new OrderAutomationContext(
        order: $purchase,
        customer: $customer,
        transaction: null,
        paymentAttempt: null,
        laboratoryPurchase: $purchase,
        pharmacyOrder: null,
        membership: null,
        amountCents: 50000,
        reference: 'LAB-REF',
        gateway: 'efevoopay',
        createdAt: now(),
        channel: OrderAutomationContext::CHANNEL_LABORATORY,
    );

    $ac = Mockery::mock(ActiveCampaignService::class);
    $dispatcher = Mockery::mock(ActiveCampaignOutboundDispatcher::class);
    $dispatcher->shouldReceive('enqueueLaboratoryPurchaseCompleted')
        ->once()
        ->with($purchase)
        ->andReturn(tap(new ActiveCampaignDispatch([
            'id' => 123,
            'event_type' => 'laboratory_purchase_completed',
            'idempotency_key' => 'laboratory_purchase:77:purchase_completed',
            'status' => ActiveCampaignDispatch::STATUS_PENDING,
        ]), fn (ActiveCampaignDispatch $dispatch) => $dispatch->id = 123));

    $driver = new ActiveCampaignOrderDriver($ac, $dispatcher);
    $result = $driver->handleLaboratoryOrder($context);

    expect($result->status)->toBe('synced')
        ->and($result->activecampaign['success'])->toBeTrue()
        ->and($result->activecampaign['executed'])->toBeTrue()
        ->and($result->activecampaign['operations'])->toHaveCount(1)
        ->and($result->activecampaign['operations'][0]['operation'])->toBe('laboratory_purchase_completed_outbox');
});

test('ActiveCampaignOrderDriver laboratory sync fails when outbox dispatch cannot be queued', function () {
    $user = User::factory()->make(['email' => 'lab@example.com']);
    $customer = Customer::factory()->make(['id' => 10]);
    $customer->setRelation('user', $user);

    $purchase = LaboratoryPurchase::factory()->make([
        'id' => 88,
        'total_cents' => 10000,
        'gda_status' => GdaOrderStatus::Confirmed,
    ]);
    $purchase->setRelation('customer', $customer);
    $purchase->setRelation('laboratoryPurchaseItems', new Collection());

    $context = new OrderAutomationContext(
        order: $purchase,
        customer: $customer,
        transaction: null,
        paymentAttempt: null,
        laboratoryPurchase: $purchase,
        pharmacyOrder: null,
        membership: null,
        amountCents: 10000,
        reference: null,
        gateway: null,
        createdAt: now(),
        channel: OrderAutomationContext::CHANNEL_LABORATORY,
    );

    $ac = Mockery::mock(ActiveCampaignService::class);
    $dispatcher = Mockery::mock(ActiveCampaignOutboundDispatcher::class);
    $dispatcher->shouldReceive('enqueueLaboratoryPurchaseCompleted')
        ->once()
        ->andThrow(new RuntimeException('outbox_error'));

    $driver = new ActiveCampaignOrderDriver($ac, $dispatcher);
    $result = $driver->handleLaboratoryOrder($context);

    expect($result->status)->toBe('failed')
        ->and($result->activecampaign['success'])->toBeFalse()
        ->and($result->activecampaign['retryable'])->toBeTrue()
        ->and($result->activecampaign['operations'])->toHaveCount(0)
        ->and($result->activecampaign['error'])->toBe('outbox_error');
});
