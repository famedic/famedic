<?php
declare(strict_types=1);

putenv('LAB_RESULTS_VISION_EXTRACTION_ENABLED=true');
putenv('LAB_RESULTS_VISION_SHADOW_MODE=true');
$_ENV['LAB_RESULTS_VISION_EXTRACTION_ENABLED'] = 'true';
$_ENV['LAB_RESULTS_VISION_SHADOW_MODE'] = 'true';

$base = getenv('LAB_DIAG_BASE') ?: '/var/www/html';
require $base . '/vendor/autoload.php';
$app = require_once $base . '/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use App\Enums\LaboratoryResultExtractionComparisonOutcome;
use App\Enums\LaboratoryResultObservationValueType;
use App\Models\AiExecution;
use App\Models\LaboratoryResultExtractionQaMetric;
use App\Services\LaboratoryResults\Extraction\LaboratoryResultExtractionComparisonItem;
use App\Services\LaboratoryResults\Extraction\LaboratoryResultHybridExtractionQaService;
use App\Services\LaboratoryResults\Extraction\LaboratoryResultObservationCandidate;

function serializeCandidate(?LaboratoryResultObservationCandidate $c): ?array
{
    if ($c === null) {
        return null;
    }

    return [
        'analyte_name_raw' => $c->analyteNameRaw,
        'value_type' => $c->valueType->value,
        'numeric_value' => $c->numericValue,
        'text_value' => $c->textValue,
        'unit' => $c->unit,
        'unit_raw' => $c->unitRaw,
        'reference_low' => $c->referenceLow,
        'reference_high' => $c->referenceHigh,
        'reference_text' => $c->referenceText,
        'source_page' => $c->sourcePage,
        'confidence' => $c->confidence,
        'parse_rule' => $c->parseRule,
    ];
}

function serializeComparisonItem(LaboratoryResultExtractionComparisonItem $item): array
{
    return [
        'outcome' => $item->outcome->value,
        'analyte_key' => $item->analyteKey,
        'conflict_fields' => $item->conflictFields,
        'text_candidate' => serializeCandidate($item->textCandidate),
        'vision_candidate' => serializeCandidate($item->visionCandidate),
    ];
}

function classifyConflictCause(LaboratoryResultExtractionComparisonItem $item): string
{
    if ($item->outcome !== LaboratoryResultExtractionComparisonOutcome::Conflict) {
        return 'UNKNOWN';
    }

    $fields = $item->conflictFields;
    $text = $item->textCandidate;
    $vision = $item->visionCandidate;

    if ($text === null || $vision === null) {
        return 'COMPARATOR';
    }

    $referenceFields = ['reference_low', 'reference_high', 'reference_text'];
    $hasReferenceConflict = (bool) array_intersect($fields, $referenceFields);

    if (in_array('value_type', $fields, true)) {
        if ($text->valueType === LaboratoryResultObservationValueType::Numeric
            && $text->numericValue === null
            && $text->textValue !== null) {
            return 'TEXT_PARSER';
        }
        if ($vision->valueType === LaboratoryResultObservationValueType::Numeric
            && $vision->numericValue === null
            && $vision->textValue !== null) {
            return 'VISION_PARSER';
        }

        return 'COMPARATOR';
    }

    if (in_array('numeric_value', $fields, true)) {
        if ($text->numericValue === null && $vision->numericValue !== null) {
            return 'TEXT_PARSER';
        }
        if ($vision->numericValue === null && $text->numericValue !== null) {
            return 'VISION_PARSER';
        }
        if ($text->parseRule !== null && $vision->parseRule === null) {
            return 'TEXT_PARSER';
        }
        if ($vision->parseRule !== null && $text->parseRule === null) {
            return 'VISION_PARSER';
        }

        return 'VISION_PARSER';
    }

    if (in_array('text_value', $fields, true)) {
        return $text->textValue === null ? 'TEXT_EXTRACTION' : 'TEXT_PARSER';
    }

    if (in_array('unit', $fields, true)) {
        if ($text->unit === null && $vision->unit !== null) {
            return 'TEXT_EXTRACTION';
        }
        if ($vision->unit === null && $text->unit !== null) {
            return 'VISION_EXTRACTION';
        }
        if (($text->unitRaw ?? null) !== ($text->unit ?? null)) {
            return 'TEXT_PARSER';
        }
        if (($vision->unitRaw ?? null) !== ($vision->unit ?? null)) {
            return 'VISION_PARSER';
        }

        return 'TEXT_RESOLUTION';
    }

    if ($hasReferenceConflict) {
        $textMissing = $text->referenceLow === null
            && $text->referenceHigh === null
            && ($text->referenceText === null || trim($text->referenceText) === '');
        $visionMissing = $vision->referenceLow === null
            && $vision->referenceHigh === null
            && ($vision->referenceText === null || trim($vision->referenceText) === '');

        if ($textMissing && ! $visionMissing) {
            return 'TEXT_PARSER';
        }
        if ($visionMissing && ! $textMissing) {
            return 'VISION_PARSER';
        }

        return 'TEXT_PARSER';
    }

    return 'UNKNOWN';
}

