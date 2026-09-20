<?php

namespace App\Services\LaboratoryPreparation;

use App\Models\AiExecution;
use App\Models\LaboratoryPurchase;
use App\Models\LaboratoryPurchaseItem;
use App\Models\LaboratoryPurchasePreparationSummary;
use Illuminate\Support\Facades\Schema;

class LaboratoryPreparationPresenter
{
    public const STATUS_AI_READY = 'AI_READY';

    public const STATUS_AI_PENDING = 'AI_PENDING';

    public const STATUS_AI_FAILED = 'AI_FAILED';

    public const STATUS_FALLBACK = 'FALLBACK';

    public function __construct(private readonly LaboratoryPreparationSource $source) {}

    /**
     * @return array{
     *     has_ai_summary: bool,
     *     ai_status: 'AI_READY'|'AI_PENDING'|'AI_FAILED'|'FALLBACK',
     *     source: 'ai'|'fallback',
     *     source_hash: string,
     *     generated_at: string|null,
     *     prompt_version: int|null,
     *     summary: array{text: string|null, sections: list<array<string, mixed>>, special_instructions: list<array<string, mixed>>, individual_instructions: list<array<string, mixed>>},
     *     individual_instructions: list<array{id: int, study_name: string, name: string, indications: string|null, instructions: string|null, feature_list: list<string>}>,
     *     studies: list<array{id: int, name: string, instructions: string, indications: string|null, feature_list: list<string>}>,
     * }
     */
    public function present(LaboratoryPurchase $purchase): array
    {
        $purchase->loadMissing([
            'laboratoryPurchaseItems',
        ]);

        if (Schema::hasTable('laboratory_purchase_preparation_summaries')) {
            $purchase->loadMissing('preparationSummary.aiExecution');
        }

        $input = $this->source->buildInput($purchase);
        $sourceHash = $this->source->hash($input);
        $individualInstructions = $this->individualInstructions($purchase);
        $legacyStudies = $this->legacyStudies($individualInstructions);
        $summary = $this->validSummary(
            $purchase->relationLoaded('preparationSummary') ? $purchase->preparationSummary : null,
            $sourceHash,
        );

        if ($summary !== null) {
            $summaryJson = $summary->summary_json;

            return [
                'has_ai_summary' => true,
                'ai_status' => self::STATUS_AI_READY,
                'source' => 'ai',
                'source_hash' => $sourceHash,
                'generated_at' => $summary->generated_at?->toIso8601String(),
                'prompt_version' => $summary->aiExecution?->prompt_version,
                'summary' => [
                    'text' => $summary->summary_text,
                    'sections' => array_values($summaryJson['sections'] ?? []),
                    'special_instructions' => array_values($summaryJson['special_instructions'] ?? []),
                    'individual_instructions' => array_values($summaryJson['individual_instructions'] ?? []),
                ],
                'individual_instructions' => $individualInstructions,
                'studies' => $legacyStudies,
            ];
        }

        $execution = $this->latestExecutionForHash($purchase, $sourceHash);
        $aiStatus = match ($execution?->status) {
            AiExecution::STATUS_QUEUED, AiExecution::STATUS_PROCESSING => self::STATUS_AI_PENDING,
            AiExecution::STATUS_FAILED => self::STATUS_AI_FAILED,
            default => self::STATUS_FALLBACK,
        };

        return [
            'has_ai_summary' => false,
            'ai_status' => $aiStatus,
            'source' => 'fallback',
            'source_hash' => $sourceHash,
            'generated_at' => null,
            'prompt_version' => null,
            'summary' => [
                'text' => null,
                'sections' => [],
                'special_instructions' => [],
                'individual_instructions' => [],
            ],
            'individual_instructions' => $individualInstructions,
            'studies' => $legacyStudies,
        ];
    }

    /**
     * @return list<array{id: int, study_name: string, name: string, indications: string|null, instructions: string|null, feature_list: list<string>}>
     */
    private function individualInstructions(LaboratoryPurchase $purchase): array
    {
        return $purchase->laboratoryPurchaseItems
            ->sortBy('id')
            ->values()
            ->map(fn (LaboratoryPurchaseItem $item) => [
                'id' => (int) $item->id,
                'study_name' => (string) $item->name,
                'name' => (string) $item->name,
                'indications' => filled($item->indications) ? (string) $item->indications : null,
                'instructions' => filled($item->indications) ? (string) $item->indications : null,
                'feature_list' => $this->source->normalizeFeatureList($item->feature_list),
            ])
            ->all();
    }

    /**
     * @param  list<array{id: int, study_name: string, name: string, indications: string|null, instructions: string|null, feature_list: list<string>}>  $individualInstructions
     * @return list<array{id: int, name: string, instructions: string, indications: string|null, feature_list: list<string>}>
     */
    private function legacyStudies(array $individualInstructions): array
    {
        return collect($individualInstructions)
            ->map(fn (array $instruction) => [
                'id' => $instruction['id'],
                'name' => $instruction['name'],
                'instructions' => filled($instruction['instructions']) ? $instruction['instructions'] : '—',
                'indications' => $instruction['indications'],
                'feature_list' => $instruction['feature_list'],
            ])
            ->values()
            ->all();
    }

    private function validSummary(?LaboratoryPurchasePreparationSummary $summary, string $sourceHash): ?LaboratoryPurchasePreparationSummary
    {
        if ($summary === null) {
            return null;
        }

        if ($summary->status !== LaboratoryPurchasePreparationSummary::STATUS_GENERATED) {
            return null;
        }

        if ($summary->invalidated_at !== null || $summary->source_hash !== $sourceHash) {
            return null;
        }

        if ($summary->aiExecution?->status === AiExecution::STATUS_FAILED) {
            return null;
        }

        if (! filled($summary->summary_text) || ! is_array($summary->summary_json)) {
            return null;
        }

        foreach (['summary', 'sections', 'special_instructions', 'individual_instructions'] as $key) {
            if (! array_key_exists($key, $summary->summary_json)) {
                return null;
            }
        }

        return $summary;
    }

    private function latestExecutionForHash(LaboratoryPurchase $purchase, string $sourceHash): ?AiExecution
    {
        if (! Schema::hasTable('ai_executions')) {
            return null;
        }

        return AiExecution::query()
            ->where('domain', 'laboratory')
            ->where('feature', 'laboratory_preparation_summary')
            ->where('subject_type', $purchase->getMorphClass())
            ->where('subject_id', $purchase->id)
            ->where('input_hash', $sourceHash)
            ->latest('id')
            ->first();
    }
}
