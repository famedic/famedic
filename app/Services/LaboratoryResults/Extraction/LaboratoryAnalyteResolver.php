<?php

namespace App\Services\LaboratoryResults\Extraction;

use App\Enums\LaboratoryAnalyteResolutionStatus;
use App\Models\LaboratoryAnalyte;
use Illuminate\Support\Collection;

class LaboratoryAnalyteResolver
{
    public function resolve(string $analyteNameRaw, ?string $loincCode = null): ?LaboratoryAnalyte
    {
        return $this->resolveDetailed($analyteNameRaw, null, $loincCode)->analyte;
    }

    public function resolveDetailed(
        string $analyteNameRaw,
        ?string $analyteCode = null,
        ?string $loincCode = null,
    ): LaboratoryAnalyteResolutionResult {
        if ($analyteCode !== null && trim($analyteCode) !== '') {
            $byCode = LaboratoryAnalyte::query()
                ->where('code', trim($analyteCode))
                ->where('is_active', true)
                ->first();

            if ($byCode) {
                return $this->resolved($byCode, $analyteNameRaw);
            }
        }

        $normalized = LaboratoryAnalyteNameNormalizer::normalize($analyteNameRaw);

        if ($normalized === '') {
            return $this->unresolved($analyteNameRaw, '');
        }

        $byAlias = LaboratoryAnalyte::query()
            ->where('is_active', true)
            ->whereHas('aliases', fn ($query) => $query->where('alias_normalized', $normalized))
            ->get();

        if ($byAlias->count() === 1) {
            return $this->resolved($byAlias->first(), $analyteNameRaw);
        }

        if ($byAlias->count() > 1) {
            return $this->ambiguous($analyteNameRaw, $normalized, $byAlias);
        }

        $byExactName = $this->findByExactNormalizedName($normalized);

        if ($byExactName->count() === 1) {
            return $this->resolved($byExactName->first(), $analyteNameRaw);
        }

        if ($byExactName->count() > 1) {
            return $this->ambiguous($analyteNameRaw, $normalized, $byExactName);
        }

        if ($loincCode !== null && trim($loincCode) !== '') {
            $byLoinc = LaboratoryAnalyte::query()
                ->where('loinc_code', trim($loincCode))
                ->where('is_active', true)
                ->first();

            if ($byLoinc) {
                return $this->resolved($byLoinc, $analyteNameRaw);
            }
        }

        return $this->unresolved($analyteNameRaw, $normalized);
    }

    /**
     * @return Collection<int, LaboratoryAnalyte>
     */
    private function findByExactNormalizedName(string $normalized): Collection
    {
        return LaboratoryAnalyte::query()
            ->where('is_active', true)
            ->where('code', $normalized)
            ->get()
            ->merge(
                LaboratoryAnalyte::query()
                    ->where('is_active', true)
                    ->get()
                    ->filter(fn (LaboratoryAnalyte $analyte): bool => LaboratoryAnalyteNameNormalizer::normalize($analyte->canonical_name) === $normalized)
            )
            ->unique('id')
            ->values();
    }

    private function resolved(LaboratoryAnalyte $analyte, string $analyteNameRaw): LaboratoryAnalyteResolutionResult
    {
        return new LaboratoryAnalyteResolutionResult(
            analyte: $analyte,
            status: LaboratoryAnalyteResolutionStatus::Resolved,
            normalizedName: LaboratoryAnalyteNameNormalizer::normalize($analyteNameRaw),
            identityKey: $analyte->code,
            analyteNameRaw: $analyteNameRaw,
        );
    }

    private function unresolved(string $analyteNameRaw, string $normalized): LaboratoryAnalyteResolutionResult
    {
        return new LaboratoryAnalyteResolutionResult(
            analyte: null,
            status: LaboratoryAnalyteResolutionStatus::Unresolved,
            normalizedName: $normalized,
            identityKey: $normalized !== '' ? $normalized : null,
            analyteNameRaw: $analyteNameRaw,
        );
    }

    /**
     * @param  Collection<int, LaboratoryAnalyte>  $matches
     */
    private function ambiguous(string $analyteNameRaw, string $normalized, Collection $matches): LaboratoryAnalyteResolutionResult
    {
        return new LaboratoryAnalyteResolutionResult(
            analyte: null,
            status: LaboratoryAnalyteResolutionStatus::Ambiguous,
            normalizedName: $normalized,
            identityKey: null,
            analyteNameRaw: $analyteNameRaw,
        );
    }
}
