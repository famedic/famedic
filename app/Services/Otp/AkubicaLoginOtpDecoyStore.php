<?php

namespace App\Services\Otp;

use Illuminate\Support\Facades\Cache;

/**
 * Ephemeral store for login OTP decoy challenge_ids (anti-enumeration).
 *
 * Recognizes only challenge_ids previously issued by decoyRequestResponse / decoy resend.
 * Stores no full email, IP, OTP, or user_id. Cache TTL bounds growth; business expiry
 * is enforced from stored timestamps so Carbon::setTestNow works in tests.
 *
 * Production: use a shared cache (e.g. Redis) across app nodes. If the cache is lost
 * or unavailable, issued decoy IDs degrade to the same public path as never-issued
 * UUIDs (NO_ACTIVE_CODE) — residual enumeration risk until TTL/natural churn.
 */
class AkubicaLoginOtpDecoyStore
{
    public const CACHE_PREFIX = 'otp:p0a4:login:decoy:';

    /**
     * Extra retention after expires_at so superseded IDs can still return
     * CODE_INVALIDATED instead of disappearing immediately.
     */
    public const GRACE_SECONDS = 3600;

    /**
     * @param  array{
     *     destination_masked: string,
     *     last_sent_at: int,
     *     expires_at: int,
     *     failed_attempts: int,
     *     max_attempts: int,
     *     invalidated_at: int|null,
     *     invalidated_reason: string|null
     * }  $state
     */
    public function put(string $publicId, array $state): void
    {
        Cache::put(
            $this->key($publicId),
            $state,
            $this->cacheTtlSeconds($state['expires_at']),
        );
    }

    /**
     * @return array{
     *     destination_masked: string,
     *     last_sent_at: int,
     *     expires_at: int,
     *     failed_attempts: int,
     *     max_attempts: int,
     *     invalidated_at: int|null,
     *     invalidated_reason: string|null
     * }|null
     */
    public function get(string $publicId): ?array
    {
        $state = Cache::get($this->key($publicId));

        return is_array($state) ? $state : null;
    }

    public function forget(string $publicId): void
    {
        Cache::forget($this->key($publicId));
    }

    public function exists(string $publicId): bool
    {
        return $this->get($publicId) !== null;
    }

    private function key(string $publicId): string
    {
        return self::CACHE_PREFIX.$publicId;
    }

    private function cacheTtlSeconds(int $expiresAtUnix): int
    {
        $remaining = $expiresAtUnix - now()->getTimestamp();

        return max(60, $remaining + self::GRACE_SECONDS);
    }
}
