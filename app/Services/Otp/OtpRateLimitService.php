<?php

namespace App\Services\Otp;

use App\Enums\P0aOtpPurpose;
use App\Exceptions\Otp\OtpChallengeException;
use App\Models\OtpAbuseEvent;
use App\Models\OtpRateLimit;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;

/**
 * Atomic OTP anti-abuse counters, cooldown, and temporary blocks.
 *
 * Always enforces rules when invoked. Productive wiring must gate on
 * otp.p0a.flags.anti_abuse_enabled before calling higher-level entry points
 * so a disabled flag does not silently open a security gate inside this layer.
 *
 * Concurrency (P0-A3):
 * - Buckets are initialized with insertOrIgnore + SELECT ... FOR UPDATE so a
 *   missing row does not rely on a broad QueryException catch.
 * - UniqueConstraintViolationException is the only insert collision that is
 *   recovered (re-read + lock). Other SQL errors propagate unchanged.
 * - Lock order is always identity bucket, then IP bucket (never reversed) to
 *   avoid AB-BA deadlocks. OtpAbusePolicy retries the outer transaction on
 *   MySQL deadlocks via DB::transaction(..., attempts).
 * - SQLite does not provide real row-level lockForUpdate; writers serialize at
 *   the database file. Unique + insertOrIgnore still prevent duplicate buckets.
 */
class OtpRateLimitService
{
    public const TRANSACTION_ATTEMPTS = 5;

    public function __construct(
        private readonly OtpAbuseKeyHasher $hasher,
    ) {
    }

    /**
     * Standalone evaluation (own transaction).
     * Prefer OtpAbusePolicy::issue which keeps locks across challenge creation.
     */
    public function authorizeIssue(OtpRequestContext $context): OtpRateLimitDecision
    {
        return DB::transaction(
            fn () => $this->evaluateIssueLocked($context),
            self::TRANSACTION_ATTEMPTS,
        );
    }

    /**
     * Evaluate limits while holding row locks. Caller must be inside a transaction.
     */
    public function evaluateIssueLocked(OtpRequestContext $context): OtpRateLimitDecision
    {
        [$identityBucket, $ipBucket] = $this->lockBucketsOrdered($context);

        $this->refreshWindow($identityBucket);
        if ($ipBucket !== null) {
            $this->refreshWindow($ipBucket);
        }

        if ($decision = $this->blockedDecision($identityBucket, $ipBucket, $context->purpose)) {
            return $decision;
        }

        if ($decision = $this->cooldownDecision($identityBucket, $context->purpose)) {
            return $decision;
        }

        $maxAllowed = $this->maxAllowedRequests();
        $priorRequests = (int) $identityBucket->request_count;

        if ($priorRequests >= $maxAllowed) {
            return $this->applyBlock(
                identityBucket: $identityBucket,
                ipBucket: $ipBucket,
                purpose: $context->purpose,
                scope: OtpRateLimitDecision::SCOPE_IDENTITY,
                decisionName: 'identity_limited',
                alsoBlockIp: false,
            );
        }

        if ($ipBucket !== null && (int) $ipBucket->request_count >= $this->ipMaxRequests()) {
            return $this->applyBlock(
                identityBucket: $identityBucket,
                ipBucket: $ipBucket,
                purpose: $context->purpose,
                scope: OtpRateLimitDecision::SCOPE_IP,
                decisionName: 'ip_limited',
                alsoBlockIp: true,
                alsoBlockIdentity: false,
            );
        }

        return OtpRateLimitDecision::allow($priorRequests === 0 ? 'allowed' : 'resend_allowed');
    }

    /**
     * Persist audit rows AFTER the authorizing transaction commits/returns,
     * so deny paths are not rolled back when the caller throws.
     */
    public function persistDecisionAudit(
        OtpRequestContext $context,
        OtpRateLimitDecision $decision,
        ?int $challengeId = null,
    ): void {
        $identityHash = $this->identityHash($context);
        $ipHash = $this->hasher->hashIp($context->clientIp);
        $name = $decision->decision ?? 'unknown';

        $this->recordEvent($context, $identityHash, $ipHash, $decision, $name, $challengeId);

        if (in_array($name, ['identity_limited', 'ip_limited', 'max_attempts'], true)) {
            $this->recordEvent($context, $identityHash, $ipHash, $decision, 'block_started', $challengeId);
        }
    }

