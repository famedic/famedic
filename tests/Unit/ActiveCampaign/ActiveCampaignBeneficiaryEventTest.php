<?php

use App\Enums\CouponBeneficiarySource;
use App\Enums\CouponBeneficiaryStatus;
use App\Enums\CouponType;
use App\Jobs\ActiveCampaign\DispatchActiveCampaignCouponEventJob;
use App\Models\ActiveCampaignDispatch;
use App\Models\Coupon;
use App\Models\CouponBeneficiary;
use App\Models\CouponUser;
use App\Models\User;
use App\Services\ActiveCampaign\ActiveCampaignDispatchService;
use App\Services\ActiveCampaign\ActiveCampaignService;
use App\Services\ActiveCampaign\CouponActiveCampaignDispatcher;
use App\Services\ActiveCampaign\CouponActiveCampaignPayloadBuilder;
use App\Services\CouponService;
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
            'promo' => ['validated' => '30', 'used' => '31', 'abandoned' => '32'],
            'authorization' => ['pending' => '40'],
        ],
        'services.activecampaign.fields' => [
            'fm_credito_estado' => '200',
            'fm_credito_monto' => '201',
        ],
    ]);
});

function acB2aBeneficiary(array $overrides = []): CouponBeneficiary
{
    $beneficiary = new CouponBeneficiary(array_merge([
        'parent_coupon_id' => 1,
        'email' => 'pendiente@example.com',
        'email_normalized' => 'pendiente@example.com',
        'first_name' => 'Juan',
        'paternal_lastname' => 'Pérez',
        'status' => CouponBeneficiaryStatus::PendingUser,
        'source' => CouponBeneficiarySource::Manual,
    ], $overrides));
    $beneficiary->id = $overrides['id'] ?? 77;

    $parent = new Coupon([
        'id' => 1,
        'amount_cents' => 30000,
        'type' => CouponType::Balance,
        'description' => 'Campaña prueba',
    ]);
    $beneficiary->setRelation('parentCoupon', $parent);

    return $beneficiary;
}

function fakeAcBeneficiaryApi(int $contactId = 42, array $contactTags = []): void
{
    Http::fake([
        'https://ac.test/api/3/contacts/*/contactTags' => Http::response([
            'contactTags' => $contactTags,
        ], 200),
        'https://ac.test/api/3/contactTags/*' => Http::response([], 200),
        'https://ac.test/api/3/contact/sync' => Http::response([
            'contact' => ['id' => $contactId],
        ], 200),
        'https://ac.test/api/3/contacts*' => Http::response([
            'contacts' => [['id' => $contactId, 'email' => 'pendiente@example.com']],
        ], 200),
        'https://ac.test/api/3/contactTags' => Http::response(['contactTag' => ['id' => 1]], 201),
        'https://ac.test/api/3/fieldValues' => Http::response(['fieldValue' => ['id' => 1]], 201),
    ]);
}

test('pending_beneficiary_created encola dispatch para beneficiario pending_user', function () {
    Queue::fake();

    $builder = Mockery::mock(CouponActiveCampaignPayloadBuilder::class);
    $builder->shouldReceive('idempotencyKeyForPendingCreated')->with(77)->andReturn('pending_beneficiary_created:coupon_beneficiary:77');
    $builder->shouldReceive('pendingBeneficiaryCreated')->once()->andReturn([
        'event_type' => 'pending_beneficiary_created',
        'email' => 'pendiente@example.com',
        'beneficiary_id' => 77,
    ]);

    $dispatcher = new CouponActiveCampaignDispatcher(
        app(ActiveCampaignDispatchService::class),
        $builder,
    );

    $dispatcher->pendingBeneficiaryCreated(acB2aBeneficiary());

    $dispatch = ActiveCampaignDispatch::query()->first();
    expect($dispatch)->not->toBeNull();
    expect($dispatch->event_type)->toBe('pending_beneficiary_created');
    Queue::assertPushed(DispatchActiveCampaignCouponEventJob::class);
});

test('beneficiario assigned no dispara pending_beneficiary_created', function () {
    Queue::fake();

    $builder = Mockery::mock(CouponActiveCampaignPayloadBuilder::class);
    $builder->shouldNotReceive('pendingBeneficiaryCreated');

    $dispatcher = new CouponActiveCampaignDispatcher(
        app(ActiveCampaignDispatchService::class),
        $builder,
    );

    $dispatcher->pendingBeneficiaryCreated(acB2aBeneficiary([
        'status' => CouponBeneficiaryStatus::Assigned,
        'user_id' => 5,
        'child_coupon_id' => 9,
    ]));

    Queue::assertNothingPushed();
});

