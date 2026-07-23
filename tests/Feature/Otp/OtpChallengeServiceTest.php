<?php

use App\Contracts\Otp\OtpCodeGenerator;
use App\Enums\P0aOtpChannel;
use App\Enums\P0aOtpPurpose;
use App\Exceptions\Otp\OtpChallengeConsumedException;
use App\Exceptions\Otp\OtpChallengeExpiredException;
use App\Exceptions\Otp\OtpChallengeInvalidatedException;
use App\Exceptions\Otp\OtpChallengeMismatchException;
use App\Exceptions\Otp\OtpChallengeNotFoundException;
use App\Exceptions\Otp\OtpInvalidCodeException;
use App\Models\OtpChallenge;
use App\Models\User;
use App\Services\Otp\CreateOtpChallengeData;
use App\Services\Otp\OtpChallengeService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Hash;
use Tests\Support\Otp\FakeOtpCodeGenerator;

beforeEach(function () {
    Carbon::setTestNow(Carbon::parse('2026-07-22 12:00:00'));

    $this->fakeGenerator = new FakeOtpCodeGenerator('001234');
    $this->app->instance(OtpCodeGenerator::class, $this->fakeGenerator);
    $this->service = app(OtpChallengeService::class);
});

afterEach(function () {
    Carbon::setTestNow();
});

function makeCreateData(array $overrides = []): CreateOtpChallengeData
{
    $defaults = [
        'purpose' => P0aOtpPurpose::AkubicaLogin,
        'channel' => P0aOtpChannel::Email,
        'ttlMinutes' => 5,
        'userId' => null,
        'subjectType' => 'email',
        'subjectKey' => 'user@example.com',
        'destinationNormalized' => 'user@example.com',
        'destinationMasked' => null,
        'contextType' => null,
        'contextId' => null,
        'invalidatePreviousActive' => true,
        'meta' => ['safe' => true],
        'maxAttempts' => 5,
    ];

    $data = array_merge($defaults, $overrides);

    return new CreateOtpChallengeData(...$data);
}

test('create stores hashed code and never persists plaintext', function () {
    $result = $this->service->create(makeCreateData());

    expect($result->plainCode())->toBe('001234')
        ->and($result->challenge->public_id)->not->toBeEmpty()
        ->and(Hash::check('001234', $result->challenge->code_hash))->toBeTrue()
        ->and($result->challenge->destination_masked)->toBe('u***@example.com')
        ->and($result->challenge->status())->toBe(OtpChallenge::STATUS_PENDING);

    $raw = OtpChallenge::query()->find($result->challenge->id);
    expect($raw->getAttributes()['code_hash'] ?? null)->not->toBe('001234')
        ->and(json_encode($raw->toArray()))->not->toContain('001234');
});

test('create invalidates previous active challenges in same scope as superseded', function () {
    $first = $this->service->create(makeCreateData());
    $second = $this->service->create(makeCreateData());

    expect($first->challenge->fresh()->status())->toBe(OtpChallenge::STATUS_INVALIDATED)
        ->and($first->challenge->fresh()->invalidated_reason)->toBe('superseded')
        ->and($second->challenge->fresh()->status())->toBe(OtpChallenge::STATUS_PENDING)
        ->and(OtpChallenge::query()->activeFor()->count())->toBe(1);
});

test('create with user and context scopes invalidation correctly', function () {
    $user = User::factory()->create();

    $a = $this->service->create(makeCreateData([
        'userId' => $user->id,
        'subjectType' => 'user',
        'subjectKey' => (string) $user->id,
        'purpose' => P0aOtpPurpose::StepUpResults,
        'contextType' => 'laboratory_purchase',
        'contextId' => 10,
    ]));

    $otherContext = $this->service->create(makeCreateData([
        'userId' => $user->id,
        'subjectType' => 'user',
        'subjectKey' => (string) $user->id,
        'purpose' => P0aOtpPurpose::StepUpResults,
        'contextType' => 'laboratory_purchase',
        'contextId' => 11,
    ]));

    $sameContext = $this->service->create(makeCreateData([
        'userId' => $user->id,
        'subjectType' => 'user',
        'subjectKey' => (string) $user->id,
        'purpose' => P0aOtpPurpose::StepUpResults,
        'contextType' => 'laboratory_purchase',
        'contextId' => 10,
    ]));

    expect($a->challenge->fresh()->status())->toBe(OtpChallenge::STATUS_INVALIDATED)
        ->and($otherContext->challenge->fresh()->status())->toBe(OtpChallenge::STATUS_PENDING)
        ->and($sameContext->challenge->fresh()->status())->toBe(OtpChallenge::STATUS_PENDING);
});

test('verify succeeds and consumes atomically', function () {
    $created = $this->service->create(makeCreateData());

    $verified = $this->service->verify(
        $created->challenge->public_id,
        '001234',
        P0aOtpPurpose::AkubicaLogin,
    );

    expect($verified->status())->toBe(OtpChallenge::STATUS_CONSUMED)
        ->and($verified->consumed_at)->not->toBeNull();

    expect(fn () => $this->service->verify(
        $created->challenge->public_id,
        '001234',
        P0aOtpPurpose::AkubicaLogin,
    ))->toThrow(OtpChallengeConsumedException::class);
});

test('verify rejects wrong code and increments failed attempts', function () {
    $created = $this->service->create(makeCreateData(['maxAttempts' => 3]));

    expect(fn () => $this->service->verify(
        $created->challenge->public_id,
        '999999',
        P0aOtpPurpose::AkubicaLogin,
    ))->toThrow(OtpInvalidCodeException::class);

    expect($created->challenge->fresh()->failed_attempts)->toBe(1)
        ->and($created->challenge->fresh()->status())->toBe(OtpChallenge::STATUS_PENDING);
});

