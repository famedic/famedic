<?php

namespace App\Actions\Api\V1\Auth;

use App\Exceptions\Api\V1\Auth\AuthOtpVerificationException;
use App\Models\OtpCode;
use Illuminate\Support\Facades\Hash;

class VerifyAuthOtpAction
{
    /**
     * @throws AuthOtpVerificationException
     */
    public function __invoke(string $email, string $code, string $purpose): OtpCode
    {
        $otp = OtpCode::query()
            ->where('email', $email)
            ->where('purpose', $purpose)
            ->where('status', OtpCode::STATUS_PENDING)
            ->whereNull('used_at')
            ->latest('id')
            ->first();

        if (! $otp) {
            throw new AuthOtpVerificationException(
                'NO_ACTIVE_CODE',
                'No hay un código activo para este correo.',
            );
        }

        if ($otp->expires_at && $otp->expires_at->isPast()) {
            $otp->update(['status' => OtpCode::STATUS_EXPIRED]);

            throw new AuthOtpVerificationException(
                'CODE_EXPIRED',
                'El código expiró. Solicita uno nuevo.',
            );
        }

        $maxAttempts = (int) ($otp->max_attempts ?: config('akubica.otp_max_attempts', 5));

        if ((int) $otp->attempts >= $maxAttempts) {
            $otp->update(['status' => OtpCode::STATUS_FAILED]);

            throw new AuthOtpVerificationException(
                'ATTEMPTS_EXHAUSTED',
                'Se agotaron los intentos. Solicita un código nuevo.',
            );
        }

        if (! Hash::check($code, (string) $otp->code)) {
            $otp->increment('attempts');

            if ((int) $otp->fresh()->attempts >= $maxAttempts) {
                $otp->update(['status' => OtpCode::STATUS_FAILED]);

                throw new AuthOtpVerificationException(
                    'ATTEMPTS_EXHAUSTED',
                    'Se agotaron los intentos. Solicita un código nuevo.',
                );
            }

            throw new AuthOtpVerificationException(
                'INVALID_CODE',
                'El código ingresado no es válido.',
            );
        }

        $otp->update([
            'status' => OtpCode::STATUS_VERIFIED,
            'verified_at' => now(),
            'used_at' => now(),
        ]);

        return $otp->fresh();
    }
}