test('beneficiario cancelado no dispara pending_beneficiary_created', function () {
    Queue::fake();

    $builder = Mockery::mock(CouponActiveCampaignPayloadBuilder::class);
    $builder->shouldNotReceive('pendingBeneficiaryCreated');

    $dispatcher = new CouponActiveCampaignDispatcher(
        app(ActiveCampaignDispatchService::class),
        $builder,
    );

    $dispatcher->pendingBeneficiaryCreated(acB2aBeneficiary([
        'status' => CouponBeneficiaryStatus::Cancelled,
        'cancelled_at' => now(),
    ]));

    Queue::assertNothingPushed();
});

test('email vacío no dispara pending_beneficiary_created', function () {
    Queue::fake();

    $builder = Mockery::mock(CouponActiveCampaignPayloadBuilder::class);
    $builder->shouldNotReceive('pendingBeneficiaryCreated');

    $dispatcher = new CouponActiveCampaignDispatcher(
        app(ActiveCampaignDispatchService::class),
        $builder,
    );

    $dispatcher->pendingBeneficiaryCreated(acB2aBeneficiary(['email' => '']));

    Queue::assertNothingPushed();
});

test('pending_beneficiary_created payload no contiene datos sensibles', function () {
    $couponService = Mockery::mock(CouponService::class);
    $builder = new CouponActiveCampaignPayloadBuilder($couponService);

    $payload = $builder->pendingBeneficiaryCreated(acB2aBeneficiary());

    expect($payload['event_type'])->toBe('pending_beneficiary_created');
    expect($payload['email'])->toBe('pendiente@example.com');
    expect($payload)->not->toHaveKey('validation_token');
    expect($payload)->not->toHaveKey('otp');
    expect($payload)->not->toHaveKey('authorization_code');
});

test('idempotencia evita segundo dispatch pending_beneficiary_created', function () {
    Queue::fake();

    $builder = Mockery::mock(CouponActiveCampaignPayloadBuilder::class);
    $builder->shouldReceive('idempotencyKeyForPendingCreated')->andReturn('pending_beneficiary_created:coupon_beneficiary:77');
    $builder->shouldReceive('pendingBeneficiaryCreated')->twice()->andReturn([
        'event_type' => 'pending_beneficiary_created',
        'email' => 'pendiente@example.com',
    ]);

    $dispatcher = new CouponActiveCampaignDispatcher(
        app(ActiveCampaignDispatchService::class),
        $builder,
    );

    $beneficiary = acB2aBeneficiary();
    $dispatcher->pendingBeneficiaryCreated($beneficiary);
    $dispatcher->pendingBeneficiaryCreated($beneficiary);

    expect(ActiveCampaignDispatch::query()->count())->toBe(1);
    Queue::assertPushed(DispatchActiveCampaignCouponEventJob::class, 1);
});

test('pending_beneficiary_activated encola dispatch y no credit_assigned', function () {
    Queue::fake();

    $user = new User(['id' => 10, 'email' => 'user@example.com']);
    $child = new Coupon(['id' => 50, 'amount_cents' => 30000, 'remaining_cents' => 30000, 'type' => CouponType::Balance]);
    $couponUser = new CouponUser(['coupon_id' => 50, 'user_id' => 10]);
    $couponUser->id = 88;

    $beneficiary = acB2aBeneficiary([
        'status' => CouponBeneficiaryStatus::Assigned,
        'user_id' => 10,
        'child_coupon_id' => 50,
        'activated_at' => now(),
    ]);

    $couponService = Mockery::mock(CouponService::class);
    $couponService->shouldReceive('buildCheckoutCreditPresentation')->andReturn([
        'total_balance_cents' => 30000,
        'applicable_balance_cents' => 30000,
        'conditional_balance_cents' => 0,
    ]);

    $builder = new CouponActiveCampaignPayloadBuilder($couponService);
    $dispatcher = new CouponActiveCampaignDispatcher(
        app(ActiveCampaignDispatchService::class),
        $builder,
    );

    $dispatcher->enqueueBeneficiary(
        eventType: 'pending_beneficiary_activated',
        idempotencyKey: 'pending_beneficiary_activated:coupon_beneficiary:77',
        entityType: 'coupon_beneficiary',
        entityId: 77,
        relatedEntityType: 'coupon',
        relatedEntityId: 50,
        email: 'user@example.com',
        userId: 10,
        customerId: null,
        payload: $builder->pendingBeneficiaryActivated($beneficiary, $user, $child, $couponUser),
    );

    expect(ActiveCampaignDispatch::query()->where('event_type', 'pending_beneficiary_activated')->exists())->toBeTrue();
    expect(ActiveCampaignDispatch::query()->where('event_type', 'credit_assigned')->exists())->toBeFalse();
    Queue::assertPushed(DispatchActiveCampaignCouponEventJob::class);
});

