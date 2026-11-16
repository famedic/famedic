<?php

use App\Contracts\Otp\OtpCodeGenerator;
use App\Exceptions\Otp\OtpDeliveryFailedException;
use App\Models\OtpChallenge;
use App\Models\OtpDeliveryOperation;
use App\Models\User;
use App\Services\Otp\AkubicaLoginOtpService;
use App\Services\Otp\Delivery\AkubicaSecureOtpDeliveryOrchestrator;
use App\Services\Otp\Delivery\FakeOtpDeliveryProvider;
use App\Services\Otp\Delivery\OtpDeliveryOutcome;
use App\Services\Otp\Delivery\OtpDeliveryReservationStore;
use App\Services\Otp\StepUp\AkubicaStepUpOtpService;
use Illuminate\Database\QueryException;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Log\Events\MessageLogged;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Notification;
use Tests\Support\Database\Rc2IsolatedDatabase;
use Tests\Support\Otp\FakeOtpCodeGenerator;

/*
 * SMS-3 lote 20261115B regression: login request returned 202 with a pending
 * challenge although no otp_delivery_operations row existed and the provider
 * was never called (DuplicateSuppressed treated as success). Login/step-up
 * must fail closed unless a delivery for that challenge was really accepted.
 */

uses(Rc2IsolatedDatabase::class);

const DUPFIX_PHONE_NATIONAL = '5512345678';
const DUPFIX_PHONE_E164 = '+525512345678';
const DUPFIX_CODE = '135791';

function dupfixRefusingReservationStore(): OtpDeliveryReservationStore
{
    return new class implements OtpDeliveryReservationStore
    {
        public function reserve(string $operationKey, int $ttlSeconds): bool
        {
            return false; // reservation already held (e.g. stale Redis key)
        }

        public function release(string $operationKey): void {}

        public function markAccepted(string $operationKey, int $ttlSeconds): void {}

        public function isAccepted(string $operationKey): bool
        {
            return false;
        }

        public function assertAvailable(): void {}
    };
}

function dupfixUser(): User
{
    return User::factory()->withRegularCustomer()->create([
        'phone' => DUPFIX_PHONE_NATIONAL,
        'phone_country' => 'MX',
        'phone_verified_at' => now(),
        'email' => 'dupfix.sintetico@ejemplo.test',
    ]);
}

function dupfixPdoException(string $sqlState, int $driverCode): PDOException
{
    return new class($sqlState, $driverCode) extends PDOException
    {
        public function __construct(string $sqlState, int $driverCode)
        {
            parent::__construct("SQLSTATE[{$sqlState}]: synthetic failure");
            $this->code = $sqlState;
            $this->errorInfo = [$sqlState, $driverCode, 'synthetic failure'];
        }
    };
}

/** @return array<int, MessageLogged> */
function &dupfixCaptureLogs(): array
{
    $logs = [];
    app('events')->listen(MessageLogged::class, function (MessageLogged $event) use (&$logs): void {
        $logs[] = $event;
    });

    return $logs;
}

/** @param array<int, MessageLogged> $logs */
function dupfixLogsNamed(array $logs, string $message): array
{
    return array_values(array_filter($logs, fn (MessageLogged $e): bool => $e->message === $message));
}

/** @param array<int, MessageLogged> $logs */
function dupfixAssertNoPii(array $logs): void
{
    $blob = json_encode(array_map(fn (MessageLogged $e) => [$e->message, $e->context], $logs));
    expect($blob)->not->toContain(DUPFIX_CODE)
        ->and($blob)->not->toContain(DUPFIX_PHONE_NATIONAL)
        ->and($blob)->not->toContain('dupfix.sintetico@ejemplo.test');
}

function dupfixInvokeDispatch(object $service, OtpChallenge $challenge): void
{
    (new ReflectionMethod($service, 'dispatchDelivery'))->invoke($service, $challenge, DUPFIX_CODE, DUPFIX_PHONE_E164);
}

beforeEach(function () {
    $this->resetRc2IsolatedState();
    Carbon::setTestNow(Carbon::parse('2026-11-15 18:00:00'));
    Notification::fake();
    config()->set('akubica_connectors.otp_enrollment', ['enabled' => false, 'certified' => false]);
    disableAllAkubicaOtpFeatures();
    config()->set('otp.p0a.policy.max_attempts', 5);
    config()->set('otp.p0a.policy.ttl_minutes', 5);
    config()->set('otp.p0a.policy.cooldown_seconds', 60);
    enableLoginOtpWithFakeDelivery();
    $this->app->instance(OtpCodeGenerator::class, new FakeOtpCodeGenerator(DUPFIX_CODE));
});

afterEach(function () {
    OtpDeliveryOperation::flushEventListeners();
    Carbon::setTestNow();
    disableAllAkubicaOtpFeatures();
});

