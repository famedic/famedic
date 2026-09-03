<?php

namespace App\Services\LaboratoryStores\Gda;

class GdaPhoneNormalizer
{
    public function normalize(null|string|int|float $value): NormalizationResult
    {
        $raw = trim((string) $value);

        if ($raw === '') {
            return new NormalizationResult(null);
        }

        $digits = preg_replace('/\D+/', '', $raw) ?? '';

        if (strlen($digits) === 12 && str_starts_with($digits, '52')) {
            $digits = substr($digits, 2);
        }

        if (strlen($digits) === 11 && str_starts_with($digits, '1')) {
            $digits = substr($digits, 1);
        }

        if (strlen($digits) !== 10) {
            return new NormalizationResult(null, [], ["Invalid phone length: {$raw}"]);
        }

        return new NormalizationResult($digits);
    }
}
