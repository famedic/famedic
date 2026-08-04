<?php

namespace App\Services;

use App\Enums\OtpPurpose;
use App\Models\OtpCode;
use App\Models\User;
use App\Notifications\CouponAuthorizationOtpNotification;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class CouponAuthorizationOtpService
{
    private const VERIFIED_PREFIX = 'coupon_authorization_verified:';

    public function __construct(
        private AdminOtpService $adminOtpService,
    ) {}

    public function resendCooldownSeconds(): int
    {
        return (int) config('coupons.authorization_otp_resend_seconds', 60);
    }

    public function verificationTtlMinutes(): int
    {
        return (int) config('coupons.authorization_otp_verification_ttl_minutes', 5);
    }

    public function challengeIdFor(int $couponId, ?int $approvalRequestId = null): string
    {
        return 'auth:coupon:'.$couponId.':request:'.($approvalRequestId ?? 0);
    }

    /**
     * @return array{challenge_id: string, expires_in: int, resend_in: int, channel: string}
     */
    public function send(User $user, string $channel, int $couponId, ?int $approvalRequestId = null): array
    {
        $challengeId = $this->challengeIdFor($couponId, $approvalRequestId);

        $otp = $this->adminOtpService->issue(
            userId: (int) $user->id,
            purpose: OtpPurpose::CouponAuthorizationApproval,
            channel: $channel,
            challengeId: $challengeId,
        );

        $user->notify(new CouponAuthorizationOtpNotification($otp['plain_code'], $channel));

        $this->adminOtpService->logAccess('coupon_authorization_otp_requested', (int) $user->id, null, $channel, [
            'purpose' => OtpPurpose::CouponAuthorizationApproval->value,
            'challenge_id' => $challengeId,
            'coupon_id' => $couponId,
            'approval_request_id' => $approvalRequestId,
            'otp_id' => $otp['otp_id'],
        ]);

        Log::info('coupon_authorization_otp_requested', [
            'user_id' => $user->id,
            'coupon_id' => $couponId,
            'approval_request_id' => $approvalRequestId,
            'challenge_id' => $challengeId,
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
    public function verify(User $user, int $couponId, ?int $approvalRequestId, string $plainCode): array
    {
        $challengeId = $this->challengeIdFor($couponId, $approvalRequestId);

        $result = $this->adminOtpService->verify(
            userId: (int) $user->id,
            purpose: OtpPurpose::CouponAuthorizationApproval,
            plainCode: $plainCode,
            challengeId: $challengeId,
        );

        $token = (string) Str::uuid();
        $ttl = $this->verificationTtlMinutes();

        Cache::put(self::VERIFIED_PREFIX.$token, [
            'user_id' => $user->id,
            'coupon_id' => $couponId,
            'approval_request_id' => $approvalRequestId,
            'challenge_id' => $challengeId,
            'otp_id' => $result['otp_id'],
        ], now()->addMinutes($ttl));

        $this->adminOtpService->logAccess('coupon_authorization_otp_verified', (int) $user->id, null, null, [
            'purpose' => OtpPurpose::CouponAuthorizationApproval->value,
            'challenge_id' => $challengeId,
            'coupon_id' => $couponId,
            'approval_request_id' => $approvalRequestId,
            'otp_id' => $result['otp_id'],
        ]);

        return [
            'verification_token' => $token,
            'expires_in' => $ttl * 60,
        ];
    }

    public function assertVerified(User $user, string $verificationToken, int $couponId, ?int $approvalRequestId): void
    {
        $entry = Cache::get(self::VERIFIED_PREFIX.$verificationToken);
        if (! is_array($entry) || (int) ($entry['user_id'] ?? 0) !== (int) $user->id) {
            throw new \DomainException('La verificación OTP expiró o no es válida. Solicita un nuevo código.');
        }

        if ((int) ($entry['coupon_id'] ?? 0) !== $couponId) {
            throw new \DomainException('La verificación OTP no corresponde a esta solicitud.');
        }

        $storedRequestId = $entry['approval_request_id'] ?? null;
        if ((int) ($storedRequestId ?? 0) !== (int) ($approvalRequestId ?? 0)) {
            throw new \DomainException('La verificación OTP no corresponde a esta solicitud.');
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

        $this->adminOtpService->logAccess('coupon_authorization_otp_consumed', (int) $user->id, null, null, [
            'purpose' => OtpPurpose::CouponAuthorizationApproval->value,
            'challenge_id' => $entry['challenge_id'] ?? null,
            'coupon_id' => $entry['coupon_id'] ?? null,
            'approval_request_id' => $entry['approval_request_id'] ?? null,
            'otp_id' => $entry['otp_id'] ?? null,
        ]);
    }

    public function latestOtpForChallenge(int $userId, string $challengeId): ?OtpCode
    {
        return OtpCode::query()
            ->where('user_id', $userId)
            ->where('purpose', OtpPurpose::CouponAuthorizationApproval->value)
            ->where('challenge_id', $challengeId)
            ->latest('id')
            ->first();
    }
}
