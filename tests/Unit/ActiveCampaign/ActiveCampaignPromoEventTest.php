<?php

use App\Enums\PromoRedemptionStatus;
use App\Enums\PromoType;
use App\Jobs\ActiveCampaign\DispatchActiveCampaignCouponEventJob;
use App\Models\ActiveCampaignDispatch;
use App\Models\Coupon;
use App\Models\PromoCode;
use App\Models\PromoRedemption;
use App\Models\User;
use App\Services\ActiveCampaign\ActiveCampaignDispatchService;
use App\Services\ActiveCampaign\ActiveCampaignService;
use App\Services\ActiveCampaign\CouponActiveCampaignDispatcher;
use App\Services\ActiveCampaign\CouponActiveCampaignPayloadBuilder;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Http;
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
        'services.activecampaign.endpoint' => 'https://ac.test',
        'services.activecampaign.token' => 'token-test',
        'services.activecampaign.tags' => [
            'credit' => ['available' => '10', 'closed' => '15'],
            'beneficiary' => ['pending' => '20'],
            'benefit' => ['activated' => '21'],
            'promo' => [
                'validated' => '30',
                'used' => '31',
                'abandoned' => '32',
            ],
            'authorization' => ['pending' => '40'],
        ],
        'services.activecampaign.fields' => [
            'fm_promo_ultimo_codigo' => '210',
            'fm_promo_estado' => '211',
            'fm_ultima_compra_lab_at' => '212',
        ],
    ]);
});

function acB2bRedemption(array $overrides = []): PromoRedemption
{
    $user = new User(['id' => 10, 'email' => 'cliente@example.com']);
    $master = new Coupon(['id' => 1, 'amount_cents' => 5000, 'min_purchase_cents' => 10000]);
    $promoCode = new PromoCode([
        'id' => 5,
        'code' => 'FAM-TEST',
        'promo_type' => PromoType::Shared,
        'max_redemptions' => 100,
        'max_uses_per_user' => 1,
        'redemptions_count' => 2,
        'coupon_id' => 1,
    ]);
    $promoCode->setRelation('coupon', $master);

    $redemption = new PromoRedemption(array_merge([
        'promo_code_id' => 5,
        'user_id' => 10,
        'customer_id' => 20,
        'status' => PromoRedemptionStatus::Validated,
        'discount_cents' => 5000,
        'validation_token' => 'secret-token-should-not-leak',
        'cart_hash' => 'hash-should-not-leak',
        'validated_at' => now(),
    ], $overrides));
    $redemption->id = $overrides['id'] ?? 99;
    $redemption->setRelation('user', $user);
    $redemption->setRelation('promoCode', $promoCode);

    return $redemption;
}

function fakeAcPromoApi(int $contactId = 42, array $contactTags = []): void
{
    Http::fake([
        'https://ac.test/api/3/contacts/*/contactTags' => Http::response([
            'contactTags' => $contactTags,
        ], 200),
        'https://ac.test/api/3/contactTags/*' => Http::response([], 200),
        'https://ac.test/api/3/contacts*' => Http::response([
            'contacts' => [['id' => $contactId, 'email' => 'cliente@example.com']],
        ], 200),
        'https://ac.test/api/3/contactTags' => Http::response(['contactTag' => ['id' => 1]], 201),
        'https://ac.test/api/3/fieldValues' => Http::response(['fieldValue' => ['id' => 1]], 201),
    ]);
}