    /**
     * Increment counters after a successful challenge creation. Caller must hold locks
     * in the same transaction (call evaluateIssueLocked first in that transaction).
     */
    public function commitAllowedIssueLocked(
        OtpRequestContext $context,
        ?int $challengeId = null,
    ): void {
        [$identityBucket, $ipBucket] = $this->lockBucketsOrdered($context);
        $identityHash = $this->identityHash($context);
        $ipHash = $this->hasher->hashIp($context->clientIp);

        $this->refreshWindow($identityBucket);

        $identityBucket->request_count = (int) $identityBucket->request_count + 1;
        $identityBucket->last_allowed_at = now();
        $identityBucket->last_challenge_id = $challengeId;
        $identityBucket->save();

        if ($ipBucket !== null) {
            $this->refreshWindow($ipBucket);
            $ipBucket->request_count = (int) $ipBucket->request_count + 1;
            $ipBucket->last_allowed_at = now();
            $ipBucket->last_challenge_id = $challengeId;
            $ipBucket->save();
        }

        $decision = OtpRateLimitDecision::allow(
            (int) $identityBucket->request_count === 1 ? 'allowed' : 'resend_allowed'
        );
        $this->recordEvent(
            $context,
            $identityHash,
            $ipHash,
            $decision,
            $decision->decision ?? 'allowed',
            $challengeId,
        );
    }

    public function recordMaxAttemptsExhausted(
        OtpRequestContext $context,
        ?int $challengeId = null,
    ): OtpRateLimitDecision {
        return DB::transaction(function () use ($context, $challengeId) {
            [$identityBucket, $ipBucket] = $this->lockBucketsOrdered($context);
            $identityHash = $this->identityHash($context);
            $ipHash = $this->hasher->hashIp($context->clientIp);

            $this->refreshWindow($identityBucket);
            if ($ipBucket !== null) {
                $this->refreshWindow($ipBucket);
            }

            $decision = $this->applyBlock(
                identityBucket: $identityBucket,
                ipBucket: $ipBucket,
                purpose: $context->purpose,
                scope: $ipBucket !== null
                    ? OtpRateLimitDecision::SCOPE_BOTH
                    : OtpRateLimitDecision::SCOPE_IDENTITY,
                decisionName: 'max_attempts',
                alsoBlockIp: $ipBucket !== null,
                alsoBlockIdentity: true,
                errorCode: OtpRateLimitDecision::CODE_MAX_ATTEMPTS,
                publicMessage: 'Se alcanzo el limite de intentos. Intenta mas tarde.',
            );

            $this->recordEvent($context, $identityHash, $ipHash, $decision, 'max_attempts', $challengeId);
            $this->recordEvent($context, $identityHash, $ipHash, $decision, 'block_started', $challengeId);

            return $decision;
        }, self::TRANSACTION_ATTEMPTS);
    }

    public function identityHash(OtpRequestContext $context): string
    {
        return $this->hasher->hashIdentity(
            purpose: $context->purpose,
            userId: $context->userId,
            subjectType: $context->subjectType,
            subjectKey: $context->subjectKey,
            contextType: $context->contextType,
            contextId: $context->contextId,
        );
    }

    public function hasher(): OtpAbuseKeyHasher
    {
        return $this->hasher;
    }

    /**
     * Acquire identity then IP locks in a fixed order (deadlock avoidance).
     *
     * @return array{0: OtpRateLimit, 1: ?OtpRateLimit}
     */
    public function lockBucketsOrdered(OtpRequestContext $context): array
    {
        $identityHash = $this->identityHash($context);
        $ipHash = $this->hasher->hashIp($context->clientIp);

        $identityBucket = $this->lockBucket(
            OtpRateLimit::BUCKET_IDENTITY,
            $identityHash,
            $context->purpose->value,
        );

        $ipBucket = null;
        if ($ipHash !== null) {
            $ipBucket = $this->lockBucket(
                OtpRateLimit::BUCKET_IP,
                $ipHash,
                $context->purpose->value,
            );
        }

        return [$identityBucket, $ipBucket];
    }

