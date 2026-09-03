<?php

namespace App\Services\LaboratoryStores\Gda;

class GdaPostalCodeNormalizer
{
    public function normalize(null|string|int|float $value): NormalizationResult
    {
        $raw = trim((string) $value);

        if ($raw === '') {
            return new NormalizationResult(null);
        }

        $digits = preg_replace('/\D+/', '', $raw) ?? '';

        if ($digits === '' || strlen($digits) > 5) {
            return new NormalizationResult(null, [], ["Invalid postal code: {$raw}"]);
        }

        return new NormalizationResult(str_pad($digits, 5, '0', STR_PAD_LEFT));
    }
}
