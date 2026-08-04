<?php

use App\Models\ActiveCampaignDispatch;
use App\Services\ActiveCampaign\ActiveCampaignDispatchService;
use App\Services\ActiveCampaign\ActiveCampaignEventCenterService;
use App\Support\ActiveCampaign\ActiveCampaignDashboardFilter;
use Carbon\Carbon;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Http\Request;
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

test('dashboard filter limita el rango a 90 días', function () {
    $request = Request::create('/admin/activecampaign', 'GET', [
        'start_date' => '2025-01-01',
        'end_date' => '2025-12-31',
    ]);

    $filter = ActiveCampaignDashboardFilter::fromRequest($request);

    expect($filter->startLocal->diffInDays($filter->endLocal))->toBeLessThanOrEqual(89);
});

test('dispatch sanitizePayloadForLog oculta claves sensibles', function () {
    $service = app(ActiveCampaignDispatchService::class);

    $sanitized = $service->sanitizePayloadForLog([
        'amount_cents' => 1000,
        'email' => 'ops@example.com',
        'token' => 'abc123',
        'nested' => [
            'password' => 'x',
            'currency' => 'MXN',
        ],
    ]);

    expect($sanitized['amount_cents'])->toBe(1000);
    expect($sanitized['email'])->toBe('ops@example.com');
    expect($sanitized['token'])->toBe('[redacted]');
    expect($sanitized['nested']['password'])->toBe('[redacted]');
    expect($sanitized['nested']['currency'])->toBe('MXN');
});

test('event center detail sanitiza payload y error', function () {
    $dispatch = ActiveCampaignDispatch::query()->create([
        'event_type' => 'credit_assigned',
        'entity_type' => 'coupon_user',
        'entity_id' => 1,
        'idempotency_key' => 'mi-consolidation:credit:1',
        'status' => ActiveCampaignDispatch::STATUS_FAILED,
        'email' => 'ops@example.com',
        'last_error' => "/var/www/app/Jobs/Fail.php:99\nStacktrace line 2 with secret token=abc",
        'payload' => [
            'amount_cents' => 50000,
            'email' => 'patient@example.com',
            'token' => 'should-hide',
            'currency' => 'MXN',
            'secret_note' => 'no',
        ],
    ]);

    $detail = app(ActiveCampaignEventCenterService::class)
        ->buildEventDetail('ac-dispatch-'.$dispatch->id);

    expect($detail)->not->toBeNull();
    expect($detail['payload'])->toHaveKey('amount_cents');
    expect($detail['payload'])->toHaveKey('currency');
    expect($detail['payload'])->not->toHaveKey('email');
    expect($detail['payload'])->not->toHaveKey('token');
    expect($detail['payload'])->not->toHaveKey('secret_note');
    expect($detail['last_error'])->not->toContain('/var/www');
    expect($detail['last_error'])->not->toContain('Stacktrace');
});

test('event center omite tipos contact-only sin paciente', function () {
    $request = Request::create('/admin/activecampaign/events', 'GET', [
        'type' => 'membership',
        'start_date' => Carbon::now('America/Monterrey')->subDays(6)->toDateString(),
        'end_date' => Carbon::now('America/Monterrey')->toDateString(),
    ]);

    $result = app(ActiveCampaignEventCenterService::class)->buildEvents($request);

    expect($result['items'])->toBe([]);
    expect($result['total'])->toBe(0);
});

test('event center lista dispatches del periodo sin payload', function () {
    ActiveCampaignDispatch::query()->create([
        'event_type' => 'promo_used',
        'entity_type' => 'coupon_user',
        'entity_id' => 2,
        'idempotency_key' => 'mi-consolidation:promo:2',
        'status' => ActiveCampaignDispatch::STATUS_SYNCED,
        'email' => 'ops@example.com',
        'payload' => ['token' => 'hidden', 'amount_cents' => 10],
        'synced_at' => now(),
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $request = Request::create('/admin/activecampaign/events', 'GET', [
        'start_date' => Carbon::now('America/Monterrey')->subDays(6)->toDateString(),
        'end_date' => Carbon::now('America/Monterrey')->toDateString(),
    ]);

    $result = app(ActiveCampaignEventCenterService::class)->buildEvents($request);
    $dispatchRows = collect($result['items'])->where('type', 'activecampaign_dispatch');

    expect($dispatchRows->isNotEmpty())->toBeTrue();
    expect($dispatchRows->first())->not->toHaveKey('payload');
});