test('promo_validated encola dispatch con code público sin validation_token', function () {
    Queue::fake();

    $builder = new CouponActiveCampaignPayloadBuilder(Mockery::mock(\App\Services\CouponService::class));
    $dispatcher = new CouponActiveCampaignDispatcher(
        app(ActiveCampaignDispatchService::class),
        $builder,
    );

    $dispatcher->enqueueBeneficiary(
        eventType: 'promo_validated',
        idempotencyKey: 'promo_validated:promo_redemption:99',
        entityType: 'promo_redemption',
        entityId: 99,
        relatedEntityType: 'promo_code',
        relatedEntityId: 5,
        email: 'cliente@example.com',
        userId: 10,
        customerId: 20,
        payload: $builder->promoValidated(acB2bRedemption()),
    );

    $dispatch = ActiveCampaignDispatch::query()->first();
    expect($dispatch)->not->toBeNull();
    expect($dispatch->payload['code'])->toBe('FAM-TEST');
    expect($dispatch->payload)->not->toHaveKey('validation_token');
    expect($dispatch->payload)->not->toHaveKey('cart_hash');
    Queue::assertPushed(DispatchActiveCampaignCouponEventJob::class);
});

test('promo_validated no dispara sin email utilizable', function () {
    Queue::fake();

    $dispatcher = new CouponActiveCampaignDispatcher(
        app(ActiveCampaignDispatchService::class),
        Mockery::mock(CouponActiveCampaignPayloadBuilder::class),
    );

    $redemption = acB2bRedemption();
    $redemption->setRelation('user', new User(['id' => 10, 'email' => '']));

    $dispatcher->promoValidated($redemption);

    Queue::assertNothingPushed();
});

test('idempotencia evita duplicado promo_validated', function () {
    Queue::fake();

    $builder = Mockery::mock(CouponActiveCampaignPayloadBuilder::class);
    $builder->shouldReceive('idempotencyKeyForPromoValidated')->andReturn('promo_validated:promo_redemption:99');
    $builder->shouldReceive('promoValidated')->twice()->andReturn([
        'event_type' => 'promo_validated',
        'email' => 'cliente@example.com',
        'code' => 'FAM-TEST',
    ]);

    $dispatcher = new CouponActiveCampaignDispatcher(
        app(ActiveCampaignDispatchService::class),
        $builder,
    );

    $redemption = acB2bRedemption();
    $dispatcher->promoValidated($redemption);
    $dispatcher->promoValidated($redemption);

    expect(ActiveCampaignDispatch::query()->count())->toBe(1);
    Queue::assertPushed(DispatchActiveCampaignCouponEventJob::class, 1);
});

test('promo_used encola sin credit_redeemed', function () {
    Queue::fake();

    $redemption = acB2bRedemption([
        'status' => PromoRedemptionStatus::Confirmed,
        'coupon_id' => 50,
        'purchase_type' => 'lab',
        'purchase_id' => 200,
        'confirmed_at' => now(),
    ]);

    $builder = new CouponActiveCampaignPayloadBuilder(Mockery::mock(\App\Services\CouponService::class));
    $dispatcher = new CouponActiveCampaignDispatcher(
        app(ActiveCampaignDispatchService::class),
        $builder,
    );

    $dispatcher->enqueueBeneficiary(
        eventType: 'promo_used',
        idempotencyKey: 'promo_used:promo_redemption:99',
        entityType: 'promo_redemption',
        entityId: 99,
        relatedEntityType: 'promo_code',
        relatedEntityId: 5,
        email: 'cliente@example.com',
        userId: 10,
        customerId: 20,
        payload: $builder->promoUsed($redemption),
    );

    expect(ActiveCampaignDispatch::query()->where('event_type', 'promo_used')->exists())->toBeTrue();
    expect(ActiveCampaignDispatch::query()->where('event_type', 'credit_redeemed')->exists())->toBeFalse();
});

