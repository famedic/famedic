<?php

use App\Contracts\Otp\OtpCodeGenerator;
use App\Enums\P0aOtpChannel;
use App\Enums\P0aOtpPurpose;
use App\Exceptions\Otp\OtpChallengeMismatchException;
use App\Exceptions\Otp\OtpInvalidCodeException;
use App\Exceptions\Otp\OtpRateLimitExceededException;
use App\Exceptions\Otp\OtpTemporarilyBlockedException;
use App\Models\OtpAbuseEvent;
use App\Models\OtpChallenge;
use App\Models\OtpRateLimit;
use App\Models\User;
use App\Services\Otp\CreateOtpChallengeData;
use App\Services\Otp\OtpAbuseKeyHasher;
use App\Services\Otp\OtpAbusePolicy;
use App\Services\Otp\OtpRateLimitDecision;
use App\Services\Otp\OtpRequestContext;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Tests\Support\Otp\FakeOtpCodeGenerator;

beforeEach(function () {
    Carbon::setTestNow(Carbon::parse('2026-07-23 12:00:00'));
    Notification::fake();

    $this->fakeGenerator = new FakeOtpCodeGenerator('001234');
    $this->app->instance(OtpCodeGenerator::class, $this->fakeGenerator);
    $this->policy = app(OtpAbusePolicy::class);
    $this->hasher = app(OtpAbuseKeyHasher::class);
});

afterEach(function () {
    Carbon::setTestNow();
});

function abuseCreateData(array $overrides = []): CreateOtpChallengeData
{
    $defaults = [
        'purpose' => P0aOtpPurpose::AkubicaLogin,
        'channel' => P0aOtpChannel::Email,
        'ttlMinutes' => 5,
        'userId' => null,
        'subjectType' => 'email',
        'subjectKey' => 'paciente@example.com',
        'destinationNormalized' => 'paciente@example.com',
        'destinationMasked' => null,
        'contextType' => null,
        'contextId' => null,
        'invalidatePreviousActive' => true,
        'meta' => null,
        'maxAttempts' => 5,
    ];

    return new CreateOtpChallengeData(...array_merge($defaults, $overrides));
}

function abuseContext(array $overrides = []): OtpRequestContext
{
    $defaults = [
        'purpose' => P0aOtpPurpose::AkubicaLogin,
        'userId' => null,
        'subjectType' => 'email',
        'subjectKey' => 'paciente@example.com',
        'contextType' => null,
        'contextId' => null,
        'channel' => P0aOtpChannel::Email,
        'clientIp' => '203.0.113.10',
        'existingChallengePublicId' => null,
    ];

    return new OtpRequestContext(...array_merge($defaults, $overrides));
}

test('first issue is allowed and records audit without plaintext secrets', function () {
    $result = $this->policy->issue(abuseCreateData(), abuseContext());

    expect($result->plainCode())->toBe('001234')
        ->and($result->challenge->send_count)->toBe(1)
        ->and(OtpRateLimit::query()->count())->toBeGreaterThanOrEqual(1)
        ->and(OtpAbuseEvent::query()->where('decision', 'allowed')->count())->toBe(1);

    $event = OtpAbuseEvent::query()->first();
    $limit = OtpRateLimit::query()->first();

    expect(json_encode($event->toArray()))->not->toContain('001234')
        ->and(json_encode($event->toArray()))->not->toContain('203.0.113.10')
        ->and(json_encode($event->toArray()))->not->toContain('paciente@example.com')
        ->and($limit->bucket_key_hash)->not->toContain('203.0.113')
        ->and($limit->getHidden())->toContain('bucket_key_hash');
});

test('immediate second issue is rejected with cooldown and retry_after', function () {
    $this->policy->issue(abuseCreateData(), abuseContext());

    try {
        $this->policy->issue(abuseCreateData(), abuseContext());
        expect(false)->toBeTrue();
    } catch (OtpRateLimitExceededException $e) {
        expect($e->decision->errorCode)->toBe(OtpRateLimitDecision::CODE_COOLDOWN)
            ->and($e->decision->retryAfterSeconds)->toBe(60)
            ->and($e->getMessage())->not->toContain('001234')
            ->and($e->getMessage())->not->toContain('203.0.113.10');
    }

    expect(OtpChallenge::query()->count())->toBe(1)
        ->and(OtpAbuseEvent::query()->where('decision', 'cooldown')->count())->toBe(1);
});

test('issue is allowed after cooldown expires', function () {
    $this->fakeGenerator = new FakeOtpCodeGenerator(['001234', '654321']);
    $this->app->instance(OtpCodeGenerator::class, $this->fakeGenerator);
    $this->policy = app(OtpAbusePolicy::class);

    $first = $this->policy->issue(abuseCreateData(), abuseContext());

    Carbon::setTestNow(now()->addSeconds(60));

    $second = $this->policy->resend(abuseCreateData(), abuseContext());

    expect($first->challenge->fresh()->status())->toBe(OtpChallenge::STATUS_INVALIDATED)
        ->and($second->plainCode())->toBe('654321')
        ->and($second->plainCode())->not->toBe($first->plainCode())
        ->and(OtpChallenge::query()->activeFor()->count())->toBe(1);

    expect(fn () => app(\App\Services\Otp\OtpChallengeService::class)->verify(
        $first->challenge->public_id,
        '001234',
        P0aOtpPurpose::AkubicaLogin,
    ))->toThrow(\App\Exceptions\Otp\OtpChallengeInvalidatedException::class);
});

