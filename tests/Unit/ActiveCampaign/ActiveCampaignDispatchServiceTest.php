<?php

use App\Models\ActiveCampaignDispatch;
use App\Services\ActiveCampaign\ActiveCampaignDispatchService;
use Illuminate\Database\Schema\Blueprint;
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
});

function acDispatchPayload(array $overrides = []): array
{
    return array_merge([
        'event_type' => 'credit_assigned',
        'idempotency_key' => 'credit_assigned:coupon_user:1',
        'entity_type' => 'coupon_user',
        'entity_id' => 1,
        'user_id' => 10,
        'email' => 'user@example.com',
        'payload' => ['amount_cents' => 50000],
    ], $overrides);
}

test('idempotency_key duplicado no crea dispatch duplicado', function () {
    config([
        'services.activecampaign.enabled' => true,
        'services.activecampaign.coupons_enabled' => true,
    ]);

    $service = app(ActiveCampaignDispatchService::class);

    $first = $service->createOrSkipByIdempotencyKey(acDispatchPayload());
    $second = $service->createOrSkipByIdempotencyKey(acDispatchPayload());

    expect($first->id)->toBe($second->id);
    expect(ActiveCampaignDispatch::query()->count())->toBe(1);
});

test('markProcessing cambia estado a processing', function () {
    $dispatch = ActiveCampaignDispatch::query()->create([
        'event_type' => 'credit_assigned',
        'entity_type' => 'coupon_user',
        'entity_id' => 1,
        'idempotency_key' => 'credit_assigned:coupon_user:99',
        'status' => ActiveCampaignDispatch::STATUS_PENDING,
        'payload' => [],
    ]);

    app(ActiveCampaignDispatchService::class)->markProcessing($dispatch);

    expect($dispatch->fresh()->status)->toBe(ActiveCampaignDispatch::STATUS_PROCESSING);
});

test('markSynced setea synced_at', function () {
    $dispatch = ActiveCampaignDispatch::query()->create([
        'event_type' => 'credit_assigned',
        'entity_type' => 'coupon_user',
        'entity_id' => 1,
        'idempotency_key' => 'credit_assigned:coupon_user:100',
        'status' => ActiveCampaignDispatch::STATUS_PROCESSING,
        'payload' => [],
    ]);

    app(ActiveCampaignDispatchService::class)->markSynced($dispatch);

    $fresh = $dispatch->fresh();
    expect($fresh->status)->toBe(ActiveCampaignDispatch::STATUS_SYNCED);
    expect($fresh->synced_at)->not->toBeNull();
});

test('markFailed incrementa attempts y guarda error', function () {
    $dispatch = ActiveCampaignDispatch::query()->create([
        'event_type' => 'credit_assigned',
        'entity_type' => 'coupon_user',
        'entity_id' => 1,
        'idempotency_key' => 'credit_assigned:coupon_user:101',
        'status' => ActiveCampaignDispatch::STATUS_PROCESSING,
        'attempts' => 1,
        'payload' => [],
    ]);

    app(ActiveCampaignDispatchService::class)->markFailed($dispatch, 'API timeout');

    $fresh = $dispatch->fresh();
    expect($fresh->status)->toBe(ActiveCampaignDispatch::STATUS_FAILED);
    expect($fresh->attempts)->toBe(2);
    expect($fresh->last_error)->toBe('API timeout');
});

test('markSkipped guarda skipped', function () {
    $dispatch = ActiveCampaignDispatch::query()->create([
        'event_type' => 'credit_assigned',
        'entity_type' => 'coupon_user',
        'entity_id' => 1,
        'idempotency_key' => 'credit_assigned:coupon_user:102',
        'status' => ActiveCampaignDispatch::STATUS_PENDING,
        'payload' => [],
    ]);

    app(ActiveCampaignDispatchService::class)->markSkipped($dispatch, 'test_skip');

    $fresh = $dispatch->fresh();
    expect($fresh->status)->toBe(ActiveCampaignDispatch::STATUS_SKIPPED);
    expect($fresh->last_error)->toBe('test_skip');
});

test('payload se castea como array y synced_at como datetime', function () {
    $syncedAt = now();

    $dispatch = ActiveCampaignDispatch::query()->create([
        'event_type' => 'promo_validated',
        'entity_type' => 'promo_redemption',
        'entity_id' => 5,
        'idempotency_key' => 'promo_validated:promo_redemption:5',
        'status' => ActiveCampaignDispatch::STATUS_SYNCED,
        'payload' => ['promo_code' => 'FAM-TEST'],
        'synced_at' => $syncedAt,
    ]);

    $fresh = $dispatch->fresh();

    expect($fresh->payload)->toBeArray();
    expect($fresh->payload['promo_code'])->toBe('FAM-TEST');
    expect($fresh->synced_at?->toDateTimeString())->toBe($syncedAt->toDateTimeString());
});