test('dupfix normal login request path is unchanged: 202, one accepted operation, one sms', function () {
    $logs = &dupfixCaptureLogs();
    dupfixUser();

    $this->postJson('/api/v1/auth/login/request-code', ['phone' => DUPFIX_PHONE_E164])
        ->assertStatus(202)
        ->assertJsonPath('data.requires_otp', true);

    $challenge = OtpChallenge::query()->sole();
    expect($challenge->invalidated_at)->toBeNull()
        ->and(OtpDeliveryOperation::query()->where('otp_challenge_id', $challenge->id)->where('status', 'sms_accepted')->count())->toBe(1)
        ->and(app(FakeOtpDeliveryProvider::class)->sent)->toHaveCount(1)
        ->and(dupfixLogsNamed($logs, 'otp_delivery_duplicate_suppressed_without_operation'))->toBeEmpty()
        ->and(dupfixLogsNamed($logs, 'otp_delivery_operation_persist_failed'))->toBeEmpty();
});

test('dupfix pre-existing reservation without operation fails login request and invalidates challenge', function () {
    $logs = &dupfixCaptureLogs();
    dupfixUser();
    app()->instance(OtpDeliveryReservationStore::class, dupfixRefusingReservationStore());
    refreshAkubicaOtpDeliveryBinding();

    $this->postJson('/api/v1/auth/login/request-code', ['phone' => DUPFIX_PHONE_E164])
        ->assertStatus(503)
        ->assertJsonPath('error.code', 'DELIVERY_FAILED');

    $challenge = OtpChallenge::query()->sole();
    expect($challenge->invalidated_at)->not->toBeNull()
        ->and($challenge->invalidated_reason)->toBe('delivery_failed')
        ->and(OtpDeliveryOperation::query()->count())->toBe(0)
        ->and(app(FakeOtpDeliveryProvider::class)->sent)->toHaveCount(0);

    $events = dupfixLogsNamed($logs, 'otp_delivery_duplicate_suppressed_without_operation');
    expect($events)->not->toBeEmpty();
    $warning = collect($events)->first(fn (MessageLogged $e) => $e->level === 'warning');
    expect($warning)->not->toBeNull()
        ->and($warning->context['challenge_public_id'])->toBe((string) $challenge->public_id)
        ->and($warning->context['purpose'])->toBe('akubica_login');
    dupfixAssertNoPii($logs);
});

test('dupfix pre-existing reservation without operation fails login resend and invalidates the new challenge', function () {
    dupfixUser();
    $firstId = $this->postJson('/api/v1/auth/login/request-code', ['phone' => DUPFIX_PHONE_E164])
        ->assertStatus(202)->json('data.challenge_id');

    Carbon::setTestNow(now()->addSeconds(61));
    app()->instance(OtpDeliveryReservationStore::class, dupfixRefusingReservationStore());
    refreshAkubicaOtpDeliveryBinding();

    $this->postJson('/api/v1/auth/login/resend-code', ['challenge_id' => $firstId])
        ->assertStatus(503)
        ->assertJsonPath('error.code', 'DELIVERY_FAILED');

    $newest = OtpChallenge::query()->where('public_id', '!=', $firstId)->sole();
    expect($newest->invalidated_reason)->toBe('delivery_failed')
        ->and(OtpDeliveryOperation::query()->where('otp_challenge_id', $newest->id)->count())->toBe(0)
        ->and(app(FakeOtpDeliveryProvider::class)->sent)->toHaveCount(1)
        ->and(OtpChallenge::query()->whereNull('invalidated_at')->whereNull('consumed_at')->count())->toBe(0);
});

test('dupfix non-unique QueryException on operation insert returns Failed, is logged without PII and fails login', function () {
    $logs = &dupfixCaptureLogs();
    dupfixUser();
    OtpDeliveryOperation::creating(function (): void {
        throw new QueryException('mysql', 'insert into `otp_delivery_operations` (...) values (...)', [], dupfixPdoException('HY000', 1364));
    });

    $this->postJson('/api/v1/auth/login/request-code', ['phone' => DUPFIX_PHONE_E164])
        ->assertStatus(503)
        ->assertJsonPath('error.code', 'DELIVERY_FAILED');

    $challenge = OtpChallenge::query()->sole();
    expect($challenge->invalidated_reason)->toBe('delivery_failed')
        ->and(OtpDeliveryOperation::query()->count())->toBe(0)
        ->and(app(FakeOtpDeliveryProvider::class)->sent)->toHaveCount(0);

    $persist = collect(dupfixLogsNamed($logs, 'otp_delivery_operation_persist_failed'))
        ->first(fn (MessageLogged $e) => $e->level === 'warning');
    expect($persist)->not->toBeNull()
        ->and($persist->context['exception_class'])->toBe(QueryException::class)
        ->and($persist->context['sqlstate'])->toBe('HY000')
        ->and($persist->context['purpose'])->toBe('akubica_login')
        ->and($persist->context['challenge_public_id'])->toBe((string) $challenge->public_id)
        ->and($persist->context)->not->toHaveKeys(['phone', 'code', 'email', 'destination']);
    dupfixAssertNoPii($logs);
});

