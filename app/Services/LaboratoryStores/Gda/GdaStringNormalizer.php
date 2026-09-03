<?php

namespace App\Services\LaboratoryStores\Gda;

use Illuminate\Support\Str;

class GdaStringNormalizer
{
    public function normalize(?string $value): string
    {
        $value = trim((string) $value);

        if ($value === '') {
            return '';
        }

        $value = Str::ascii(Str::lower($value));
        $value = preg_replace('/[.\-_,;:\/\\\\|()#]+/', ' ', $value) ?? $value;
        $value = preg_replace('/\s+/', ' ', $value) ?? $value;

        return trim($value);
    }

    public function normalizeBrand(?string $value): ?string
    {
        $brand = $this->normalize($value);

        return match ($brand) {
            'olab' => 'olab',
            'swiss lab', 'swisslab' => 'swisslab',
            'jenner' => 'jenner',
            'liacsa' => 'liacsa',
            'azteca' => 'azteca',
            default => $brand === '' ? null : $brand,
        };
    }
}
