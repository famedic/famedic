<?php

namespace App\Services\Otp\Registration;

use App\Exceptions\Otp\OtpIdentityNormalizationException;

/**
 * Side-effect-free email normalizer for Akubica secure registration.
 */
final class EmailNormalizer
{
    /**
     * @throws OtpIdentityNormalizationException
     */
    public function normalize(string $input): NormalizedEmail
    {
        $trimmed = trim($input);
        if ($trimmed === '') {
            throw new OtpIdentityNormalizationException(
                'El correo electronico no es valido.',
                'OTP_EMAIL_INVALID',
            );
        }

        $normalized = mb_strtolower($trimmed, 'UTF-8');

        if (! filter_var($normalized, FILTER_VALIDATE_EMAIL)) {
            throw new OtpIdentityNormalizationException(
                'El correo electronico no es valido.',
                'OTP_EMAIL_INVALID',
            );
        }

        if (strlen($normalized) > 255) {
            throw new OtpIdentityNormalizationException(
                'El correo electronico no es valido.',
                'OTP_EMAIL_INVALID',
            );
        }

        return new NormalizedEmail($normalized);
    }
}
