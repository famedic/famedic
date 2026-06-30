<?php

use App\Enums\CouponApprovalStatus;
use App\Enums\CouponType;
use App\Jobs\ActiveCampaign\DispatchActiveCampaignCouponEventJob;
use App\Models\ActiveCampaignDispatch;
use App\Models\Coupon;
use App\Models\CouponUser;
use App\Models\User;
use App\Services\ActiveCampaign\ActiveCampaignDispatchService;
use App\Services\ActiveCampaign\ActiveCampaignService;
use App\Services\ActiveCampaign\CouponActiveCampaignDispatcher;
use App\Services\ActiveCampaign\CouponActiveCampaignPayloadBuilder;
use App\Services\CouponService;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Schema;

beforeEach(function () {
    if (! Schema::hasTable('users')) {
        Schema::create('users', function (Blueprint $table) {
            $table->id();
            $table->string('email')->nullable();
            $table->string('name')->nullable();
            $table->timestamps();
        });
    }

    if (! Schema::hasTable('coupons')) {
        Schema::create('coupons', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('parent_coupon_id')->nullable();
            $table->unsignedInteger('amount_cents');
            $table->unsignedInteger('remaining_cents');
            $table->string('type')->default('balance');
            $table->boolean('is_active')->default(true);
            $table->string('approval_status')->default('active');
            $table->timestamp('valid_from')->nullable();
            $table->timestamp('expires_at')->nullable();
            $table->unsignedInteger('min_purchase_cents')->nullable();
            $table->timestamps();
        });
    }

    if (! Schema::hasTable('coupon_user')) {
        Schema::create('coupon_user', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('coupon_id');
            $table->unsignedBigInteger('user_id');
            $table->timestamp('assigned_at')->nullable();
            $table->timestamp('used_at')->nullable();
        });
    }

    if (! Schema::hasTable('coupon_transactions')) {
        Schema::create('coupon_transactions', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('coupon_id');
            $table->unsignedBigInteger('user_id');
            $table->string('purchase_type', 32);
            $table->unsignedBigInteger('purchase_id');
            $table->unsignedInteger('amount_used_cents');
            $table->timestamp('created_at')->nullable();
            $table->timestamp('reversed_at')->nullable();
        });
    }

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

    \Illuminate\Support\Facades\DB::table('coupon_transactions')->delete();
    \Illuminate\Support\Facades\DB::table('coupon_user')->delete();
    \Illuminate\Support\Facades\DB::table('coupons')->delete();
    \Illuminate\Support\Facades\DB::table('users')->delete();
    ActiveCampaignDispatch::query()->delete();

    config([
        'services.activecampaign.enabled' => true,
        'services.activecampaign.coupons_enabled' => true,
        'services.activecampaign.coupons_expiring_enabled' => true,
        'services.activecampaign.coupons_expiring_days' => 14,
        'services.activecampaign.endpoint' => 'https://ac.test',
        'services.activecampaign.token' => 'token-test',
        'services.activecampaign.tags' => [
            'credit' => [
                'available' => '10',
                'expiring' => '11',
                'used' => '12',
                'closed' => '15',
            ],
        ],
        'services.activecampaign.fields' => [
            'fm_credito_estado' => '200',
            'fm_credito_restante' => '201',
            'fm_credito_expira_at' => '202',
            'fm_saldo_total' => '203',
        ],
    ]);
});

function b2cSeedExpiringAssignment(array $couponOverrides = [], array $userOverrides = [], array $assignmentOverrides = []): CouponUser
{
    $userId = User::query()->insertGetId(array_merge([
        'email' => 'cliente@example.com',
        'name' => 'Cliente',
        'created_at' => now(),
        'updated_at' => now(),
    ], $userOverrides));

    $couponId = Coupon::query()->insertGetId(array_merge([
        'amount_cents' => 10000,
        'remaining_cents' => 10000,
        'type' => CouponType::Balance->value,
        'is_active' => true,
        'approval_status' => CouponApprovalStatus::Active->value,
        'expires_at' => now()->addDays(7),
        'created_at' => now(),
        'updated_at' => now(),
    ], $couponOverrides));

    $assignmentId = CouponUser::query()->insertGetId(array_merge([
        'coupon_id' => $couponId,
        'user_id' => $userId,
        'assigned_at' => now(),
        'used_at' => null,
    ], $assignmentOverrides));

    return CouponUser::query()->with(['coupon', 'user'])->findOrFail($assignmentId);
}

