<?php

use App\Contracts\Otp\OtpCodeGenerator;
use App\Enums\P0aOtpChannel;
use App\Enums\P0aOtpPurpose;
use App\Exceptions\Otp\OtpRateLimitExceededException;
use App\Models\OtpAbuseEvent;
use App\Models\OtpChallenge;
use App\Models\OtpRateLimit;
use App\Services\Otp\CreateOtpChallengeData;
use App\Services\Otp\OtpAbuseKeyHasher;
use App\Services\Otp\OtpAbusePolicy;
use App\Services\Otp\OtpChallengeService;
use App\Services\Otp\OtpRateLimitDecision;
use App\Services\Otp\OtpRateLimitService;
use App\Services\Otp\OtpRequestContext;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Tests\Support\Otp\FakeOtpCodeGenerator;

beforeEach(function () {
    Carbon::setTestNow(Carbon::parse('2026-07-23 15:00:00'));
    Notification::fake();

    $this->app->instance(OtpCodeGenerator::class, new FakeOtpCodeGenerator(['111111', '222222', '333333']));
    $this->policy = app(OtpAbusePolicy::class);
    $this->rateLimits = app(OtpRateLimitService::class);
    $this->hasher = app(OtpAbuseKeyHasher::class);
});

afterEach(function () {
    Carbon::setTestNow();
});

function concurrencyCreateData(array $overrides = []): CreateOtpChallengeData
{
    $defaults = [
        'purpose' => P0aOtpPurpose::AkubicaLogin,
        'channel' => P0aOtpChannel::Email,
        'ttlMinutes' => 5,
        'userId' => null,
        'subjectType' => 'email',
        'subjectKey' => 'race@example.com',
        'destinationNormalized' => 'race@example.com',
        'destinationMasked' => null,
        'contextType' => null,
        'contextId' => null,
        'invalidatePreviousActive' => true,
        'meta' => null,
        'maxAttempts' => 5,
    ];

    return new CreateOtpChallengeData(...array_merge($defaults, $overrides));
}

function concurrencyContext(array $overrides = []): OtpRequestContext
{
    $defaults = [
        'purpose' => P0aOtpPurpose::AkubicaLogin,
        'userId' => null,
        'subjectType' => 'email',
        'subjectKey' => 'race@example.com',
        'contextType' => null,
        'contextId' => null,
        'channel' => P0aOtpChannel::Email,
        'clientIp' => '198.51.100.50',
        'existingChallengePublicId' => null,
    ];

    return new OtpRequestContext(...array_merge($defaults, $overrides));
}

test('missing bucket initialization is idempotent under insertOrIgnore and yields a single row', function () {
    $context = concurrencyContext();
    $hash = $this->rateLimits->identityHash($context);

    DB::transaction(function () use ($context, $hash) {
        $first = $this->rateLimits->lockBucket(OtpRateLimit::BUCKET_IDENTITY, $hash, $context->purpose->value);
        $second = $this->rateLimits->lockBucket(OtpRateLimit::BUCKET_IDENTITY, $hash, $context->purpose->value);

        expect($first->id)->toBe($second->id)
            ->and($first->request_count)->toBe(0);
    });

    expect(OtpRateLimit::query()->where('bucket_type', OtpRateLimit::BUCKET_IDENTITY)->count())->toBe(1)
        ->and(OtpRateLimit::query()->where('bucket_type', OtpRateLimit::BUCKET_IP)->count())->toBe(0);
});

test('identity and ip buckets are created together with fixed lock order', function () {
    $context = concurrencyContext();

    DB::transaction(function () use ($context) {
        [$identity, $ip] = $this->rateLimits->lockBucketsOrdered($context);

        expect($identity->bucket_type)->toBe(OtpRateLimit::BUCKET_IDENTITY)
            ->and($ip)->not->toBeNull()
            ->and($ip->bucket_type)->toBe(OtpRateLimit::BUCKET_IP);
    });

    expect(OtpRateLimit::query()->count())->toBe(2);
});

test('duplicate create on unique key raises UniqueConstraintViolationException not a bare domain leak path', function () {
    $context = concurrencyContext();
    $hash = $this->rateLimits->identityHash($context);

    $attrs = [
        'bucket_type' => OtpRateLimit::BUCKET_IDENTITY,
        'bucket_key_hash' => $hash,
        'purpose' => $context->purpose->value,
        'window_started_at' => now(),
        'request_count' => 0,
    ];

    OtpRateLimit::query()->create($attrs);

    try {
        OtpRateLimit::query()->create($attrs);
        expect(false)->toBeTrue();
    } catch (UniqueConstraintViolationException $e) {
        expect($e)->toBeInstanceOf(UniqueConstraintViolationException::class);
    }

    // Service recovers by locking the existing row (insertOrIgnore + FOR UPDATE).
    DB::transaction(function () use ($context, $hash) {
        $bucket = $this->rateLimits->lockBucket(
            OtpRateLimit::BUCKET_IDENTITY,
            $hash,
            $context->purpose->value,
        );
        expect($bucket->bucket_key_hash)->toBe($hash);
    });

    expect(OtpRateLimit::query()->where('bucket_type', OtpRateLimit::BUCKET_IDENTITY)->count())->toBe(1);
});