test('cooldown is isolated by purpose', function () {
    $this->policy->issue(abuseCreateData(), abuseContext());

    $register = $this->policy->issue(
        abuseCreateData([
            'purpose' => P0aOtpPurpose::AkubicaRegister,
            'subjectKey' => 'paciente@example.com',
        ]),
        abuseContext([
            'purpose' => P0aOtpPurpose::AkubicaRegister,
        ]),
    );

    expect($register->challenge->purpose)->toBe('akubica_register')
        ->and(OtpChallenge::query()->count())->toBe(2);
});

test('exceeding identity max requests applies 30 minute block', function () {
    config()->set('otp.p0a.anti_abuse.identity_max_requests', 2);
    config()->set('otp.p0a.policy.max_resends', 1);

    $this->fakeGenerator = new FakeOtpCodeGenerator(['111111', '222222', '333333']);
    $this->app->instance(OtpCodeGenerator::class, $this->fakeGenerator);
    $this->policy = app(OtpAbusePolicy::class);

    $this->policy->issue(abuseCreateData(), abuseContext());
    Carbon::setTestNow(now()->addSeconds(60));
    $this->policy->issue(abuseCreateData(), abuseContext());
    Carbon::setTestNow(now()->addSeconds(60));

    try {
        $this->policy->issue(abuseCreateData(), abuseContext());
        expect(false)->toBeTrue();
    } catch (OtpRateLimitExceededException $e) {
        expect($e->decision->errorCode)->toBe(OtpRateLimitDecision::CODE_RATE_LIMITED)
            ->and($e->decision->retryAfterSeconds)->toBe(30 * 60)
            ->and($e->decision->availableAt?->equalTo(now()->addMinutes(30)))->toBeTrue();
    }

    expect(OtpAbuseEvent::query()->where('decision', 'block_started')->count())->toBeGreaterThanOrEqual(1);

    // Still blocked before unlock time.
    expect(fn () => $this->policy->issue(abuseCreateData(), abuseContext()))
        ->toThrow(OtpTemporarilyBlockedException::class);

    Carbon::setTestNow(now()->addMinutes(30));

    $after = $this->policy->issue(abuseCreateData(), abuseContext());
    expect($after->challenge->status())->toBe(OtpChallenge::STATUS_PENDING);
});

test('ip limit blocks independently of identity', function () {
    config()->set('otp.p0a.anti_abuse.ip_max_requests', 2);
    config()->set('otp.p0a.anti_abuse.identity_max_requests', 50);
    config()->set('otp.p0a.policy.max_resends', 50);

    $this->fakeGenerator = new FakeOtpCodeGenerator(['111111', '222222', '333333']);
    $this->app->instance(OtpCodeGenerator::class, $this->fakeGenerator);
    $this->policy = app(OtpAbusePolicy::class);

    $this->policy->issue(
        abuseCreateData(['subjectKey' => 'a@example.com', 'destinationNormalized' => 'a@example.com']),
        abuseContext(['subjectKey' => 'a@example.com', 'clientIp' => '198.51.100.1']),
    );
    Carbon::setTestNow(now()->addSeconds(60));

    $this->policy->issue(
        abuseCreateData(['subjectKey' => 'b@example.com', 'destinationNormalized' => 'b@example.com']),
        abuseContext(['subjectKey' => 'b@example.com', 'clientIp' => '198.51.100.1']),
    );
    Carbon::setTestNow(now()->addSeconds(60));

    expect(fn () => $this->policy->issue(
        abuseCreateData(['subjectKey' => 'c@example.com', 'destinationNormalized' => 'c@example.com']),
        abuseContext(['subjectKey' => 'c@example.com', 'clientIp' => '198.51.100.1']),
    ))->toThrow(OtpRateLimitExceededException::class);
});

test('same identity from different ips shares identity cooldown', function () {
    $this->policy->issue(abuseCreateData(), abuseContext(['clientIp' => '198.51.100.1']));

    expect(fn () => $this->policy->issue(
        abuseCreateData(),
        abuseContext(['clientIp' => '198.51.100.2']),
    ))->toThrow(OtpRateLimitExceededException::class);
});

test('missing ip still enforces identity limits and does not use a global bucket', function () {
    $this->policy->issue(abuseCreateData(), abuseContext(['clientIp' => null]));

    expect(OtpRateLimit::query()->where('bucket_type', OtpRateLimit::BUCKET_IP)->count())->toBe(0);

    expect(fn () => $this->policy->issue(
        abuseCreateData(),
        abuseContext(['clientIp' => null]),
    ))->toThrow(OtpRateLimitExceededException::class);

    // Different identity without IP remains allowed.
    $other = $this->policy->issue(
        abuseCreateData([
            'subjectKey' => 'otro@example.com',
            'destinationNormalized' => 'otro@example.com',
        ]),
        abuseContext([
            'subjectKey' => 'otro@example.com',
            'clientIp' => null,
        ]),
    );

    expect($other->challenge->subject_key)->toBe('otro@example.com');
});