test('dupfix orchestrator returns Failed for QueryException and for unique violation without existing operation', function () {
    dupfixUser();
    $this->postJson('/api/v1/auth/login/request-code', ['phone' => DUPFIX_PHONE_E164])->assertStatus(202);
    $challenge = OtpChallenge::query()->sole();
    OtpDeliveryOperation::query()->delete();

    OtpDeliveryOperation::creating(function (): void {
        throw new QueryException('mysql', 'insert', [], dupfixPdoException('42S22', 1054));
    });
    expect(app(AkubicaSecureOtpDeliveryOrchestrator::class)
        ->deliverLoginSafely($challenge, DUPFIX_CODE, DUPFIX_PHONE_E164, 'corr-dupfix-1'))
        ->toBe(OtpDeliveryOutcome::Failed);

    OtpDeliveryOperation::flushEventListeners();
    OtpDeliveryOperation::creating(function (): void {
        throw new UniqueConstraintViolationException('mysql', 'insert', [], dupfixPdoException('23000', 1062));
    });
    expect(app(AkubicaSecureOtpDeliveryOrchestrator::class)
        ->deliverLoginSafely($challenge, DUPFIX_CODE, DUPFIX_PHONE_E164, 'corr-dupfix-2'))
        ->toBe(OtpDeliveryOutcome::Failed)
        ->and(app(FakeOtpDeliveryProvider::class)->sent)->toHaveCount(1);
});

test('dupfix genuine duplicate with existing accepted operation is still success (unique violation path)', function () {
    $logs = &dupfixCaptureLogs();
    dupfixUser();
    $this->postJson('/api/v1/auth/login/request-code', ['phone' => DUPFIX_PHONE_E164])->assertStatus(202);
    $challenge = OtpChallenge::query()->sole();

    // Re-deliver the same challenge: the operation row already exists and was accepted.
    expect(app(AkubicaSecureOtpDeliveryOrchestrator::class)
        ->deliverLoginSafely($challenge, DUPFIX_CODE, DUPFIX_PHONE_E164, 'corr-dupfix-3'))
        ->toBe(OtpDeliveryOutcome::DuplicateSuppressed);

    dupfixInvokeDispatch(app(AkubicaLoginOtpService::class), $challenge);

    expect($challenge->fresh()->invalidated_at)->toBeNull()
        ->and(OtpDeliveryOperation::query()->count())->toBe(1)
        ->and(app(FakeOtpDeliveryProvider::class)->sent)->toHaveCount(1)
        ->and(dupfixLogsNamed($logs, 'otp_delivery_duplicate_suppressed_without_operation'))->toBeEmpty();
});

test('dupfix genuine duplicate with held reservation and accepted operation is still success', function () {
    $logs = &dupfixCaptureLogs();
    dupfixUser();
    $this->postJson('/api/v1/auth/login/request-code', ['phone' => DUPFIX_PHONE_E164])->assertStatus(202);
    $challenge = OtpChallenge::query()->sole();

    app()->instance(OtpDeliveryReservationStore::class, dupfixRefusingReservationStore());
    refreshAkubicaOtpDeliveryBinding();

    dupfixInvokeDispatch(app(AkubicaLoginOtpService::class), $challenge);

    expect($challenge->fresh()->invalidated_at)->toBeNull()
        ->and(app(FakeOtpDeliveryProvider::class)->sent)->toHaveCount(1)
        ->and(dupfixLogsNamed($logs, 'otp_delivery_duplicate_suppressed'))->not->toBeEmpty()
        ->and(dupfixLogsNamed($logs, 'otp_delivery_duplicate_suppressed_without_operation'))->toBeEmpty();
});

test('dupfix step-up dispatch fails closed on duplicate suppression without accepted operation', function () {
    dupfixUser();
    $this->postJson('/api/v1/auth/login/request-code', ['phone' => DUPFIX_PHONE_E164])->assertStatus(202);
    $challenge = OtpChallenge::query()->sole();
    OtpDeliveryOperation::query()->delete();

    app()->instance(OtpDeliveryReservationStore::class, dupfixRefusingReservationStore());
    refreshAkubicaOtpDeliveryBinding();

    expect(fn () => dupfixInvokeDispatch(app(AkubicaStepUpOtpService::class), $challenge))
        ->toThrow(OtpDeliveryFailedException::class);

    expect($challenge->fresh()->invalidated_reason)->toBe('delivery_failed')
        ->and(app(FakeOtpDeliveryProvider::class)->sent)->toHaveCount(1);
});
