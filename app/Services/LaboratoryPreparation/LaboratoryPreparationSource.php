<?php

namespace App\Services\LaboratoryPreparation;

use App\Models\LaboratoryPurchase;
use App\Models\LaboratoryPurchaseItem;
use Carbon\CarbonInterface;

class LaboratoryPreparationSource
{
    /**
     * @return array{items: list<array{id: int, name: string, gda_id: string|null, indications: string|null, feature_list: list<string>}>}
     */
    public function buildInput(LaboratoryPurchase $purchase): array
    {
        $purchase->loadMissing('laboratoryPurchaseItems');

        return [
            'items' => $purchase->laboratoryPurchaseItems
                ->sortBy('id')
                ->values()
                ->map(fn (LaboratoryPurchaseItem $item) => [
                    'id' => (int) $item->id,
                    'name' => (string) $item->name,
                    'gda_id' => filled($item->gda_id) ? (string) $item->gda_id : null,
                    'indications' => filled($item->indications) ? (string) $item->indications : null,
                    'feature_list' => $this->normalizeFeatureList($item->feature_list),
                ])
                ->all(),
        ];
    }

    /**
     * @return array{
     *     items: list<array{id: int, name: string, gda_id: string|null, indications: string|null, feature_list: list<string>}>,
     *     patient_context: array{age_years: int|null, age_source: string},
     *     rules_version: string
     * }
     */
    public function buildDeterministicInput(LaboratoryPurchase $purchase): array
    {
        return [
            ...$this->buildInput($purchase),
            'patient_context' => $this->patientContext($purchase)->toHashPayload(),
            'rules_version' => LaboratoryPreparationRuleEngine::RULES_VERSION,
        ];
    }

    public function deterministicHash(LaboratoryPurchase $purchase): string
    {
        return $this->hash($this->buildDeterministicInput($purchase));
    }

    public function patientContext(LaboratoryPurchase $purchase): LaboratoryPreparationPatientContext
    {
        $birthDate = $purchase->birth_date;
        $ageYears = $birthDate instanceof CarbonInterface
            ? (int) $birthDate->diffInYears(now())
            : null;

        return new LaboratoryPreparationPatientContext(
            ageYears: $ageYears,
            ageSource: $ageYears === null ? 'purchase_missing_birth_date' : 'purchase_birth_date',
        );
    }

    /**
     * @param  array<string, mixed>  $input
     */
    public function hash(array $input): string
    {
        return hash('sha256', json_encode($input, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    }

    /**
     * @return list<string>
     */
    public function normalizeFeatureList(mixed $featureList): array
    {
        if (is_string($featureList)) {
            $decoded = json_decode($featureList, true);
            $featureList = is_array($decoded) ? $decoded : [];
        }

        if (! is_array($featureList)) {
            return [];
        }

        return collect($featureList)
            ->map(function ($entry) {
                if (is_array($entry)) {
                    return trim((string) ($entry['name'] ?? $entry['label'] ?? ''));
                }

                return trim((string) $entry);
            })
            ->filter()
            ->values()
            ->all();
    }
}