test('verify max attempts invalidates and returns OTP_MAX_ATTEMPTS decision', function () {
    $created = $this->policy->issue(
        abuseCreateData(['maxAttempts' => 2]),
        abuseContext(),
    );

    expect(fn () => $this->policy->verify(
        $created->challenge->public_id,
        '111111',
        abuseContext(),
    ))->toThrow(OtpInvalidCodeException::class);

    try {
        $this->policy->verify(
            $created->challenge->public_id,
            '222222',
            abuseContext(),
        );
        expect(false)->toBeTrue();
    } catch (OtpRateLimitExceededException $e) {
        expect($e->decision->errorCode)->toBe(OtpRateLimitDecision::CODE_MAX_ATTEMPTS)
            ->and($e->decision->retryAfterSeconds)->toBe(30 * 60)
            ->and($e->getMessage())->not->toContain('001234')
            ->and($e->getMessage())->not->toContain('222222');
    }

    expect($created->challenge->fresh()->status())->toBe(OtpChallenge::STATUS_INVALIDATED);

    expect(fn () => $this->policy->verify(
        $created->challenge->public_id,
        '001234',
        abuseContext(),
    ))->toThrow(OtpRateLimitExceededException::class);
});

test('subject mismatch does not exhaust attempts of another challenge', function () {
    $user = User::factory()->create();
    $created = $this->policy->issue(
        abuseCreateData([
            'userId' => $user->id,
            'subjectType' => 'user',
            'subjectKey' => (string) $user->id,
            'purpose' => P0aOtpPurpose::StepUpResults,
            'contextType' => 'laboratory_purchase',
            'contextId' => 10,
            'maxAttempts' => 2,
        ]),
        abuseContext([
            'purpose' => P0aOtpPurpose::StepUpResults,
            'userId' => $user->id,
            'subjectType' => 'user',
            'subjectKey' => (string) $user->id,
            'contextType' => 'laboratory_purchase',
            'contextId' => 10,
        ]),
    );

    expect(fn () => $this->policy->verify(
        $created->challenge->public_id,
        '001234',
        abuseContext([
            'purpose' => P0aOtpPurpose::StepUpResults,
            'userId' => $user->id + 99,
            'contextType' => 'laboratory_purchase',
            'contextId' => 10,
        ]),
    ))->toThrow(OtpChallengeMismatchException::class);

    expect($created->challenge->fresh()->failed_attempts)->toBe(0)
        ->and($created->challenge->fresh()->status())->toBe(OtpChallenge::STATUS_PENDING);
});

test('successful verify before max attempts still works', function () {
    $created = $this->policy->issue(abuseCreateData(['maxAttempts' => 3]), abuseContext());

    $verified = $this->policy->verify(
        $created->challenge->public_id,
        '001234',
        abuseContext(),
    );

    expect($verified->status())->toBe(OtpChallenge::STATUS_CONSUMED);
});

test('anti_abuse flag remains false and no notifications are sent', function () {
    $this->policy->issue(abuseCreateData(), abuseContext());

    expect(config('otp.p0a.flags.anti_abuse_enabled'))->toBeFalse()
        ->and(config('otp.p0a.flags.sms_delivery_enabled'))->toBeFalse()
        ->and(config('otp.p0a.flags.email_fallback_enabled'))->toBeFalse();

    Notification::assertNothingSent();
});

test('legacy otp_codes and sanctum defaults remain intact after abuse policy usage', function () {
    $before = DB::table('otp_codes')->count();
    $this->policy->issue(abuseCreateData(), abuseContext());

    expect(DB::table('otp_codes')->count())->toBe($before)
        ->and(config('otp.digits'))->toBe(6)
        ->and(config('otp.expiry'))->toBe(10)
        ->and(config('akubica.otp_ttl_minutes'))->toBe(10)
        ->and(config('akubica.token_ttl_minutes'))->toBe(1440)
        ->and((int) config('otp.p0a.sanctum.current_expiration_minutes'))->toBe(1440);
});

test('conditional counter increment is used for concurrency safety documentation', function () {
    // SQLite may not honor lockForUpdate; we still persist via unique bucket +
    // transactional read-modify-write. On MySQL, evaluateIssueLocked +
    // commitAllowedIssueLocked run under the same outer transaction with
    // lockForUpdate on otp_rate_limits rows.
    $context = abuseContext();
    $service = app(\App\Services\Otp\OtpRateLimitService::class);

    DB::transaction(function () use ($service, $context) {
        $decision = $service->evaluateIssueLocked($context);
        expect($decision->allowed)->toBeTrue();
        $service->commitAllowedIssueLocked($context, null);
    });

    $bucket = OtpRateLimit::query()
        ->where('bucket_type', OtpRateLimit::BUCKET_IDENTITY)
        ->first();

    expect($bucket->request_count)->toBe(1)
        ->and($bucket->last_allowed_at)->not->toBeNull();
});
