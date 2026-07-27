<?php

namespace App\Services\Otp\Registration;

use Illuminate\Support\Facades\Cache;

/**
 * Ephemeral store for secure-register OTP decoy challenge_ids (anti-enumeration).
 *
 * Separate from AkubicaLoginOtpDecoyStore (distinct cache prefix). Stores no full
 * email, phone, OTP, or user_id. Business expiry uses stored timestamps.
 */
final class AkubicaRegisterOtpDecoyStore
{
    public const CACHE_PREFIX = 'otp:p0a5:register:decoy:';

    public const GRACE_SECONDS = 3600;

    /**
     * @param  array{
     *     destination_masked: string,
     *     last_sent_at: int,
     *     expires_at: int,
     *     failed_attempts: int,
     *     max_attempts: int,
     *     invalidated_at: int|null,
     *     invalidation_reason: string|null
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
     *     invalidation_reason: string|null
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