test('job pending_beneficiary_activated remueve pendiente y agrega activado + disponible', function () {
    fakeAcBeneficiaryApi(contactTags: [['id' => 99, 'tag' => 20]]);

    $dispatch = app(ActiveCampaignDispatchService::class)->createOrSkipByIdempotencyKey([
        'event_type' => 'pending_beneficiary_activated',
        'idempotency_key' => 'pending_beneficiary_activated:coupon_beneficiary:1',
        'entity_type' => 'coupon_beneficiary',
        'entity_id' => 1,
        'email' => 'user@example.com',
        'user_id' => 10,
        'payload' => [
            'event_type' => 'pending_beneficiary_activated',
            'email' => 'user@example.com',
            'user_id' => 10,
            'amount_cents' => 30000,
            'remaining_cents' => 30000,
        ],
    ]);

    (new DispatchActiveCampaignCouponEventJob($dispatch->id))->handle(app(ActiveCampaignService::class));

    expect($dispatch->fresh()->status)->toBe(ActiveCampaignDispatch::STATUS_SYNCED);

    Http::assertSent(fn ($request) => $request->method() === 'DELETE'
        && str_contains($request->url(), '/contactTags/99'));

    Http::assertSent(fn ($request) => $request->method() === 'POST'
        && str_contains($request->url(), '/contactTags')
        && ($request['contactTag']['tag'] ?? null) == 21);

    Http::assertSent(fn ($request) => $request->method() === 'POST'
        && str_contains($request->url(), '/contactTags')
        && ($request['contactTag']['tag'] ?? null) == 10);
});

test('job pending_beneficiary_cancelled remueve pendiente y marca cerrado', function () {
    fakeAcBeneficiaryApi(contactTags: [['id' => 98, 'tag' => 20]]);

    $dispatch = app(ActiveCampaignDispatchService::class)->createOrSkipByIdempotencyKey([
        'event_type' => 'pending_beneficiary_cancelled',
        'idempotency_key' => 'pending_beneficiary_cancelled:coupon_beneficiary:2',
        'entity_type' => 'coupon_beneficiary',
        'entity_id' => 2,
        'email' => 'pendiente@example.com',
        'payload' => [
            'event_type' => 'pending_beneficiary_cancelled',
            'email' => 'pendiente@example.com',
            'first_name' => 'Ana',
            'paternal_lastname' => 'López',
        ],
    ]);

    (new DispatchActiveCampaignCouponEventJob($dispatch->id))->handle(app(ActiveCampaignService::class));

    expect($dispatch->fresh()->status)->toBe(ActiveCampaignDispatch::STATUS_SYNCED);

    Http::assertSent(fn ($request) => $request->method() === 'POST'
        && str_contains($request->url(), '/contactTags')
        && ($request['contactTag']['tag'] ?? null) == 15);
});

test('fallo API en beneficiario marca failed y relanza', function () {
    Http::fake([
        'https://ac.test/api/3/contacts*' => Http::response(['contacts' => []], 200),
    ]);

    $dispatch = app(ActiveCampaignDispatchService::class)->createOrSkipByIdempotencyKey([
        'event_type' => 'pending_beneficiary_created',
        'idempotency_key' => 'pending_beneficiary_created:coupon_beneficiary:9',
        'entity_type' => 'coupon_beneficiary',
        'entity_id' => 9,
        'email' => 'x@example.com',
        'payload' => [
            'event_type' => 'pending_beneficiary_created',
            'email' => 'x@example.com',
            'first_name' => 'Test',
        ],
    ]);

    expect(fn () => (new DispatchActiveCampaignCouponEventJob($dispatch->id))
        ->handle(app(ActiveCampaignService::class)))
        ->toThrow(\App\Exceptions\ActiveCampaignSyncException::class);

    expect($dispatch->fresh()->status)->toBe(ActiveCampaignDispatch::STATUS_FAILED);
});
