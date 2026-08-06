<?php

use App\DTOs\ActiveCampaign\ActiveCampaignOperationResult;
use App\DTOs\Orders\OrderAutomationContext;
use App\Models\Customer;
use App\Models\LaboratoryPurchase;
use App\Models\LaboratoryPurchaseItem;
use App\Models\User;
use App\Services\ActiveCampaign\ActiveCampaignService;
use App\Services\Orders\Drivers\ActiveCampaignOrderDriver;
use Illuminate\Database\Eloquent\Collection;

test('ActiveCampaignOrderDriver laboratory sync succeeds when both AC ops succeed', function () {
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
    $ac->shouldReceive('laboratoryPurchase')
        ->once()
        ->with($purchase)
        ->andReturn(ActiveCampaignOperationResult::success([
            'operation' => 'laboratoryPurchase',
            'resource' => 'ecomOrder',
            'contact_id' => 123,
            'duration_ms' => 10,
        ]));
    $ac->shouldReceive('completedPurchase')
        ->once()
        ->withArgs(function (string $email, string $externalId, float $total, array $products, string $category) {
            return $email === 'lab@example.com'
                && $externalId === 'COMPLETE-LAB-77'
                && $category === 'Laboratorio'
                && $total === 500.0;
        })
        ->andReturn(ActiveCampaignOperationResult::success([
            'operation' => 'completedPurchase',
            'resource' => 'ecomOrder',
            'duration_ms' => 12,
        ]));

    $driver = new ActiveCampaignOrderDriver($ac);
    $result = $driver->handleLaboratoryOrder($context);

    expect($result->status)->toBe('synced')
        ->and($result->activecampaign['success'])->toBeTrue()
        ->and($result->activecampaign['executed'])->toBeTrue()
        ->and($result->activecampaign['operations'])->toHaveCount(2)
        ->and($result->activecampaign['operations'][0]['operation'])->toBe('laboratoryPurchase')
        ->and($result->activecampaign['operations'][1]['operation'])->toBe('completedPurchase');
});

test('ActiveCampaignOrderDriver laboratory sync fails when laboratoryPurchase fails', function () {
    $user = User::factory()->make(['email' => 'lab@example.com']);
    $customer = Customer::factory()->make(['id' => 10]);
    $customer->setRelation('user', $user);

    $purchase = LaboratoryPurchase::factory()->make([
        'id' => 88,
        'total_cents' => 10000,
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
    $ac->shouldReceive('laboratoryPurchase')
        ->once()
        ->andReturn(ActiveCampaignOperationResult::failure([
            'operation' => 'laboratoryPurchase',
            'error' => 'create_ecom_order_http_error',
            'http_status' => 503,
            'retryable' => true,
            'duration_ms' => 5,
        ]));
    $ac->shouldReceive('completedPurchase')->never();

    $driver = new ActiveCampaignOrderDriver($ac);
    $result = $driver->handleLaboratoryOrder($context);

    expect($result->status)->toBe('failed')
        ->and($result->activecampaign['success'])->toBeFalse()
        ->and($result->activecampaign['retryable'])->toBeTrue()
        ->and($result->activecampaign['operations'])->toHaveCount(1)
        ->and($result->activecampaign['error'])->toBe('create_ecom_order_http_error');
});
