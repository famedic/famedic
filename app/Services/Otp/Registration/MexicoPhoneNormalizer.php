<?php

namespace App\Services\Otp\Registration;

use App\Exceptions\Otp\OtpIdentityNormalizationException;

/**
 * Mexico-focused phone normalizer for Akubica secure registration.
 *
 * Persistence shape matches legacy CreateUserAction: 10-digit national without
 * spaces + ISO-2 phone_country. Digit-first rules avoid silent ambiguity.
 *
 * +521 legacy: 13 digits starting with 521 → national = last 10 (strip trunk 1).
 * D3 historical cleanup remains open; this only normalizes comparison inputs.
 */
final class MexicoPhoneNormalizer
{
    public const DEFAULT_COUNTRY = 'MX';

    /**
     * @throws OtpIdentityNormalizationException
     */
    public function normalize(string $input, ?string $phoneCountry = null): PhoneIdentity
    {
        $raw = trim($input);
        if ($raw === '') {
            throw $this->invalid();
        }

        $lower = mb_strtolower($raw);
        if (preg_match('/[a-z]/', $lower) || str_contains($lower, 'ext')) {
            throw $this->invalid();
        }

        $country = strtoupper(trim((string) ($phoneCountry ?: self::DEFAULT_COUNTRY)));
        if ($country === '' || strlen($country) !== 2) {
            $country = self::DEFAULT_COUNTRY;
        }

        // Secure register comparison is MX-scoped in P0-A5.
        if ($country !== self::DEFAULT_COUNTRY && ! str_starts_with(ltrim($raw), '+')) {
            throw $this->invalid();
        }

        $digits = preg_replace('/\D+/', '', $raw) ?? '';
        if ($digits === '') {
            throw $this->invalid();
        }

        // Explicit non-MX international prefixes when + / country digits present.
        if (str_starts_with(ltrim($raw), '+') || str_starts_with($digits, '00')) {
            $intl = str_starts_with($digits, '00') ? substr($digits, 2) : $digits;
            if (! str_starts_with($intl, '52')) {
                throw $this->invalid();
            }
            $digits = $intl;
            $country = self::DEFAULT_COUNTRY;
        }

        $national = $this->toMexicoNational($digits);
        if ($national === null) {
            throw $this->invalid();
        }

        return new PhoneIdentity(
            countryCode: self::DEFAULT_COUNTRY,
            nationalNumber: $national,
            e164: '+52'.$national,
            comparisonKey: self::DEFAULT_COUNTRY.'|'.$national,
        );
    }

    /**
     * @return non-empty-string|null
     */
    private function toMexicoNational(string $digits): ?string
    {
        // Already national (10 digits).
        if (strlen($digits) === 10) {
            return $digits;
        }

        // Legacy trunk on national: 1 + 10 digits.
        if (strlen($digits) === 11 && str_starts_with($digits, '1')) {
            return substr($digits, 1);
        }

        // Country 52 + 10 digits.
        if (strlen($digits) === 12 && str_starts_with($digits, '52')) {
            return substr($digits, 2);
        }

        // Legacy 521 + 10 digits.
        if (strlen($digits) === 13 && str_starts_with($digits, '521')) {
            return substr($digits, 3);
        }

        return null;
    }

    private function invalid(): OtpIdentityNormalizationException
    {
        return new OtpIdentityNormalizationException(
            'El telefono no es valido.',
            'OTP_PHONE_INVALID',
        );
    }
}