    /**
     * Idempotent bucket init for a missing row, then row lock.
     *
     * Race when the row does not exist yet:
     * 1) insertOrIgnore — at most one physical insert wins the unique key;
     * 2) SELECT ... FOR UPDATE — loser waits for the winner's transaction;
     * 3) if the row is still invisible (rare), create + catch ONLY
     *    UniqueConstraintViolationException, then re-lock.
     *
     * Never catches generic QueryException / deadlock here — those propagate
     * so DB::transaction(..., attempts) can retry the whole unit of work.
     */
    public function lockBucket(string $type, string $hash, string $purpose): OtpRateLimit
    {
        $now = now();

        OtpRateLimit::query()->insertOrIgnore([
            'bucket_type' => $type,
            'bucket_key_hash' => $hash,
            'purpose' => $purpose,
            'window_started_at' => $now,
            'request_count' => 0,
            'last_allowed_at' => null,
            'blocked_until' => null,
            'last_challenge_id' => null,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        $bucket = $this->findBucketForUpdate($type, $hash, $purpose);
        if ($bucket !== null) {
            return $bucket;
        }

        try {
            $bucket = OtpRateLimit::query()->create([
                'bucket_type' => $type,
                'bucket_key_hash' => $hash,
                'purpose' => $purpose,
                'window_started_at' => $now,
                'request_count' => 0,
                'last_allowed_at' => null,
                'blocked_until' => null,
                'last_challenge_id' => null,
            ]);
        } catch (UniqueConstraintViolationException) {
            $bucket = $this->findBucketForUpdate($type, $hash, $purpose);
        }

        if ($bucket === null) {
            throw new OtpChallengeException(
                'No se pudo inicializar el control antiabuso OTP.',
                'OTP_RATE_LIMIT_INIT_FAILED',
            );
        }

        return $this->findBucketForUpdate($type, $hash, $purpose)
            ?? $bucket;
    }

    private function findBucketForUpdate(string $type, string $hash, string $purpose): ?OtpRateLimit
    {
        return OtpRateLimit::query()
            ->where('bucket_type', $type)
            ->where('bucket_key_hash', $hash)
            ->where('purpose', $purpose)
            ->lockForUpdate()
            ->first();
    }

    private function refreshWindow(OtpRateLimit $bucket): void
    {
        $dirty = false;

        if ($bucket->blocked_until !== null && $bucket->blocked_until->isPast()) {
            $bucket->blocked_until = null;
            $dirty = true;
        }

        if ($bucket->window_started_at === null
            || $bucket->window_started_at->lte(now()->subMinutes($this->windowMinutes()))
        ) {
            $bucket->window_started_at = now();
            $bucket->request_count = 0;
            $dirty = true;
        }

        if ($dirty) {
            $bucket->save();
        }
    }

    private function blockedDecision(
        OtpRateLimit $identityBucket,
        ?OtpRateLimit $ipBucket,
        P0aOtpPurpose $purpose,
    ): ?OtpRateLimitDecision {
        $identityBlocked = $identityBucket->isBlocked();
        $ipBlocked = $ipBucket?->isBlocked() ?? false;

        if (! $identityBlocked && ! $ipBlocked) {
            return null;
        }

        if ($identityBlocked && $ipBlocked) {
            $until = $identityBucket->blocked_until->greaterThan($ipBucket->blocked_until)
                ? $identityBucket->blocked_until
                : $ipBucket->blocked_until;
            $scope = OtpRateLimitDecision::SCOPE_BOTH;
        } elseif ($identityBlocked) {
            $until = $identityBucket->blocked_until;
            $scope = OtpRateLimitDecision::SCOPE_IDENTITY;
        } else {
            $until = $ipBucket->blocked_until;
            $scope = OtpRateLimitDecision::SCOPE_IP;
        }

        $retryAfter = max(1, $until->getTimestamp() - now()->getTimestamp());

        return OtpRateLimitDecision::deny(
            errorCode: OtpRateLimitDecision::CODE_BLOCKED,
            publicMessage: 'Demasiados intentos. Intenta mas tarde.',
            decision: 'blocked',
            scope: $scope,
            retryAfterSeconds: $retryAfter,
            availableAt: $until,
            purpose: $purpose->value,
        );
    }

    private function cooldownDecision(
        OtpRateLimit $identityBucket,
        P0aOtpPurpose $purpose,
    ): ?OtpRateLimitDecision {
        if ($identityBucket->last_allowed_at === null) {
            return null;
        }

        $availableAt = $identityBucket->last_allowed_at->copy()->addSeconds($this->cooldownSeconds());

        if ($availableAt->lte(now())) {
            return null;
        }

        $retryAfter = max(1, $availableAt->getTimestamp() - now()->getTimestamp());

        return OtpRateLimitDecision::deny(
            errorCode: OtpRateLimitDecision::CODE_COOLDOWN,
            publicMessage: 'Espera unos segundos antes de solicitar otro codigo.',
            decision: 'cooldown',
            scope: OtpRateLimitDecision::SCOPE_IDENTITY,
            retryAfterSeconds: $retryAfter,
            availableAt: $availableAt,
            purpose: $purpose->value,
        );
    }

    private function applyBlock(
        OtpRateLimit $identityBucket,
        ?OtpRateLimit $ipBucket,
        P0aOtpPurpose $purpose,
        string $scope,
        string $decisionName,
        bool $alsoBlockIp = false,
        bool $alsoBlockIdentity = true,
        ?string $errorCode = null,
        ?string $publicMessage = null,
    ): OtpRateLimitDecision {
        $availableAt = now()->addMinutes($this->blockMinutes());
        $retryAfter = max(1, $availableAt->getTimestamp() - now()->getTimestamp());

        if ($alsoBlockIdentity) {
            $identityBucket->blocked_until = $availableAt;
            $identityBucket->save();
        }

        if ($alsoBlockIp && $ipBucket !== null) {
            $ipBucket->blocked_until = $availableAt;
            $ipBucket->save();
        }

        return OtpRateLimitDecision::deny(
            errorCode: $errorCode ?? OtpRateLimitDecision::CODE_RATE_LIMITED,
            publicMessage: $publicMessage ?? 'Demasiados intentos. Intenta mas tarde.',
            decision: $decisionName,
            scope: $scope,
            retryAfterSeconds: $retryAfter,
            availableAt: $availableAt,
            purpose: $purpose->value,
        );
    }

    private function recordEvent(
        OtpRequestContext $context,
        string $identityHash,
        ?string $ipHash,
        OtpRateLimitDecision $decision,
        string $decisionName,
        ?int $challengeId = null,
    ): void {
        if (! (bool) config('otp.p0a.policy.audit_enabled', true)) {
            return;
        }

        OtpAbuseEvent::query()->create([
            'decision' => $decisionName,
            'error_code' => $decision->errorCode,
            'purpose' => $context->purpose->value,
            'identity_key_hash' => $identityHash,
            'ip_key_hash' => $ipHash,
            'scope' => $decision->scope,
            'retry_after_seconds' => $decision->retryAfterSeconds,
            'available_at' => $decision->availableAt,
            'otp_challenge_id' => $challengeId,
            'meta' => [
                'channel' => $context->channel?->value,
                'has_ip' => $ipHash !== null,
                'has_user' => $context->userId !== null,
            ],
            'created_at' => now(),
        ]);
    }

    private function cooldownSeconds(): int
    {
        return (int) config('otp.p0a.policy.cooldown_seconds', 60);
    }

    private function blockMinutes(): int
    {
        return (int) config('otp.p0a.policy.block_minutes', 30);
    }

    private function windowMinutes(): int
    {
        return (int) config(
            'otp.p0a.anti_abuse.rate_limit_window_minutes',
            config('otp.p0a.policy.resend_window_minutes', 30)
        );
    }

    /**
     * Max allowed issue/resend operations per identity+purpose window.
     * Defaults to min(identity_max_requests, 1 + max_resends).
     */
    private function maxAllowedRequests(): int
    {
        $configured = (int) config('otp.p0a.anti_abuse.identity_max_requests', 4);
        $fromResends = 1 + (int) config('otp.p0a.policy.max_resends', 3);

        return max(1, min($configured, $fromResends));
    }

    private function ipMaxRequests(): int
    {
        return (int) config('otp.p0a.anti_abuse.ip_max_requests', 20);
    }
}