function conflictDebugValues(LaboratoryResultExtractionComparisonItem $item): array
{
    $text = $item->textCandidate;
    $vision = $item->visionCandidate;

    return [
        'text_numeric_value' => $text?->numericValue,
        'vision_numeric_value' => $vision?->numericValue,
        'text_unit' => $text?->unit,
        'vision_unit' => $vision?->unit,
        'text_reference_low' => $text?->referenceLow,
        'text_reference_high' => $text?->referenceHigh,
        'vision_reference_low' => $vision?->referenceLow,
        'vision_reference_high' => $vision?->referenceHigh,
        'text_reference_text' => $text?->referenceText,
        'vision_reference_text' => $vision?->referenceText,
    ];
}

/** @var LaboratoryResultHybridExtractionQaService $qa */
$qa = app(LaboratoryResultHybridExtractionQaService::class);

$output = [
    'generated_at' => now()->toIso8601String(),
    'environment' => app()->environment(),
    'vision_enabled' => (bool) config('laboratory-results.vision_extraction.enabled', false),
    'shadow_mode' => (bool) config('laboratory-results.vision_extraction.shadow_mode', true),
    'versions' => [],
    'openai_errors' => [],
    'first_batch_metrics_summary' => [],
    'first_batch_aggregate_keys' => [
        'TEXT_ONLY' => [],
        'VISION_ONLY' => [],
    ],
    'errors' => [],
];

foreach ([33, 34] as $versionId) {
    $versionPayload = [
        'version_id' => $versionId,
        'compare' => null,
        'text_candidates' => [],
        'vision_candidates' => [],
        'comparison_items' => [],
        'conflicts' => [],
    ];

    try {
        $result = $qa->compareVersion($versionId, true);
        $versionPayload['compare'] = $result->toCommandOutput();

        foreach ($result->comparisonItems as $item) {
            $serialized = serializeComparisonItem($item);
            $versionPayload['comparison_items'][] = $serialized;

            if ($item->textCandidate !== null) {
                $versionPayload['text_candidates'][] = serializeCandidate($item->textCandidate);
            }
            if ($item->visionCandidate !== null) {
                $versionPayload['vision_candidates'][] = serializeCandidate($item->visionCandidate);
            }

            if ($item->outcome === LaboratoryResultExtractionComparisonOutcome::Conflict) {
                $versionPayload['conflicts'][] = array_merge(
                    [
                        'analyte_key' => $item->analyteKey,
                        'conflict_fields' => $item->conflictFields,
                        'cause' => classifyConflictCause($item),
                    ],
                    conflictDebugValues($item),
                );
            }
        }

        if ($result->aiExecutionId !== null) {
            $exec = AiExecution::query()->find($result->aiExecutionId);
            if ($exec !== null && $exec->status === AiExecution::STATUS_FAILED) {
                $output['openai_errors'][] = [
                    'version_id' => $versionId,
                    'ai_execution_id' => $exec->id,
                    'error' => $exec->error,
                    'model' => $exec->model,
                ];
            }
        }
    } catch (Throwable $e) {
        $versionPayload['error'] = $e->getMessage();
        $output['errors'][] = ['version_id' => $versionId, 'message' => $e->getMessage()];
    }

    $output['versions'][] = $versionPayload;
}

$firstBatchRows = [];
for ($vid = 1; $vid <= 37; $vid++) {
    $metric = LaboratoryResultExtractionQaMetric::query()
        ->where('laboratory_result_version_id', $vid)
        ->orderBy('id')
        ->first();

    if ($metric === null) {
        continue;
    }

    $firstBatchRows[] = [
        'metric_id' => $metric->id,
        'version_id' => $metric->laboratory_result_version_id,
        'comparison_outcome' => $metric->comparison_outcome,
        'text_obs' => $metric->text_observation_count,
        'vision_obs' => $metric->vision_observation_count,
        'match' => $metric->match_count,
        'conflict' => $metric->conflict_count,
        'text_only' => $metric->text_only_count,
        'vision_only' => $metric->vision_only_count,
        'vision_status' => $metric->vision_extraction_status,
        'document_category' => is_array($metric->summary) ? ($metric->summary['document_category'] ?? null) : null,
        'ai_execution_id' => $metric->ai_execution_id,
    ];

    try {
        $replay = $qa->compareVersion($vid, true);
        foreach ($replay->comparisonItems as $item) {
            if ($item->outcome === LaboratoryResultExtractionComparisonOutcome::TextOnly && $item->analyteKey !== null) {
                $output['first_batch_aggregate_keys']['TEXT_ONLY'][$item->analyteKey] = ($output['first_batch_aggregate_keys']['TEXT_ONLY'][$item->analyteKey] ?? 0) + 1;
            }
            if ($item->outcome === LaboratoryResultExtractionComparisonOutcome::VisionOnly && $item->analyteKey !== null) {
                $output['first_batch_aggregate_keys']['VISION_ONLY'][$item->analyteKey] = ($output['first_batch_aggregate_keys']['VISION_ONLY'][$item->analyteKey] ?? 0) + 1;
            }
        }
    } catch (Throwable $e) {
        // skip replay errors for batch aggregate
    }
}

$output['first_batch_metrics_summary'] = $firstBatchRows;

arsort($output['first_batch_aggregate_keys']['TEXT_ONLY']);
arsort($output['first_batch_aggregate_keys']['VISION_ONLY']);

$jsonPath = 'lab_diag_v33_v34.json';
file_put_contents($jsonPath, json_encode($output, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
fwrite(STDOUT, $jsonPath . "\n");