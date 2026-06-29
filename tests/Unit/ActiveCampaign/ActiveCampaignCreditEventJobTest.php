<?php

use App\Jobs\ActiveCampaign\DispatchActiveCampaignCouponEventJob;
use App\Models\ActiveCampaignDispatch;
use App\Services\ActiveCampaign\ActiveCampaignDispatchService;
use App\Services\ActiveCampaign\ActiveCampaignService;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Http;
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
        'services.activecampaign.endpoint' => 'https://ac.test',
        'services.activecampaign.token' => 'token-test',
        'services.activecampaign.enabled' => true,
        'services.activecampaign.coupons_enabled' => true,
        'services.activecampaign.tags' => [
            'credit' => [
                'available' => '10',
                'expiring' => '11',
                'used' => '12',
                'restored' => '13',
                'revoked' => '14',
                'closed' => '15',
            ],
            'beneficiary' => ['pending' => '20'],
            'benefit' => ['activated' => '21'],
            'promo' => ['validated' => '30', 'used' => '31', 'abandoned' => '32'],
            'authorization' => ['pending' => '40'],
        ],
        'services.activecampaign.fields' => [
            'fm_credito_estado' => '200',
            'fm_credito_restante' => '201',
            'fm_credito_ultimo_uso_at' => '202',
            'fm_ultima_compra_lab_at' => '203',
        ],
    ]);
});

function fakeAcCouponApi(int $contactId = 42): void
{
    Http::fake([
        'https://ac.test/api/3/contacts*' => Http::response([
            'contacts' => [['id' => $contactId, 'email' => 'user@example.com']],
        ], 200),
        'https://ac.test/api/3/contactTags' => Http::response(['contactTag' => ['id' => 1]], 201),
        'https://ac.test/api/3/contacts/*/contactTags' => Http::response(['contactTags' => []], 200),
        'https://ac.test/api/3/fieldValues' => Http::response(['fieldValue' => ['id' => 1]], 201),
    ]);
}

test('job credit_redeemed agrega tags usado/cerrado y actualiza fields', function () {
    fakeAcCouponApi();

    $dispatch = app(ActiveCampaignDispatchService::class)->createOrSkipByIdempotencyKey([
        'event_type' => 'credit_redeemed',
        'idempotency_key' => 'credit_redeemed:coupon_transaction:1',
        'entity_type' => 'coupon_transaction',
        'entity_id' => 1,
        'user_id' => 10,
        'email' => 'user@example.com',
        'payload' => [
            'event_type' => 'credit_redeemed',
            'email' => 'user@example.com',
            'user_id' => 10,
            'remaining_cents_after' => 0,
            'redeemed_at' => now()->toIso8601String(),
            'purchase_type' => 'lab',
        ],
    ]);

    (new DispatchActiveCampaignCouponEventJob($dispatch->id))->handle(app(ActiveCampaignService::class));

    $fresh = $dispatch->fresh();
    expect($fresh->status)->toBe(ActiveCampaignDispatch::STATUS_SYNCED);
    expect($fresh->synced_at)->not->toBeNull();

    Http::assertSent(fn ($request) => $request->method() === 'POST'
        && str_contains($request->url(), '/contactTags')
        && ($request['contactTag']['tag'] ?? null) == 12);

    Http::assertSent(fn ($request) => $request->method() === 'POST'
        && str_contains($request->url(), '/contactTags')
        && ($request['contactTag']['tag'] ?? null) == 15);
});

test('falla API marca failed y relanza para retry', function () {
    Http::fake([
        'https://ac.test/api/3/contacts*' => Http::response(['contacts' => []], 200),
    ]);

    $dispatch = app(ActiveCampaignDispatchService::class)->createOrSkipByIdempotencyKey([
        'event_type' => 'credit_assigned',
        'idempotency_key' => 'credit_assigned:coupon_user:500',
        'entity_type' => 'coupon_user',
        'entity_id' => 500,
        'email' => 'user@example.com',
        'payload' => [
            'event_type' => 'credit_assigned',
            'email' => 'user@example.com',
            'user_id' => 10,
            'amount_cents' => 10000,
            'remaining_cents' => 10000,
        ],
    ]);

    expect(fn () => (new DispatchActiveCampaignCouponEventJob($dispatch->id))
        ->handle(app(ActiveCampaignService::class)))
        ->toThrow(\App\Exceptions\ActiveCampaignSyncException::class);

    $fresh = $dispatch->fresh();
    expect($fresh->status)->toBe(ActiveCampaignDispatch::STATUS_FAILED);
    expect($fresh->attempts)->toBe(1);
});

test('credit_restored usable vuelve a tag disponible', function () {
    fakeAcCouponApi();

    $dispatch = app(ActiveCampaignDispatchService::class)->createOrSkipByIdempotencyKey([
        'event_type' => 'credit_restored',
        'idempotency_key' => 'credit_restored:coupon_transaction:2',
        'entity_type' => 'coupon_transaction',
        'entity_id' => 2,
        'email' => 'user@example.com',
        'payload' => [
            'event_type' => 'credit_restored',
            'email' => 'user@example.com',
            'is_usable_after_restore' => true,
            'remaining_cents_after' => 50000,
        ],
    ]);

    (new DispatchActiveCampaignCouponEventJob($dispatch->id))->handle(app(ActiveCampaignService::class));

    Http::assertSent(fn ($request) => $request->method() === 'POST'
        && str_contains($request->url(), '/contactTags')
        && ($request['contactTag']['tag'] ?? null) == 10);
});

test('credit_revoked agrega cerrado y no duplica en re-ejecución synced', function () {
    fakeAcCouponApi();

    $dispatch = ActiveCampaignDispatch::query()->create([
        'event_type' => 'credit_revoked',
        'entity_type' => 'coupon_user',
        'entity_id' => 3,
        'idempotency_key' => 'credit_revoked:coupon_user:3',
        'status' => ActiveCampaignDispatch::STATUS_SYNCED,
        'synced_at' => now(),
        'email' => 'user@example.com',
        'payload' => [
            'event_type' => 'credit_revoked',
            'email' => 'user@example.com',
        ],
    ]);

    (new DispatchActiveCampaignCouponEventJob($dispatch->id))->handle(app(ActiveCampaignService::class));

    Http::assertNothingSent();
});

test('evento no implementado queda skipped', function () {
    $dispatch = app(ActiveCampaignDispatchService::class)->createOrSkipByIdempotencyKey([
        'event_type' => 'promo_abandoned',
        'idempotency_key' => 'promo_abandoned:1',
        'entity_type' => 'promo_redemption',
        'entity_id' => 1,
        'email' => 'user@example.com',
        'payload' => ['email' => 'user@example.com'],
    ]);

    (new DispatchActiveCampaignCouponEventJob($dispatch->id))->handle(app(ActiveCampaignService::class));

    expect($dispatch->fresh()->status)->toBe(ActiveCampaignDispatch::STATUS_SKIPPED);
    expect($dispatch->fresh()->last_error)->toBe('event_not_implemented');
});