test('only one of two competing first issues remains allowed under serialized transactions', function () {
    // SQLite cannot simulate true parallel writers; this models the MySQL outcome
    // after lock handover: winner commits, loser re-evaluates under cooldown.
    $first = $this->policy->issue(concurrencyCreateData(), concurrencyContext());

    try {
        $this->policy->issue(concurrencyCreateData(), concurrencyContext());
        expect(false)->toBeTrue();
    } catch (OtpRateLimitExceededException $e) {
        expect($e->decision->errorCode)->toBe(OtpRateLimitDecision::CODE_COOLDOWN)
            ->and($e)->not->toBeInstanceOf(\Illuminate\Database\QueryException::class);
    }

    expect(OtpChallenge::query()->count())->toBe(1)
        ->and(OtpChallenge::query()->activeFor()->count())->toBe(1)
        ->and($first->challenge->fresh()->status())->toBe(OtpChallenge::STATUS_PENDING)
        ->and(OtpRateLimit::query()->where('bucket_type', OtpRateLimit::BUCKET_IDENTITY)->value('request_count'))->toBe(1);
});

test('two resends after cooldown still serialize to a single active challenge', function () {
    $this->policy->issue(concurrencyCreateData(), concurrencyContext());

    Carbon::setTestNow(now()->addSeconds(60));
    $second = $this->policy->resend(concurrencyCreateData(), concurrencyContext());

    try {
        $this->policy->resend(concurrencyCreateData(), concurrencyContext());
        expect(false)->toBeTrue();
    } catch (OtpRateLimitExceededException $e) {
        expect($e->decision->errorCode)->toBe(OtpRateLimitDecision::CODE_COOLDOWN);
    }

    expect(OtpChallenge::query()->activeFor()->count())->toBe(1)
        ->and($second->challenge->fresh()->status())->toBe(OtpChallenge::STATUS_PENDING)
        ->and(OtpChallenge::query()->whereNotNull('invalidated_at')->count())->toBe(1);
});

test('rolled-back issue leaves no challenge counters or commit audit', function () {
    expect(fn () => DB::transaction(function () {
        $context = concurrencyContext();
        $decision = $this->rateLimits->evaluateIssueLocked($context);
        expect($decision->allowed)->toBeTrue();

        $created = app(OtpChallengeService::class)->create(concurrencyCreateData());
        $this->rateLimits->commitAllowedIssueLocked($context, $created->challenge->id);
        app(OtpChallengeService::class)->recordDeliveryAttempt($created->challenge->public_id);

        throw new RuntimeException('force-rollback');
    }))->toThrow(RuntimeException::class);

    expect(OtpChallenge::query()->count())->toBe(0)
        ->and(OtpRateLimit::query()->count())->toBe(0)
        ->and(OtpAbuseEvent::query()->count())->toBe(0);
});

test('recordDeliveryAttempt stays inside the policy transaction boundary', function () {
    $result = $this->policy->issue(concurrencyCreateData(), concurrencyContext());

    expect($result->challenge->send_count)->toBe(1)
        ->and($result->challenge->last_sent_at)->not->toBeNull();

    // A denied second call must not create another challenge or bump send_count on the first.
    expect(fn () => $this->policy->issue(concurrencyCreateData(), concurrencyContext()))
        ->toThrow(OtpRateLimitExceededException::class);

    expect($result->challenge->fresh()->send_count)->toBe(1)
        ->and(OtpChallenge::query()->count())->toBe(1);
});

test('contextType and contextId intentionally isolate identity buckets', function () {
    $base = [
        'purpose' => P0aOtpPurpose::StepUpResults,
        'userId' => 42,
        'subjectType' => 'user',
        'subjectKey' => '42',
    ];

    $a = $this->hasher->hashIdentity(...$base, contextType: 'laboratory_purchase', contextId: 10);
    $b = $this->hasher->hashIdentity(...$base, contextType: 'laboratory_purchase', contextId: 11);
    $none = $this->hasher->hashIdentity(...$base, contextType: null, contextId: null);

    expect($a)->not->toBe($b)
        ->and($a)->not->toBe($none);

    // Documented risk: varying context creates distinct buckets for the same user+purpose.
    // Future productive wiring should add a global identity+purpose ceiling if needed.
});
