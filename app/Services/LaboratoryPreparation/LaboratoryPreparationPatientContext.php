<?php

namespace App\Services\LaboratoryPreparation;

use InvalidArgumentException;

final class LaboratoryPreparationPatientContext
{
    public function __construct(
        public readonly ?int $ageYears,
        public readonly ?string $label = null,
        public readonly string $ageSource = 'unknown',
    ) {}

    public static function fromAgeYears(int $ageYears): self
    {
        if ($ageYears < 0) {
            throw new InvalidArgumentException('Patient age years must be zero or positive.');
        }

        return new self(
            ageYears: $ageYears,
            label: null,
            ageSource: 'age_years',
        );
    }

    /**
     * Interpreta etiquetas de casos QA oficiales (p. ej. "Adulto", "Menor de 3 años").
     */
    public static function fromOfficialLabel(string $patientContext): self
    {
        $normalized = mb_strtolower(trim($patientContext));

        if (preg_match('/menor\s+de\s+(\d+)\s*a[nñ]os?/u', $normalized, $matches) === 1) {
            $threshold = (int) $matches[1];

            return new self(
                ageYears: max(0, $threshold - 1),
                label: $patientContext,
                ageSource: 'official_label_pediatric',
            );
        }

        if (str_contains($normalized, 'adulto') || str_contains($normalized, 'adulta')) {
            return new self(
                ageYears: 30,
                label: $patientContext,
                ageSource: 'official_label_adult',
            );
        }

        if (preg_match('/(\d+)\s*a[nñ]os?/u', $normalized, $matches) === 1) {
            return new self(
                ageYears: (int) $matches[1],
                label: $patientContext,
                ageSource: 'official_label_explicit_age',
            );
        }

        return new self(
            ageYears: null,
            label: $patientContext !== '' ? $patientContext : null,
            ageSource: 'official_label_unresolved',
        );
    }

    public function isPediatricUnderYears(int $years): bool
    {
        if ($this->ageYears === null) {
            return false;
        }

        return $this->ageYears < $years;
    }

    /**
     * @return array{age_years: int|null, age_source: string}
     */
    public function toHashPayload(): array
    {
        return [
            'age_years' => $this->ageYears,
            'age_source' => $this->ageSource,
        ];
    }
}
