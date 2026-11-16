<?php

use App\Contracts\Otp\OtpCodeGenerator;
use App\Models\OtpChallenge;
use App\Models\OtpDeliveryOperation;
use App\Models\OtpMovementEvent;
use App\Models\User;
use App\Services\Otp\Delivery\FakeOtpDeliveryProvider;
use App\Support\Api\V1\AkubicaCorrelationId;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;
use Tests\Support\Database\Rc2IsolatedDatabase;
use Tests\Support\Otp\FakeOtpCodeGenerator;

/*
 * SMS-3 lote 20261115C regression: valid non-UUID X-Correlation-ID values
 * (8..128 chars, /^[A-Za-z0-9._-]+$/) must be persisted verbatim in
 * otp_delivery_operations.correlation_id and the login request must return 202.
 */

uses(Rc2IsolatedDatabase::class);

function corridMaxLengthValue(): string
{
    $prefix = 'rc2-max-';

    return $prefix.str_repeat('a', AkubicaCorrelationId::MAX_LENGTH - strlen($prefix));
}

beforeEach(function () {
    $this->resetRc2IsolatedState();
    Carbon::setTestNow(Carbon::parse('2026-11-15 21:00:00'));
    Notification::fake();
    config()->set('akubica_connectors.otp_enrollment', ['enabled' => false, 'certified' => false]);
    disableAllAkubicaOtpFeatures();
    config()->set('otp.p0a.policy.max_attempts', 5);
    config()->set('otp.p0a.policy.ttl_minutes', 5);
    config()->set('otp.p0a.policy.cooldown_seconds', 60);
    enableLoginOtpWithFakeDelivery();
    $this->app->instance(OtpCodeGenerator::class, new FakeOtpCodeGenerator('246802'));

    User::factory()->withRegularCustomer()->create([
        'phone' => '5512345678',
        'phone_country' => 'MX',
        'phone_verified_at' => now(),
        'email' => 'corrid.sintetico@ejemplo.test',
    ]);
});

afterEach(function () {
    Carbon::setTestNow();
    disableAllAkubicaOtpFeatures();
});

test('corrid otp_delivery_operations.correlation_id is wide enough for the contractual maximum', function () {
    $column = collect(DB::select(
        "SELECT DATA_TYPE AS data_type, CHARACTER_MAXIMUM_LENGTH AS len, IS_NULLABLE AS nullable
           FROM information_schema.COLUMNS
          WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'otp_delivery_operations' AND COLUMN_NAME = 'correlation_id'"
    ))->sole();

    expect(strtolower($column->data_type))->toBe('varchar')
        ->and((int) $column->len)->toBeGreaterThanOrEqual(AkubicaCorrelationId::MAX_LENGTH)
        ->and($column->nullable)->toBe('NO');
});

test('corrid otp_movement_events.movement_key fits the corr: prefix plus MAX_LENGTH and keeps its index', function () {
    $column = collect(DB::select(
        "SELECT DATA_TYPE AS data_type, CHARACTER_MAXIMUM_LENGTH AS len, IS_NULLABLE AS nullable
           FROM information_schema.COLUMNS
          WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'otp_movement_events' AND COLUMN_NAME = 'movement_key'"
    ))->sole();
    $indexed = DB::select(
        "SELECT 1 FROM information_schema.STATISTICS
          WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'otp_movement_events' AND COLUMN_NAME = 'movement_key'"
    );

    expect(strtolower($column->data_type))->toBe('varchar')
        ->and((int) $column->len)->toBeGreaterThanOrEqual(strlen('corr:') + AkubicaCorrelationId::MAX_LENGTH)
        ->and($column->nullable)->toBe('NO')
        ->and($indexed)->not->toBeEmpty();
});

test('corrid login request persists the exact X-Correlation-ID in the delivery operation', function (string $correlationId) {
    expect(AkubicaCorrelationId::isValid($correlationId))->toBeTrue();

    $this->withHeaders(['X-Correlation-ID' => $correlationId])
        ->postJson('/api/v1/auth/login/request-code', ['phone' => '+525512345678'])
        ->assertStatus(202)
        ->assertJsonPath('data.requires_otp', true);

    $challenge = OtpChallenge::query()->sole();
    $operation = OtpDeliveryOperation::query()->where('otp_challenge_id', $challenge->id)->sole();

    expect($challenge->invalidated_at)->toBeNull()
        ->and($operation->status)->toBe('sms_accepted')
        ->and($operation->correlation_id)->toBe($correlationId)
        ->and(strlen($operation->correlation_id))->toBe(strlen($correlationId))
        ->and(app(FakeOtpDeliveryProvider::class)->sent)->toHaveCount(1)
        ->and(OtpMovementEvent::query()->where('correlation_id', $correlationId)->exists())->toBeTrue()
        ->and(OtpMovementEvent::query()->where('movement_key', 'corr:'.$correlationId)->exists())->toBeTrue();
})->with([
    'uuid (36)' => fn () => (string) Str::uuid(),
    'non-uuid runner id (45)' => fn () => 'rc2-sms3-'.Str::uuid(),
    'exactly MAX_LENGTH (128)' => fn () => corridMaxLengthValue(),
]);

test('corrid max length helper really is MAX_LENGTH', function () {
    expect(strlen(corridMaxLengthValue()))->toBe(AkubicaCorrelationId::MAX_LENGTH)
        ->and(AkubicaCorrelationId::MAX_LENGTH)->toBe(128)
        ->and(AkubicaCorrelationId::MIN_LENGTH)->toBe(8);
});
