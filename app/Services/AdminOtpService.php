<?php

namespace App\Services;

use App\Enums\OtpPurpose;
use App\Models\OtpAccessLog;
use App\Models\OtpCode;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class AdminOtpService
{
    public const MAX_ATTEMPTS = 5;

    /**
     * @return array{plain_code: string, expires_at: \Illuminate\Support\Carbon, otp_id: int}
     */
    public function issue(
        int $userId,
        OtpPurpose $purpose,
        string $channel,
        ?string $challengeId = null,
        ?int $laboratoryPurchaseId = null,
    ): array {
        return DB::transaction(function () use ($userId, $purpose, $channel, $challengeId, $laboratoryPurchaseId) {
            $query = OtpCode::query()
                ->where('user_id', $userId)
                ->where('purpose', $purpose->value)
                ->where('status', OtpCode::STATUS_PENDING);

            if ($challengeId !== null) {
                $query->where('challenge_id', $challengeId);
            }

            if ($laboratoryPurchaseId !== null) {
                $query->where('laboratory_purchase_id', $laboratoryPurchaseId);
            }

            $query->update(['status' => OtpCode::STATUS_EXPIRED]);

            $plainCode = str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT);
            $expiryMinutes = max(1, (int) config('otp.expiry', 10));
            $expiresAt = now()->addMinutes($expiryMinutes);

            $row = OtpCode::query()->create([
                'user_id' => $userId,
                'purpose' => $purpose->value,
                'challenge_id' => $challengeId ?? (string) Str::uuid(),
                'laboratory_purchase_id' => $laboratoryPurchaseId,
                'channel' => $channel,
                'code' => Hash::make($plainCode),
                'expires_at' => $expiresAt,
                'attempts' => 0,
                'status' => OtpCode::STATUS_PENDING,
            ]);

            return [
                'plain_code' => $plainCode,
                'expires_at' => $expiresAt,
                'otp_id' => (int) $row->id,
                'challenge_id' => $row->challenge_id,
            ];
        });
    }

    /**
     * @return array{verified: true, otp_id: int, challenge_id: ?string}
     *
     * @throws \DomainException
     */
    public function verify(
        int $userId,
        OtpPurpose $purpose,
        string $plainCode,
        ?string $challengeId = null,
        ?int $laboratoryPurchaseId = null,
    ): array {
        $query = OtpCode::query()
            ->where('user_id', $userId)
            ->where('purpose', $purpose->value)
            ->where('status', OtpCode::STATUS_PENDING)
            ->latest('id');

        if ($challengeId !== null) {
            $query->where('challenge_id', $challengeId);
        }

        if ($laboratoryPurchaseId !== null) {
            $query->where('laboratory_purchase_id', $laboratoryPurchaseId);
        }

        $otp = $query->first();

        if ($otp === null) {
            throw new \DomainException('No hay un código activo. Solicita uno nuevo.');
        }

        if ($otp->expires_at->isPast()) {
            $otp->update(['status' => OtpCode::STATUS_EXPIRED]);
            throw new \DomainException('El código expiró. Solicita uno nuevo.');
        }

        if ($otp->attempts >= self::MAX_ATTEMPTS) {
            $otp->update(['status' => OtpCode::STATUS_FAILED]);
            throw new \DomainException('Demasiados intentos fallidos. Solicita un código nuevo.');
        }

        if (! Hash::check($plainCode, $otp->code)) {
            $otp->increment('attempts');
            if ($otp->fresh()->attempts >= self::MAX_ATTEMPTS) {
                $otp->update(['status' => OtpCode::STATUS_FAILED]);
                throw new \DomainException('Demasiados intentos fallidos. Solicita un código nuevo.');
            }
            throw new \DomainException('Código incorrecto.');
        }

        $otp->update([
            'status' => OtpCode::STATUS_VERIFIED,
            'verified_at' => now(),
        ]);

        return [
            'verified' => true,
            'otp_id' => (int) $otp->id,
            'challenge_id' => $otp->challenge_id,
        ];
    }

    public function resendCooldownRemaining(?OtpCode $latestOtp, int $cooldownSeconds): int
    {
        if ($latestOtp === null) {
            return 0;
        }

        $elapsed = now()->diffInSeconds($latestOtp->created_at);

        return (int) max(0, $cooldownSeconds - $elapsed);
    }

    public function logAccess(
        string $event,
        ?int $userId,
        ?int $purchaseId,
        ?string $channel,
        array $meta = [],
    ): void {
        try {
            OtpAccessLog::query()->create([
                'user_id' => $userId,
                'laboratory_purchase_id' => $purchaseId,
                'event' => $event,
                'channel' => $channel,
                'ip_address' => request()->ip(),
                'user_agent' => substr((string) request()->userAgent(), 0, 2000),
                'meta' => $meta ?: null,
            ]);
        } catch (\Throwable $e) {
            Log::warning('otp_access_log_write_failed', ['error' => $e->getMessage()]);
        }
    }

    public function otpExpiresInSeconds(\DateTimeInterface $expiresAt): int
    {
        return (int) max(0, $expiresAt->getTimestamp() - now()->getTimestamp());
    }
}
