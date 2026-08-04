<?php

use App\Jobs\ActiveCampaign\DispatchActiveCampaignCouponEventJob;
use App\Models\ActiveCampaignDispatch;
use App\Models\Coupon;
use App\Models\CouponUser;
use App\Models\User;
use App\Services\ActiveCampaign\ActiveCampaignDispatchService;
use App\Services\ActiveCampaign\CouponActiveCampaignDispatcher;
use App\Services\ActiveCampaign\CouponActiveCampaignPayloadBuilder;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Schema;

beforeEach(function () {
    if (! Schema::hasTable('activecampaign_dispatches')) {
        Schema::create('activecampaign_dispatches', function (Blueprint $table) {
            $table->id();
            $table->string('event_type', 64);
            $table->string('entity_type', 64);
            $table->unsignedBigInteger('entity_id')->nullable();
            $table->string('related_entity_type', 64)->nullable();
            $table->unsignedBigInteger('related_entity_id')->nullable();
            $table->unsignedBigInteger('user_id')->nullable();
            $table->unsignedBigInteger('customer_id')->nullable();
            $table->string('email')->nullable();
            $table->string('idempotency_key', 191)->unique();
            $table->string('status', 32)->default('pending');
            $table->unsignedSmallInteger('attempts')->default(0);
            $table->text('last_error')->nullable();
            $table->json('payload')->nullable();
            $table->timestamp('synced_at')->nullable();
            $table->timestamps();
        });
    }

    ActiveCampaignDispatch::query()->delete();

    config([
        'services.activecampaign.enabled' => true,
        'services.activecampaign.coupons_enabled' => true,
    ]);
});

function acB1User(array $overrides = []): User
{
    return new User(array_merge([
        'id' => 10,
        'email' => 'paciente@example.com',
        'name' => 'Ana',
    ], $overrides));
}

function acB1Coupon(array $overrides = []): Coupon
{
    return new Coupon(array_merge([
        'id' => 100,
        'parent_coupon_id' => null,
        'amount_cents' => 50000,
        'remaining_cents' => 50000,
        'type' => \App\Enums\CouponType::Balance,
        'is_active' => true,
    ], $overrides));
}

function acB1Assignment(array $overrides = []): CouponUser
{
    $assignment = new CouponUser(array_merge([
        'coupon_id' => 100,
        'user_id' => 10,
        'assigned_at' => now(),
    ], $overrides));
    $assignment->id = $overrides['id'] ?? 55;

    return $assignment;
}

test('flag global apagado no encola job de crédito', function () {
    Queue::fake();

    config(['services.activecampaign.enabled' => false]);

    $builder = Mockery::mock(CouponActiveCampaignPayloadBuilder::class);
    $builder->shouldNotReceive('creditAssigned');

    $dispatcher = new CouponActiveCampaignDispatcher(
        app(ActiveCampaignDispatchService::class),
        $builder,
    );

    $dispatcher->creditAssigned(acB1Coupon(), acB1Assignment(), acB1User(), 'individual');

    Queue::assertNothingPushed();
});

test('flag coupons apagado no encola job de crédito', function () {
    Queue::fake();

    config(['services.activecampaign.coupons_enabled' => false]);

    $builder = Mockery::mock(CouponActiveCampaignPayloadBuilder::class);
    $builder->shouldNotReceive('creditAssigned');

    $dispatcher = new CouponActiveCampaignDispatcher(
        app(ActiveCampaignDispatchService::class),
        $builder,
    );

    $dispatcher->creditAssigned(acB1Coupon(), acB1Assignment(), acB1User(), 'individual');

    Queue::assertNothingPushed();
});

test('usuario sin email no encola job', function () {
    Queue::fake();

    $builder = Mockery::mock(CouponActiveCampaignPayloadBuilder::class);
    $builder->shouldNotReceive('creditAssigned');

    $dispatcher = new CouponActiveCampaignDispatcher(
        app(ActiveCampaignDispatchService::class),
        $builder,
    );

    $dispatcher->creditAssigned(acB1Coupon(), acB1Assignment(), acB1User(['email' => '']), 'individual');

    Queue::assertNothingPushed();
});

test('asignación crea dispatch pending y encola job', function () {
    Queue::fake();

    $payload = [
        'event_type' => 'credit_assigned',
        'email' => 'paciente@example.com',
        'user_id' => 10,
        'coupon_user_id' => 55,
        'validation_token' => 'secret',
    ];

    $builder = Mockery::mock(CouponActiveCampaignPayloadBuilder::class);

    $dispatcher = new CouponActiveCampaignDispatcher(
        app(ActiveCampaignDispatchService::class),
        $builder,
    );

    $dispatcher->enqueue(
        eventType: 'credit_assigned',
        idempotencyKey: 'credit_assigned:coupon_user:55',
        entityType: 'coupon_user',
        entityId: 55,
        relatedEntityType: 'coupon',
        relatedEntityId: 100,
        user: acB1User(),
        payload: $payload,
    );

    $dispatch = ActiveCampaignDispatch::query()->first();
    expect($dispatch)->not->toBeNull();
    expect($dispatch->status)->toBe(ActiveCampaignDispatch::STATUS_PENDING);
    expect($dispatch->idempotency_key)->toBe('credit_assigned:coupon_user:55');

    Queue::assertPushed(DispatchActiveCampaignCouponEventJob::class, fn ($job) => $job->dispatchId === $dispatch->id);
});

test('idempotency_key duplicado no encola segundo job', function () {
    Queue::fake();

    $builder = Mockery::mock(CouponActiveCampaignPayloadBuilder::class);
    $builder->shouldReceive('idempotencyKeyForAssigned')->andReturn('credit_assigned:coupon_user:55');
    $builder->shouldReceive('creditAssigned')->twice()->andReturn([
        'event_type' => 'credit_assigned',
        'email' => 'paciente@example.com',
    ]);

    $dispatcher = new CouponActiveCampaignDispatcher(
        app(ActiveCampaignDispatchService::class),
        $builder,
    );

    $user = acB1User();
    $coupon = acB1Coupon();
    $assignment = acB1Assignment();

    $dispatcher->creditAssigned($coupon, $assignment, $user, 'individual');
    $dispatcher->creditAssigned($coupon, $assignment, $user, 'individual');

    expect(ActiveCampaignDispatch::query()->count())->toBe(1);
    Queue::assertPushed(DispatchActiveCampaignCouponEventJob::class, 1);
});

test('payload sensible se redacta en logs del dispatch service', function () {
    $service = app(ActiveCampaignDispatchService::class);

    $sanitized = $service->sanitizePayloadForLog([
        'email' => 'a@b.com',
        'validation_token' => 'abc',
        'otp' => '123456',
        'amount_cents' => 100,
    ]);

    expect($sanitized['validation_token'])->toBe('[redacted]');
    expect($sanitized['otp'])->toBe('[redacted]');
    expect($sanitized['amount_cents'])->toBe(100);
});