test('con flag global apagado no se sincroniza y crea dispatch skipped', function () {
    config([
        'services.activecampaign.enabled' => false,
        'services.activecampaign.coupons_enabled' => true,
    ]);

    $service = app(ActiveCampaignDispatchService::class);

    expect($service->shouldDispatch('credit_assigned:coupon_user:200', 'credit_assigned'))->toBeFalse();

    $dispatch = $service->createOrSkipByIdempotencyKey(acDispatchPayload([
        'idempotency_key' => 'credit_assigned:coupon_user:200',
    ]));

    expect($dispatch->status)->toBe(ActiveCampaignDispatch::STATUS_SKIPPED);
    expect($dispatch->last_error)->toBe('integration_disabled');
});

test('con flag de cupones apagado no sincroniza evento de cupón', function () {
    config([
        'services.activecampaign.enabled' => true,
        'services.activecampaign.coupons_enabled' => false,
    ]);

    $service = app(ActiveCampaignDispatchService::class);

    expect($service->shouldDispatch('credit_assigned:coupon_user:201', 'credit_assigned'))->toBeFalse();

    $dispatch = $service->createOrSkipByIdempotencyKey(acDispatchPayload([
        'idempotency_key' => 'credit_assigned:coupon_user:201',
    ]));

    expect($dispatch->status)->toBe(ActiveCampaignDispatch::STATUS_SKIPPED);
    expect($dispatch->last_error)->toBe('coupons_integration_disabled');
});

test('shouldDispatch permite reintento cuando el dispatch previo falló', function () {
    config([
        'services.activecampaign.enabled' => true,
        'services.activecampaign.coupons_enabled' => true,
    ]);

    ActiveCampaignDispatch::query()->create([
        'event_type' => 'credit_assigned',
        'entity_type' => 'coupon_user',
        'entity_id' => 1,
        'idempotency_key' => 'credit_assigned:coupon_user:202',
        'status' => ActiveCampaignDispatch::STATUS_FAILED,
        'payload' => [],
    ]);

    $service = app(ActiveCampaignDispatchService::class);

    expect($service->shouldDispatch('credit_assigned:coupon_user:202', 'credit_assigned'))->toBeTrue();
});

test('dispatch job B1 sincroniza con API cuando está habilitado', function () {
    config([
        'services.activecampaign.enabled' => true,
        'services.activecampaign.coupons_enabled' => true,
        'services.activecampaign.endpoint' => 'https://ac.test',
        'services.activecampaign.token' => 'token-test',
        'services.activecampaign.tags' => [
            'credit' => [
                'available' => '10',
                'closed' => '15',
                'used' => '12',
                'expiring' => '11',
                'restored' => '13',
                'revoked' => '14',
            ],
            'beneficiary' => ['pending' => '20'],
            'benefit' => ['activated' => '21'],
            'promo' => ['validated' => '30', 'used' => '31', 'abandoned' => '32'],
            'authorization' => ['pending' => '40'],
        ],
    ]);

    \Illuminate\Support\Facades\Http::fake([
        'https://ac.test/api/3/contacts*' => \Illuminate\Support\Facades\Http::response([
            'contacts' => [['id' => 42, 'email' => 'user@example.com']],
        ], 200),
        'https://ac.test/api/3/contactTags' => \Illuminate\Support\Facades\Http::response(['contactTag' => ['id' => 1]], 201),
        'https://ac.test/api/3/contacts/*/contactTags' => \Illuminate\Support\Facades\Http::response(['contactTags' => []], 200),
        'https://ac.test/api/3/fieldValues' => \Illuminate\Support\Facades\Http::response(['fieldValue' => ['id' => 1]], 201),
    ]);

    $dispatch = app(ActiveCampaignDispatchService::class)->createOrSkipByIdempotencyKey(acDispatchPayload([
        'idempotency_key' => 'credit_assigned:coupon_user:203',
        'payload' => [
            'event_type' => 'credit_assigned',
            'email' => 'user@example.com',
            'user_id' => 10,
            'amount_cents' => 50000,
            'remaining_cents' => 50000,
        ],
    ]));

    (new \App\Jobs\ActiveCampaign\DispatchActiveCampaignCouponEventJob($dispatch->id))
        ->handle(app(\App\Services\ActiveCampaign\ActiveCampaignService::class));

    $fresh = $dispatch->fresh();
    expect($fresh->status)->toBe(ActiveCampaignDispatch::STATUS_SYNCED);
    expect($fresh->synced_at)->not->toBeNull();
});
