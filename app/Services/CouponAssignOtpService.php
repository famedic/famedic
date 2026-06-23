<?php

namespace App\Services;

use App\Enums\OtpPurpose;
use App\Models\OtpCode;
use App\Models\User;
use App\Notifications\CouponCreationOtpNotification;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class CouponAssignOtpService
{
    private const DRAFT_PREFIX = 'coupon_assign_draft:';

    private const VERIFIED_PREFIX = 'coupon_assign_verified:';

    public function __construct(
        private AdminOtpService $adminOtpService,
    ) {}

    public function isRequired(): bool
    {
        return (bool) config('coupons.creation_otp_required', true);
    }

    public function resendCooldownSeconds(): int
    {
        return (int) config('coupons.creation_otp_resend_seconds', 60);
    }

    public function verificationTtlMinutes(): int
    {
        return (int) config('coupons.creation_otp_verification_ttl_minutes', 15);
    }

    /**
     * @param  array<string, mixed>  $assignPayload
     * @return array{challenge_id: string, expires_in: int, resend_in: int, channel: string}
     */
    public function send(User $user, string $channel, array $assignPayload): array
    {
        $normalized = $this->normalizePayload($assignPayload);
        $challengeId = (string) Str::uuid();
        $cacheKey = self::DRAFT_PREFIX.$challengeId;

        Cache::put($cacheKey, [
            'user_id' => $user->id,
            'payload' => $normalized,
            'payload_hash' => $this->hashPayload($normalized),
        ], now()->addMinutes(max(1, (int) config('otp.expiry', 10))));

        $otp = $this->adminOtpService->issue(
            userId: (int) $user->id,
            purpose: OtpPurpose::CouponCreation,
            channel: $channel,
            challengeId: $challengeId,
        );

        $user->notify(new CouponCreationOtpNotification($otp['plain_code'], $channel));

        $this->adminOtpService->logAccess('coupon_creation_otp_requested', (int) $user->id, null, $channel, [
            'purpose' => OtpPurpose::CouponCreation->value,
            'challenge_id' => $challengeId,
            'otp_id' => $otp['otp_id'],
            'payload_hash' => $this->hashPayload($normalized),
        ]);

        Log::info('coupon_creation_otp_requested', [
            'user_id' => $user->id,
            'challenge_id' => $challengeId,
            'channel' => $channel,
            'otp_id' => $otp['otp_id'],
            'expires_in' => $this->adminOtpService->otpExpiresInSeconds($otp['expires_at']),
        ]);

        return [
            'challenge_id' => $challengeId,
            'expires_in' => $this->adminOtpService->otpExpiresInSeconds($otp['expires_at']),
            'resend_in' => $this->resendCooldownSeconds(),
            'channel' => $channel,
        ];
    }

    /**
     * @param  array<string, mixed>  $assignPayload
     * @return array{challenge_id: string, expires_in: int, resend_in: int, channel: string}
     */
    public function resend(User $user, string $channel, array $assignPayload, string $challengeId): array
    {
        $cacheKey = self::DRAFT_PREFIX.$challengeId;
        $draft = Cache::get($cacheKey);

        $normalized = $this->normalizePayload($assignPayload);
        $hash = $this->hashPayload($normalized);

        if (! is_array($draft) || (int) ($draft['user_id'] ?? 0) !== (int) $user->id) {
            return $this->send($user, $channel, $assignPayload);
        }

        Cache::put($cacheKey, [
            'user_id' => $user->id,
            'payload' => $normalized,
            'payload_hash' => $hash,
        ], now()->addMinutes(max(1, (int) config('otp.expiry', 10))));

        $otp = $this->adminOtpService->issue(
            userId: (int) $user->id,
            purpose: OtpPurpose::CouponCreation,
            channel: $channel,
            challengeId: $challengeId,
        );

        $user->notify(new CouponCreationOtpNotification($otp['plain_code'], $channel));

        $this->adminOtpService->logAccess('coupon_creation_otp_resent', (int) $user->id, null, $channel, [
            'purpose' => OtpPurpose::CouponCreation->value,
            'challenge_id' => $challengeId,
            'otp_id' => $otp['otp_id'],
            'payload_hash' => $hash,
        ]);

        return [
            'challenge_id' => $challengeId,
            'expires_in' => $this->adminOtpService->otpExpiresInSeconds($otp['expires_at']),
            'resend_in' => $this->resendCooldownSeconds(),
            'channel' => $channel,
        ];
    }

    /**
     * @return array{verification_token: string, expires_in: int}
     */
    public function verify(User $user, string $challengeId, string $plainCode): array
    {
        $draft = Cache::get(self::DRAFT_PREFIX.$challengeId);
        if (! is_array($draft) || (int) ($draft['user_id'] ?? 0) !== (int) $user->id) {
            throw new \DomainException('La solicitud de verificación expiró o no es válida. Vuelve a intentar guardar el cupón.');
        }

        $result = $this->adminOtpService->verify(
            userId: (int) $user->id,
            purpose: OtpPurpose::CouponCreation,
            plainCode: $plainCode,
            challengeId: $challengeId,
        );

        $token = (string) Str::uuid();
        $ttl = $this->verificationTtlMinutes();

        Cache::put(self::VERIFIED_PREFIX.$token, [
            'user_id' => $user->id,
            'challenge_id' => $challengeId,
            'payload_hash' => $draft['payload_hash'],
            'otp_id' => $result['otp_id'],
        ], now()->addMinutes($ttl));

        Cache::forget(self::DRAFT_PREFIX.$challengeId);

        $this->adminOtpService->logAccess('coupon_creation_otp_verified', (int) $user->id, null, null, [
            'purpose' => OtpPurpose::CouponCreation->value,
            'challenge_id' => $challengeId,
            'otp_id' => $result['otp_id'],
            'payload_hash' => $draft['payload_hash'],
        ]);

        return [
            'verification_token' => $token,
            'expires_in' => $ttl * 60,
        ];
    }

    /**
     * @param  array<string, mixed>  $assignPayload
     */
    public function assertVerified(User $user, string $verificationToken, array $assignPayload): void
    {
        if (! $this->isRequired()) {
            return;
        }

        $entry = Cache::get(self::VERIFIED_PREFIX.$verificationToken);
        if (! is_array($entry) || (int) ($entry['user_id'] ?? 0) !== (int) $user->id) {
            throw new \DomainException('La verificación OTP expiró o no es válida. Solicita un nuevo código.');
        }

        $normalized = $this->normalizePayload($assignPayload);
        $hash = $this->hashPayload($normalized);
        if (! hash_equals((string) $entry['payload_hash'], $hash)) {
            throw new \DomainException('Los datos del cupón cambiaron después de la verificación OTP. Vuelve a verificar.');
        }
    }

    public function consumeVerificationToken(User $user, string $verificationToken): void
    {
        $key = self::VERIFIED_PREFIX.$verificationToken;
        $entry = Cache::get($key);
        if (! is_array($entry) || (int) ($entry['user_id'] ?? 0) !== (int) $user->id) {
            throw new \DomainException('La verificación OTP ya fue utilizada o expiró.');
        }

        Cache::forget($key);

        $this->adminOtpService->logAccess('coupon_creation_otp_consumed', (int) $user->id, null, null, [
            'purpose' => OtpPurpose::CouponCreation->value,
            'challenge_id' => $entry['challenge_id'] ?? null,
            'otp_id' => $entry['otp_id'] ?? null,
        ]);
    }

    public function latestOtpForChallenge(int $userId, string $challengeId): ?OtpCode
    {
        return OtpCode::query()
            ->where('user_id', $userId)
            ->where('purpose', OtpPurpose::CouponCreation->value)
            ->where('challenge_id', $challengeId)
            ->latest('id')
            ->first();
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    public function normalizePayload(array $payload): array
    {
        $copy = $payload;
        ksort($copy);
        unset($copy['file'], $copy['otp_verification_token']);

        return $copy;
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    public function hashPayload(array $payload): string
    {
        return hash('sha256', json_encode($this->normalizePayload($payload), JSON_THROW_ON_ERROR));
    }
}