test('verify invalidates when max attempts exhausted', function () {
    $created = $this->service->create(makeCreateData(['maxAttempts' => 2]));

    expect(fn () => $this->service->verify(
        $created->challenge->public_id,
        '111111',
        P0aOtpPurpose::AkubicaLogin,
    ))->toThrow(OtpInvalidCodeException::class);

    expect(fn () => $this->service->verify(
        $created->challenge->public_id,
        '222222',
        P0aOtpPurpose::AkubicaLogin,
    ))->toThrow(OtpChallengeInvalidatedException::class);

    expect($created->challenge->fresh()->status())->toBe(OtpChallenge::STATUS_INVALIDATED)
        ->and($created->challenge->fresh()->invalidated_reason)->toBe('attempts_exhausted');
});

test('verify rejects expired challenge', function () {
    $created = $this->service->create(makeCreateData(['ttlMinutes' => 5]));

    Carbon::setTestNow(now()->addMinutes(6));

    expect(fn () => $this->service->verify(
        $created->challenge->public_id,
        '001234',
        P0aOtpPurpose::AkubicaLogin,
    ))->toThrow(OtpChallengeExpiredException::class);
});

test('verify rejects purpose mismatch', function () {
    $created = $this->service->create(makeCreateData([
        'purpose' => P0aOtpPurpose::AkubicaLogin,
    ]));

    expect(fn () => $this->service->verify(
        $created->challenge->public_id,
        '001234',
        P0aOtpPurpose::AkubicaRegister,
    ))->toThrow(OtpChallengeMismatchException::class);
});

test('verify rejects user and context mismatch', function () {
    $user = User::factory()->create();
    $created = $this->service->create(makeCreateData([
        'userId' => $user->id,
        'subjectType' => 'user',
        'subjectKey' => (string) $user->id,
        'purpose' => P0aOtpPurpose::StepUpResults,
        'contextType' => 'laboratory_purchase',
        'contextId' => 42,
    ]));

    expect(fn () => $this->service->verify(
        $created->challenge->public_id,
        '001234',
        P0aOtpPurpose::StepUpResults,
        userId: $user->id + 1,
        contextType: 'laboratory_purchase',
        contextId: 42,
    ))->toThrow(OtpChallengeMismatchException::class);

    expect(fn () => $this->service->verify(
        $created->challenge->public_id,
        '001234',
        P0aOtpPurpose::StepUpResults,
        userId: $user->id,
        contextType: 'laboratory_purchase',
        contextId: 99,
    ))->toThrow(OtpChallengeMismatchException::class);
});

test('verify exceptions never include the plaintext otp', function () {
    $created = $this->service->create(makeCreateData());

    try {
        $this->service->verify(
            $created->challenge->public_id,
            '999999',
            P0aOtpPurpose::AkubicaLogin,
        );
        expect(false)->toBeTrue();
    } catch (OtpInvalidCodeException $e) {
        expect($e->getMessage())->not->toContain('001234')
            ->and($e->getMessage())->not->toContain('999999');
    }
});

test('verify rejects unknown public id', function () {
    expect(fn () => $this->service->verify(
        '00000000-0000-0000-0000-000000000000',
        '001234',
        P0aOtpPurpose::AkubicaLogin,
    ))->toThrow(OtpChallengeNotFoundException::class);
});

test('invalidate marks challenge invalidated', function () {
    $created = $this->service->create(makeCreateData());

    $this->service->invalidate($created->challenge->public_id, 'manual');

    expect($created->challenge->fresh()->status())->toBe(OtpChallenge::STATUS_INVALIDATED)
        ->and($created->challenge->fresh()->invalidated_reason)->toBe('manual');
});

test('findActive and statusByPublicId work', function () {
    $created = $this->service->create(makeCreateData());

    expect($this->service->findActive($created->challenge->public_id))->not->toBeNull()
        ->and($this->service->statusByPublicId($created->challenge->public_id))
        ->toBe(OtpChallenge::STATUS_PENDING);

    $this->service->invalidate($created->challenge->public_id, 'manual');

    expect($this->service->findActive($created->challenge->public_id))->toBeNull()
        ->and($this->service->statusByPublicId($created->challenge->public_id))
        ->toBe(OtpChallenge::STATUS_INVALIDATED);
});

test('recordDeliveryAttempt increments send_count without notifications', function () {
    $created = $this->service->create(makeCreateData());

    $this->service->recordDeliveryAttempt($created->challenge->public_id);
    $this->service->recordDeliveryAttempt($created->challenge->public_id);

    $fresh = $created->challenge->fresh();
    expect($fresh->send_count)->toBe(2)
        ->and($fresh->last_sent_at)->not->toBeNull();
});

test('p0a feature flags remain disabled by default after service usage', function () {
    $this->service->create(makeCreateData());

    expect(config('otp.p0a.flags.infrastructure_enabled'))->toBeFalse()
        ->and(config('otp.p0a.flags.sms_delivery_enabled'))->toBeFalse()
        ->and(config('otp.p0a.flags.anti_abuse_enabled'))->toBeFalse();
});

test('otp_codes table remains untouched by otp_challenges service', function () {
    expect(\Illuminate\Support\Facades\Schema::hasTable('otp_codes'))->toBeTrue()
        ->and(\Illuminate\Support\Facades\Schema::hasTable('otp_challenges'))->toBeTrue();

    $before = \Illuminate\Support\Facades\DB::table('otp_codes')->count();
    $this->service->create(makeCreateData());
    $after = \Illuminate\Support\Facades\DB::table('otp_codes')->count();

    expect($after)->toBe($before)
        ->and(OtpChallenge::query()->count())->toBe(1);
});