test('job promo_used agrega usada y remueve validada', function () {
    fakeAcPromoApi(contactTags: [['id' => 88, 'tag' => 30]]);

    $dispatch = app(ActiveCampaignDispatchService::class)->createOrSkipByIdempotencyKey([
        'event_type' => 'promo_used',
        'idempotency_key' => 'promo_used:promo_redemption:1',
        'entity_type' => 'promo_redemption',
        'entity_id' => 1,
        'email' => 'cliente@example.com',
        'user_id' => 10,
        'payload' => [
            'event_type' => 'promo_used',
            'email' => 'cliente@example.com',
            'user_id' => 10,
            'code' => 'FAM-TEST',
            'purchase_type' => 'lab',
            'confirmed_at' => now()->toIso8601String(),
        ],
    ]);

    (new DispatchActiveCampaignCouponEventJob($dispatch->id))->handle(app(ActiveCampaignService::class));

    expect($dispatch->fresh()->status)->toBe(ActiveCampaignDispatch::STATUS_SYNCED);

    Http::assertSent(fn ($request) => $request->method() === 'POST'
        && str_contains($request->url(), '/contactTags')
        && ($request['contactTag']['tag'] ?? null) == 31);

    Http::assertSent(fn ($request) => $request->method() === 'DELETE'
        && str_contains($request->url(), '/contactTags/88'));
});

test('promo_released remueve validada sin agregar usada ni abandonada', function () {
    fakeAcPromoApi(contactTags: [['id' => 87, 'tag' => 30]]);

    $dispatch = app(ActiveCampaignDispatchService::class)->createOrSkipByIdempotencyKey([
        'event_type' => 'promo_released',
        'idempotency_key' => 'promo_released:promo_redemption:2',
        'entity_type' => 'promo_redemption',
        'entity_id' => 2,
        'email' => 'cliente@example.com',
        'user_id' => 10,
        'payload' => [
            'event_type' => 'promo_released',
            'email' => 'cliente@example.com',
            'user_id' => 10,
            'code' => 'FAM-TEST',
            'released_at' => now()->toIso8601String(),
        ],
    ]);

    (new DispatchActiveCampaignCouponEventJob($dispatch->id))->handle(app(ActiveCampaignService::class));

    expect($dispatch->fresh()->status)->toBe(ActiveCampaignDispatch::STATUS_SYNCED);

    Http::assertSent(fn ($request) => $request->method() === 'DELETE'
        && str_contains($request->url(), '/contactTags/87'));

    Http::assertNotSent(fn ($request) => $request->method() === 'POST'
        && str_contains($request->url(), '/contactTags')
        && ($request['contactTag']['tag'] ?? null) == 31);

    Http::assertNotSent(fn ($request) => $request->method() === 'POST'
        && str_contains($request->url(), '/contactTags')
        && ($request['contactTag']['tag'] ?? null) == 32);
});

test('promo_released no dispara si redemption sigue validated en memoria', function () {
    Queue::fake();

    $builder = Mockery::mock(CouponActiveCampaignPayloadBuilder::class);
    $builder->shouldNotReceive('promoReleased');

    $dispatcher = new CouponActiveCampaignDispatcher(
        app(ActiveCampaignDispatchService::class),
        $builder,
    );

    $dispatcher->promoReleased(acB2bRedemption(), 'user_cleared');

    Queue::assertNothingPushed();
});

test('fallo API promo marca failed y relanza', function () {
    Http::fake([
        'https://ac.test/api/3/contacts*' => Http::response(['contacts' => []], 200),
    ]);

    $dispatch = app(ActiveCampaignDispatchService::class)->createOrSkipByIdempotencyKey([
        'event_type' => 'promo_validated',
        'idempotency_key' => 'promo_validated:promo_redemption:3',
        'entity_type' => 'promo_redemption',
        'entity_id' => 3,
        'email' => 'cliente@example.com',
        'user_id' => 10,
        'payload' => [
            'event_type' => 'promo_validated',
            'email' => 'cliente@example.com',
            'user_id' => 10,
            'code' => 'FAM-TEST',
        ],
    ]);

    expect(fn () => (new DispatchActiveCampaignCouponEventJob($dispatch->id))
        ->handle(app(ActiveCampaignService::class)))
        ->toThrow(\App\Exceptions\ActiveCampaignSyncException::class);

    expect($dispatch->fresh()->status)->toBe(ActiveCampaignDispatch::STATUS_FAILED);
});