test('dry-run no crea dispatch', function () {
    Queue::fake();
    b2cSeedExpiringAssignment();

    Artisan::call('activecampaign:sync-expiring-coupons', ['--dry-run' => true]);

    expect(ActiveCampaignDispatch::query()->count())->toBe(0);
    Queue::assertNothingPushed();
});

test('flag expiring apagado no ejecuta salvo force', function () {
    Queue::fake();
    config(['services.activecampaign.coupons_expiring_enabled' => false]);
    b2cSeedExpiringAssignment();

    Artisan::call('activecampaign:sync-expiring-coupons');

    expect(ActiveCampaignDispatch::query()->count())->toBe(0);
    Queue::assertNothingPushed();

    Artisan::call('activecampaign:sync-expiring-coupons', ['--force' => true]);

    expect(ActiveCampaignDispatch::query()->count())->toBe(1);
    Queue::assertPushed(DispatchActiveCampaignCouponEventJob::class);
});

test('--days filtra correctamente', function () {
    b2cSeedExpiringAssignment(['expires_at' => now()->addDays(20)]);
    b2cSeedExpiringAssignment(['expires_at' => now()->addDays(5)], ['email' => 'otro@example.com']);

    Artisan::call('activecampaign:sync-expiring-coupons', [
        '--days' => '7',
        '--dry-run' => true,
    ]);

    expect(Artisan::output())->toMatch('/eligible\s+\|\s+1\b/');
    expect(ActiveCampaignDispatch::query()->count())->toBe(0);
});

test('--limit limita dispatches', function () {
    Queue::fake();
    ActiveCampaignDispatch::query()->delete();

    b2cSeedExpiringAssignment([], ['email' => 'a@example.com']);
    b2cSeedExpiringAssignment([], ['email' => 'b@example.com']);

    $exitCode = Artisan::call('activecampaign:sync-expiring-coupons', [
        '--limit' => '1',
    ]);

    expect($exitCode)->toBe(0);
    expect(Artisan::output())->toMatch('/dispatches_created\s+\|\s+1\b/');
    expect(ActiveCampaignDispatch::query()->count())->toBe(1);
});

test('omite usado', function () {
    Queue::fake();
    b2cSeedExpiringAssignment([], [], ['used_at' => now()]);

    Artisan::call('activecampaign:sync-expiring-coupons');

    expect(ActiveCampaignDispatch::query()->count())->toBe(0);
    Queue::assertNothingPushed();
});

test('omite vencido', function () {
    Queue::fake();
    b2cSeedExpiringAssignment(['expires_at' => now()->subDay()]);

    Artisan::call('activecampaign:sync-expiring-coupons', ['--days' => 14]);

    expect(ActiveCampaignDispatch::query()->count())->toBe(0);
});

test('omite sin email', function () {
    Queue::fake();
    b2cSeedExpiringAssignment([], ['email' => '']);

    Artisan::call('activecampaign:sync-expiring-coupons');

    expect(ActiveCampaignDispatch::query()->count())->toBe(0);
});

test('omite sin remaining_cents', function () {
    Queue::fake();
    b2cSeedExpiringAssignment(['remaining_cents' => 0]);

    Artisan::call('activecampaign:sync-expiring-coupons');

    expect(ActiveCampaignDispatch::query()->count())->toBe(0);
});

test('crea dispatch para elegible', function () {
    Queue::fake();
    $assignment = b2cSeedExpiringAssignment();

    Artisan::call('activecampaign:sync-expiring-coupons');

    $dispatch = ActiveCampaignDispatch::query()->first();
    expect($dispatch)->not->toBeNull();
    expect($dispatch->event_type)->toBe('credit_expiring');
    expect($dispatch->entity_id)->toBe($assignment->id);
    expect($dispatch->idempotency_key)->toBe("credit_expiring:coupon_user:{$assignment->id}");
    Queue::assertPushed(DispatchActiveCampaignCouponEventJob::class);
});

test('idempotencia evita duplicado', function () {
    Queue::fake();
    b2cSeedExpiringAssignment();

    Artisan::call('activecampaign:sync-expiring-coupons');
    Artisan::call('activecampaign:sync-expiring-coupons');

    expect(ActiveCampaignDispatch::query()->count())->toBe(1);
    Queue::assertPushed(DispatchActiveCampaignCouponEventJob::class, 1);
});

