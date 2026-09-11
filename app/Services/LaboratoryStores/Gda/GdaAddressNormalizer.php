<?php

namespace App\Services\LaboratoryStores\Gda;

use Illuminate\Support\Str;

class GdaAddressNormalizer
{
    public function __construct(private readonly GdaStringNormalizer $strings) {}

    public function normalize(?string $value): string
    {
        $value = $this->strings->normalize($value);

        if ($value === '') {
            return '';
        }

        $replacements = [
            '/\bav\b/' => 'avenida',
            '/\bave\b/' => 'avenida',
            '/\bavda\b/' => 'avenida',
            '/\bc\b/' => 'calle',
            '/\bcalz\b/' => 'calzada',
            '/\bblvd\b/' => 'boulevard',
            '/\bcol\b/' => 'colonia',
            '/\bno\b/' => 'numero',
            '/\bnum\b/' => 'numero',
            '/\besq\b/' => 'esquina',
            '/\bcdmx\b/' => 'ciudad de mexico',
            '/\bedo mex\b/' => 'estado de mexico',
            '/\bedomex\b/' => 'estado de mexico',
            '/\bmex\b/' => 'estado de mexico',
            '/\bqro\b/' => 'queretaro',
        ];

        foreach ($replacements as $pattern => $replacement) {
            $value = preg_replace($pattern, $replacement, $value) ?? $value;
        }

        return trim(preg_replace('/\s+/', ' ', $value) ?? $value);
    }

    public function normalizeState(?string $value): string
    {
        $value = $this->strings->normalize($value);

        return match ($value) {
            'cdmx', 'df', 'distrito federal', 'ciudad mexico', 'ciudad de mexico' => 'ciudad de mexico',
            'mexico', 'mex', 'edomex', 'edo mex', 'estado mexico', 'estado de mexico' => 'estado de mexico',
            'queretaro', 'qro' => 'queretaro',
            'nuevo leon', 'nl' => 'nuevo leon',
            default => $value,
        };
    }

    public function normalizeMunicipality(?string $value): string
    {
        $value = $this->strings->normalize($value);

        return match ($value) {
            'cdmx', 'ciudad mexico' => 'ciudad de mexico',
            'cuauhtemoc' => 'cuauhtemoc',
            'alvaro obregon' => 'alvaro obregon',
            'benito juarez' => 'benito juarez',
            'miguel hidalgo' => 'miguel hidalgo',
            'tlalnepantla', 'tlalnepantla de baz' => 'tlalnepantla de baz',
            'cuautitlan izcalli' => 'cuautitlan izcalli',
            'queretaro', 'santiago de queretaro' => 'queretaro',
            default => $value,
        };
    }

    public function similarity(?string $left, ?string $right): int
    {
        $left = $this->normalize($left);
        $right = $this->normalize($right);

        if ($left === '' || $right === '') {
            return 0;
        }

        similar_text($left, $right, $percent);

        return (int) round($percent);
    }

    public function containsPostalCode(?string $address, ?string $postalCode): bool
    {
        if ($postalCode === null || $postalCode === '') {
            return false;
        }

        return Str::contains($this->normalize($address), $postalCode);
    }

    public function containsMunicipality(?string $address, ?string $municipality): bool
    {
        $municipality = $this->normalizeMunicipality($municipality);

        if ($municipality === '') {
            return false;
        }

        return Str::contains($this->normalize($address), $municipality);
    }
}
