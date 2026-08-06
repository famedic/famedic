<?php

namespace App\Services\ClinicalMatching;

use App\Services\ClinicalMatching\Catalog\CatalogAdapterInterface;
use App\Services\ClinicalMatching\Catalog\CatalogItem;

/**
 * Orchestrates matching. Depends only on CatalogAdapterInterface — never Eloquent.
 */
class ClinicalMatchingEngine
{
    public function __construct(
        private CatalogAdapterInterface $catalog,
        private CatalogMatcher $matcher,
        private MockInterpretationFactory $interpretations,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function build(?array $interpretation = null): array
    {
        $interpretation ??= $this->interpretations->sample();
        $interpretation['ai_json'] = $this->toAiJson($interpretation);

        $medicationMatches = $this->matchItems(
            $interpretation['medications'] ?? [],
            'medication'
        );

        $studyMatches = $this->matchItems(
            $interpretation['studies'] ?? [],
            'laboratory'
        );

        $allMatches = array_merge($medicationMatches, $studyMatches);

        return [
            'meta' => [
                'product' => 'AI Clinical Interpreter',
                'module' => 'Clinical Matching Engine',
                'version' => '0.2.0-lab-catalog',
                'note' => 'Estudios: catálogo real LaboratoryTest vía Adapter. Farmacia/OpenAI/OCR pendientes.',
                'catalog_source' => 'laboratory_tests',
                'pipeline' => [
                    'normalize',
                    'expand_abbreviations',
                    'synonyms',
                    'compare',
                    'rank',
                    'confidence',
                ],
            ],
            'document' => $interpretation['document'],
            'interpretation' => $interpretation,
            'matches' => [
                'medications' => $medicationMatches,
                'studies' => $studyMatches,
            ],
            'summary' => $this->buildSummary($allMatches),
            'ai_config' => $this->defaultAiConfig(),
            'future_actions' => [
                ['id' => 'add_to_cart', 'label' => 'Agregar al carrito', 'enabled' => false],
                ['id' => 'create_quote', 'label' => 'Crear cotización', 'enabled' => false],
                ['id' => 'create_order', 'label' => 'Crear pedido', 'enabled' => false],
                ['id' => 'save_interpretation', 'label' => 'Guardar interpretación', 'enabled' => false],
                ['id' => 'patient_timeline', 'label' => 'Timeline del paciente', 'enabled' => false],
            ],
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function searchCatalog(string $query, string $type = 'all'): array
    {
        $variants = $this->matcher->buildVariants($query);
        $candidates = $this->catalog->searchCandidates($variants, $type, 40);
        $pool = array_map(fn (CatalogItem $item) => $item->toArray(), $candidates);
        $ranked = $this->matcher->rank($query, $pool, 8);

        return array_map(function (array $row) {
            $suggestion = $this->suggestionFromRank($row);
            $suggestion['id'] = $suggestion['catalog_id'];
            $suggestion['match_reason'] = $suggestion['reason'];

            return $suggestion;
        }, $ranked);
    }

    /**
     * @param  list<array<string, mixed>>  $detectedItems
     * @return list<array<string, mixed>>
     */
    private function matchItems(array $detectedItems, string $type): array
    {
        $results = [];

        foreach ($detectedItems as $detected) {
            $variants = $this->matcher->buildVariants($detected['detected_name']);
            $candidates = $this->catalog->searchCandidates($variants, $type, 40);
            $pool = array_map(fn (CatalogItem $item) => $item->toArray(), $candidates);
            $ranked = $this->matcher->rank($detected['detected_name'], $pool, 8);

            $suggestions = array_map(
                fn (array $row) => $this->suggestionFromRank($row),
                $ranked
            );

            $top = $suggestions[0] ?? null;
            $score = ($ranked[0]['score'] ?? 0.0);
            $status = $top ? $this->matcher->statusFromScore($score) : 'not_found';

            $highConfidence = array_values(array_filter(
                $suggestions,
                fn (array $s) => ($s['similarity'] ?? 0) >= 92
            ));

            // Multiple strong hits → operator must choose
            $ambiguous = count($highConfidence) > 1;

            if ($status === 'not_found' && $top && ($top['similarity'] ?? 0) >= 45) {
                $status = 'partial';
            }

            if ($ambiguous) {
                $status = 'partial';
            }

            $engineStatus = match ($status) {
                'exact' => 'exact',
                'partial' => 'partial',
                default => 'not_found',
            };

            $uiState = match ($engineStatus) {
                'exact' => ($top['available'] ?? false) ? 'match_found' : 'needs_validation',
                'partial' => 'needs_validation',
                default => 'not_found',
            };

            $autoSelect = $engineStatus === 'exact' && ! $ambiguous;

            $results[] = [
                'detection_id' => $detected['id'],
                'type' => $type,
                'detected_name' => $detected['detected_name'],
                'detection_confidence' => $detected['confidence'] ?? null,
                'detection_meta' => $detected,
                'engine_status' => $engineStatus,
                'ui_state' => $uiState,
                'user_decision' => null,
                'selected_catalog_id' => $autoSelect ? ($top['catalog_id'] ?? null) : null,
                'match' => $top,
                'perhaps' => (! $autoSelect && $top && $engineStatus !== 'exact') ? $top : null,
                'alternatives' => $suggestions,
                'pipeline' => [
                    'normalized' => true,
                    'abbreviations_expanded' => true,
                    'synonyms_applied' => true,
                    'candidates' => count($candidates),
                    'ranked' => count($suggestions),
                ],
            ];
        }

        return $results;
    }

    /**
     * @param  array{item: array<string, mixed>, score: float, reason: string}  $row
     * @return array<string, mixed>
     */
    private function suggestionFromRank(array $row): array
    {
        $item = $row['item'];

        return [
            'catalog_id' => $item['id'],
            'name' => $item['name'],
            'short_name' => $item['short_name'] ?? null,
            'sku' => $item['code'] ?? $item['sku'] ?? null,
            'code' => $item['code'] ?? $item['sku'] ?? null,
            'price' => $item['price'] ?? null,
            'price_cents' => $item['price_cents'] ?? null,
            'delivery_time' => $item['delivery_time'] ?? null,
            'laboratory' => $item['laboratory'] ?? $item['brand'] ?? null,
            'available' => $item['available'] ?? true,
            'brand' => $item['brand'] ?? null,
            'similarity' => (int) round($row['score'] * 100),
            'reason' => $row['reason'],
            'match_status' => $this->matcher->statusFromScore($row['score']),
        ];
    }

    /**
     * @param  list<array<string, mixed>>  $matches
     * @return array<string, mixed>
     */
    private function buildSummary(array $matches): array
    {
        $meds = array_values(array_filter($matches, fn ($m) => $m['type'] === 'medication'));
        $labs = array_values(array_filter($matches, fn ($m) => $m['type'] === 'laboratory'));

        $countBy = function (array $items, string $status): int {
            return count(array_filter($items, fn ($m) => $m['engine_status'] === $status));
        };

        $pending = count(array_filter(
            $matches,
            fn ($m) => in_array($m['ui_state'], ['needs_validation', 'not_found'], true)
        ));

        $studiesTotal = count($labs);
        $studiesFound = $countBy($labs, 'exact');
        $successRate = $studiesTotal > 0
            ? (int) round(($studiesFound / $studiesTotal) * 100)
            : 0;

        return [
            'medications_found' => $countBy($meds, 'exact'),
            'medications_partial' => $countBy($meds, 'partial'),
            'medications_not_found' => $countBy($meds, 'not_found'),
            'studies_found' => $studiesFound,
            'studies_similar' => $countBy($labs, 'partial'),
            'studies_not_found' => $countBy($labs, 'not_found'),
            'studies_total' => $studiesTotal,
            'success_rate' => $successRate,
            'pending_validation' => $pending,
            'total_detections' => count($matches),
            'exact_total' => $countBy($matches, 'exact'),
            'partial_total' => $countBy($matches, 'partial'),
            'not_found_total' => $countBy($matches, 'not_found'),
        ];
    }

    /**
     * @param  array<string, mixed>  $interpretation
     * @return array<string, mixed>
     */
    private function toAiJson(array $interpretation): array
    {
        return [
            'patient' => $interpretation['patient']['name'] ?? null,
            'physician' => $interpretation['physician']['name'] ?? null,
            'date' => $interpretation['date']['value'] ?? null,
            'diagnosis' => $interpretation['diagnosis']['value'] ?? null,
            'medications' => array_map(fn ($m) => [
                'name' => $m['detected_name'],
                'dose' => $m['dose'] ?? null,
                'frequency' => $m['frequency'] ?? null,
                'duration' => $m['duration'] ?? null,
            ], $interpretation['medications'] ?? []),
            'studies' => array_map(
                fn ($s) => $s['detected_name'],
                $interpretation['studies'] ?? []
            ),
            'indications' => $interpretation['indications']['value'] ?? null,
            'observations' => $interpretation['observations']['value'] ?? null,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function defaultAiConfig(): array
    {
        return [
            'model' => 'gpt-4o (placeholder)',
            'temperature' => 0.2,
            'system_prompt' => "Eres un intérprete clínico de Famedic. Extrae de la receta: paciente, médico, fecha, diagnóstico, medicamentos, estudios, indicaciones y observaciones. Responde solo en JSON estructurado. No inventes datos.",
            'user_prompt' => "Interpreta la siguiente receta médica y devuelve JSON con las claves: patient, physician, date, diagnosis, medications[], studies[], indications, observations.\n\n{{ocr_text}}",
            'last_saved_at' => null,
            'editable' => true,
        ];
    }
}