test('payload contiene expires_at y days_to_expire sin datos sensibles', function () {
    $couponService = Mockery::mock(CouponService::class);
    $couponService->shouldReceive('buildCheckoutCreditPresentation')->andReturn([
        'total_balance_cents' => 10000,
        'applicable_balance_cents' => 10000,
        'conditional_balance_cents' => 0,
    ]);

    $builder = new CouponActiveCampaignPayloadBuilder($couponService);
    $assignment = b2cSeedExpiringAssignment();

    $payload = $builder->creditExpiring($assignment);

    expect($payload['event_type'])->toBe('credit_expiring');
    expect($payload['expires_at'])->not->toBeNull();
    expect($payload['days_to_expire'])->toBe(7);
    expect($payload)->not->toHaveKey('validation_token');
    expect($payload)->not->toHaveKey('otp');
    expect($payload)->not->toHaveKey('authorization_code');
});

test('job credit_expiring agrega tag por vencer y estado por_vencer', function () {
    Http::fake([
        'https://ac.test/api/3/contacts*' => Http::response([
            'contacts' => [['id' => 42, 'email' => 'cliente@example.com']],
        ], 200),
        'https://ac.test/api/3/contactTags' => Http::response(['contactTag' => ['id' => 1]], 201),
        'https://ac.test/api/3/contacts/*/contactTags' => Http::response(['contactTags' => []], 200),
        'https://ac.test/api/3/fieldValues' => Http::response(['fieldValue' => ['id' => 1]], 201),
    ]);

    $dispatch = app(ActiveCampaignDispatchService::class)->createOrSkipByIdempotencyKey([
        'event_type' => 'credit_expiring',
        'idempotency_key' => 'credit_expiring:coupon_user:99',
        'entity_type' => 'coupon_user',
        'entity_id' => 99,
        'email' => 'cliente@example.com',
        'payload' => [
            'event_type' => 'credit_expiring',
            'email' => 'cliente@example.com',
            'user_id' => 1,
            'remaining_cents' => 10000,
            'expires_at' => now()->addDays(7)->toIso8601String(),
            'saldo_total_cents' => 10000,
        ],
    ]);

    (new DispatchActiveCampaignCouponEventJob($dispatch->id))->handle(app(ActiveCampaignService::class));

    expect($dispatch->fresh()->status)->toBe(ActiveCampaignDispatch::STATUS_SYNCED);

    Http::assertSent(fn ($request) => $request->method() === 'POST'
        && str_contains($request->url(), '/contactTags')
        && ($request['contactTag']['tag'] ?? null) == 11);

    Http::assertSent(fn ($request) => $request->method() === 'POST'
        && str_contains($request->url(), '/fieldValues')
        && ($request['fieldValue']['field'] ?? null) == 200
        && ($request['fieldValue']['value'] ?? null) === 'por_vencer');
});

test('fallo API credit_expiring marca failed y relanza', function () {
    Http::fake([
        'https://ac.test/api/3/contacts*' => Http::response(['contacts' => []], 200),
    ]);

    $dispatch = app(ActiveCampaignDispatchService::class)->createOrSkipByIdempotencyKey([
        'event_type' => 'credit_expiring',
        'idempotency_key' => 'credit_expiring:coupon_user:500',
        'entity_type' => 'coupon_user',
        'entity_id' => 500,
        'email' => 'cliente@example.com',
        'payload' => [
            'event_type' => 'credit_expiring',
            'email' => 'cliente@example.com',
            'remaining_cents' => 5000,
        ],
    ]);

    expect(fn () => (new DispatchActiveCampaignCouponEventJob($dispatch->id))
        ->handle(app(ActiveCampaignService::class)))
        ->toThrow(\App\Exceptions\ActiveCampaignSyncException::class);

    expect($dispatch->fresh()->status)->toBe(ActiveCampaignDispatch::STATUS_FAILED);
});

test('ExpiringCouponCandidateQuery idempotency key es una vez por cupón', function () {
    $builder = new CouponActiveCampaignPayloadBuilder(Mockery::mock(CouponService::class));

    expect($builder->idempotencyKeyForExpiring(42))->toBe('credit_expiring:coupon_user:42');
});

test('dispatchCreditExpiring respeta elegibilidad', function () {
    Queue::fake();
    $assignment = b2cSeedExpiringAssignment(['remaining_cents' => 0]);

    app(CouponActiveCampaignDispatcher::class)->dispatchCreditExpiring($assignment, true);

    expect(ActiveCampaignDispatch::query()->count())->toBe(0);
    Queue::assertNothingPushed();
});
