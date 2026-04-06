<?php

namespace App\Support;

use Illuminate\Http\Request;

/**
 * Sesión de confianza tras validar OTP para resultados de laboratorio (independiente del código OTP).
 */
final class LabResultsOtpTrustSession
{
    public static function sessionKey(int $purchaseId): string
    {
        return "otp_verified_at:lab_results:purchase:{$purchaseId}";
    }

    public static function trustMinutes(): int
    {
        return max(1, (int) config('laboratory-results.otp_trust_session_minutes', 30));
    }

    public static function remainingSeconds(Request $request, int $purchaseId): int
    {
        $verifiedAt = $request->session()->get(self::sessionKey($purchaseId));
        if (! $verifiedAt) {
            return 0;
        }

        $verifiedAtTs = is_numeric($verifiedAt) ? (int) $verifiedAt : strtotime((string) $verifiedAt);
        if (! $verifiedAtTs) {
            return 0;
        }

        $expiresAtTs = $verifiedAtTs + (self::trustMinutes() * 60);

        return max(0, $expiresAtTs - time());
    }

    public static function isValid(Request $request, int $purchaseId): bool
    {
        return self::remainingSeconds($request, $purchaseId) > 0;
    }
}
