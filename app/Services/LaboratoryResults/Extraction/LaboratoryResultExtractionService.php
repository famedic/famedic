<?php

namespace App\Services\LaboratoryResults\Extraction;

use App\Enums\LaboratoryResultAbnormalSource;
use App\Enums\LaboratoryResultEventType;
use App\Enums\LaboratoryResultExtractionMethod;
use App\Enums\LaboratoryResultExtractionStatus;
use App\Enums\LaboratoryResultObservationValueType;
use App\Enums\LaboratoryResultReportSource;
use App\Enums\LaboratoryResultStructuredStatus;
use App\Models\AiPrompt;
use App\Models\LaboratoryResultEvent;
use App\Models\LaboratoryResultObservation;
use App\Models\LaboratoryResultReport;
use App\Models\LaboratoryResultVersion;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

class LaboratoryResultExtractionService
{
    public function __construct(
        private LaboratoryResultTextExtractor $textExtractor,
        private LaboratoryResultTextMetricsCalculator $metricsCalculator,
        private LaboratoryResultTextParser $textParser,
        private LaboratoryAnalyteResolver $analyteResolver,
        private LaboratoryResultExtractionValidator $validator,
        private LaboratoryResultReferenceEvaluator $referenceEvaluator,
        private LaboratoryResultReportPublisher $publisher,
        private LaboratoryResultVisionExtractor $visionExtractor,
        private LaboratoryResultVisionFallbackEvaluator $visionFallbackEvaluator,
        private LaboratoryResultExtractionComparator $extractionComparator,
        private LaboratoryResultExtractionQaRecorder $qaRecorder,
    ) {}

    public function extractForVersion(int $laboratoryResultVersionId): ?LaboratoryResultReport
    {
        $started = microtime(true);

        $version = LaboratoryResultVersion::query()
            ->with('resultStatus')
            ->find($laboratoryResultVersionId);

        if (! $version) {
            Log::warning('laboratory_result_extraction_skipped_missing_version', [
                'result_version_id' => $laboratoryResultVersionId,
            ]);

            return null;
        }

        $purchaseId = $version->resultStatus?->laboratory_purchase_id;

        if ($purchaseId === null) {
            return null;
        }

        $inputHash = LaboratoryResultInputHash::compute($version->sha256);
        $extractorVersion = (string) config(
            'laboratory-results.structured_extraction.extractor_version',
            LaboratoryResultInputHash::DEFAULT_EXTRACTOR_VERSION
        );

        $existing = LaboratoryResultReport::query()
            ->where('laboratory_result_version_id', $version->id)
            ->where('input_hash', $inputHash)
            ->first();

        if ($existing) {
            Log::info('laboratory_result_extraction_idempotent_skip', [
                'purchase_id' => $purchaseId,
                'result_version_id' => $version->id,
                'report_id' => $existing->id,
                'extractor_version' => $extractorVersion,
            ]);

            if ($this->visionEnabled() && Storage::exists($version->storage_path)) {
                $this->runVisionShadowPipeline(
                    version: $version,
                    purchaseId: $purchaseId,
                    textReport: $existing,
                    pdfBinary: Storage::get($version->storage_path),
                    started: $started,
                );
            }

            return $existing;
        }

        $this->recordEvent($version, LaboratoryResultEventType::ExtractionRequested, [
            'version_id' => $version->id,
            'extractor_version' => $extractorVersion,
        ]);

        if (! Storage::exists($version->storage_path)) {
            return $this->finalizeFailure(
                $version,
                $purchaseId,
                $inputHash,
                $extractorVersion,
                'pdf_not_found',
                $started,
            );
        }

        $pdfBinary = Storage::get($version->storage_path);
        $extraction = $this->textExtractor->extractFromBinary($pdfBinary);

        if (! $extraction->success) {
            $textReport = $this->finalizeFailure(
                $version,
                $purchaseId,
                $inputHash,
                $extractorVersion,
                $extraction->errorCode ?? 'pdf_extract_failed',
                $started,
                rawPayload: ['error' => $extraction->errorMessage],
            );

            if ($this->visionEnabled()) {
                $this->runVisionShadowPipeline(
                    version: $version,
                    purchaseId: $purchaseId,
                    textReport: $textReport,
                    pdfBinary: $pdfBinary,
                    started: $started,
                    textExtractionSucceeded: false,
                );
            }

            return $textReport;
        }

        $metrics = $this->metricsCalculator->calculate($extraction);
        $candidates = $this->textParser->parse($extraction);
        $validationSummary = $this->validateCandidates($candidates);

        if (! $metrics->hasSufficientText) {
            $textReport = $this->finalizeInsufficientText(
                $version,
                $purchaseId,
                $inputHash,
                $extractorVersion,
                $extraction,
                $metrics,
                $started,
            );

            $this->runVisionShadowPipeline(
                version: $version,
                purchaseId: $purchaseId,
                textReport: $textReport,
                pdfBinary: $pdfBinary,
                started: $started,
                extraction: $extraction,
                metrics: $metrics,
                validationSummary: $validationSummary,
                textExtractionSucceeded: true,
            );

            return $textReport;
        }

        $textReport = DB::transaction(function () use (
            $version,
            $purchaseId,
            $inputHash,
            $extractorVersion,
            $extraction,
            $metrics,
            $validationSummary,
            $started,
        ): LaboratoryResultReport {
            $report = LaboratoryResultReport::query()->create([
                'laboratory_purchase_id' => $purchaseId,
                'laboratory_result_version_id' => $version->id,
                'source' => $this->resolveSource($version->source),
                'extraction_method' => LaboratoryResultExtractionMethod::PdfText,
                'extraction_status' => LaboratoryResultExtractionStatus::Processing,
                'structured_status' => LaboratoryResultStructuredStatus::Draft,
                'observation_count' => 0,
                'input_hash' => $inputHash,
                'extractor_version' => $extractorVersion,
                'prompt_version' => null,
                'raw_extraction_payload' => [
                    'metrics' => [
                        'page_count' => $metrics->pageCount,
                        'total_characters' => $metrics->totalCharacters,
                        'characters_per_page' => $metrics->charactersPerPage,
                        'text_density' => $metrics->textDensity,
                        'approximate_line_count' => $metrics->approximateLineCount,
                    ],
                    'candidate_count' => count($validationSummary['candidates']),
                ],
            ]);

            $persisted = 0;

            foreach ($validationSummary['publishable'] as $item) {
                LaboratoryResultObservation::query()->create($this->observationAttributes($report->id, $item));
                $persisted++;
            }

            // MVP: publicar si hay al menos una observation válida; partial si hubo rechazos.
            $hasPublishable = $persisted > 0;
            $hasRejected = $validationSummary['rejected_count'] > 0;

            $extractionStatus = match (true) {
                $hasPublishable && ! $hasRejected => LaboratoryResultExtractionStatus::Extracted,
                $hasPublishable && $hasRejected => LaboratoryResultExtractionStatus::Partial,
                default => LaboratoryResultExtractionStatus::ManualReview,
            };

            $structuredStatus = $hasPublishable
                ? LaboratoryResultStructuredStatus::Validated
                : LaboratoryResultStructuredStatus::Draft;

            $report->update([
                'extraction_status' => $extractionStatus,
                'structured_status' => $structuredStatus,
                'observation_count' => $persisted,
                'confidence_overall' => $validationSummary['confidence_overall'],
                'validation_errors' => $validationSummary['validation_errors'],
            ]);

            if ($hasPublishable) {
                if ($this->structuredPublicationEnabled()) {
                    $report = $this->publisher->publish($report->fresh(['observations']));
                }

                $this->recordEvent($version, LaboratoryResultEventType::ExtractionSucceeded, [
                    'report_id' => $report->id,
                    'version_id' => $version->id,
                    'extractor_version' => $extractorVersion,
                    'observation_count' => $persisted,
                    'duration_ms' => (int) round((microtime(true) - $started) * 1000),
                ]);
            } elseif ($hasRejected) {
                $this->recordEvent($version, LaboratoryResultEventType::ExtractionPartial, [
                    'report_id' => $report->id,
                    'version_id' => $version->id,
                    'extractor_version' => $extractorVersion,
                    'observation_count' => 0,
                    'duration_ms' => (int) round((microtime(true) - $started) * 1000),
                ]);
            } else {
                $this->recordEvent($version, LaboratoryResultEventType::ExtractionFailed, [
                    'report_id' => $report->id,
                    'version_id' => $version->id,
                    'extractor_version' => $extractorVersion,
                    'error_code' => 'no_publishable_observations',
                    'duration_ms' => (int) round((microtime(true) - $started) * 1000),
                ]);
            }

            Log::info('laboratory_result_extraction_completed', [
                'purchase_id' => $purchaseId,
                'result_version_id' => $version->id,
                'report_id' => $report->id,
                'extractor_version' => $extractorVersion,
                'status' => $report->extraction_status->value,
                'observation_count' => $persisted,
                'duration_ms' => (int) round((microtime(true) - $started) * 1000),
            ]);

            return $report->fresh(['observations']);
        });

        $this->runVisionShadowPipeline(
            version: $version,
            purchaseId: $purchaseId,
            textReport: $textReport,
            pdfBinary: $pdfBinary,
            started: $started,
            extraction: $extraction,
            metrics: $metrics,
            validationSummary: $validationSummary,
            textExtractionSucceeded: true,
        );

        return $textReport;
    }

    private function structuredPublicationEnabled(): bool
    {
        return (bool) config('laboratory-results.structured_publication.enabled', false);
    }

    private function visionEnabled(): bool
    {
        return (bool) config('laboratory-results.vision_extraction.enabled', false);
    }

    private function visionShadowMode(): bool
    {
        return (bool) config('laboratory-results.vision_extraction.shadow_mode', true);
    }

    /**
     * @param  array{
     *     candidates: list<LaboratoryResultObservationCandidate>,
     *     publishable: list<array<string, mixed>>,
     *     rejected_count: int,
     *     validation_errors: list<array<string, mixed>>,
     *     confidence_overall: ?float
     * }|null  $validationSummary
     */
    private function runVisionShadowPipeline(
        LaboratoryResultVersion $version,
        int $purchaseId,
        LaboratoryResultReport $textReport,
        string $pdfBinary,
        float $started,
        ?LaboratoryResultTextExtractionResult $extraction = null,
        ?LaboratoryResultTextMetrics $metrics = null,
        ?array $validationSummary = null,
        bool $textExtractionSucceeded = true,
    ): void {
        if (! $this->visionEnabled()) {
            return;
        }

        $extraction ??= $this->textExtractor->extractFromBinary($pdfBinary);

        if (! $extraction->success) {
            return;
        }

        $metrics ??= $this->metricsCalculator->calculate($extraction);
        $validationSummary ??= $this->validateCandidates($this->textParser->parse($extraction));

        if (! $this->visionFallbackEvaluator->shouldFallback($metrics, $validationSummary, $textExtractionSucceeded)) {
            Log::info('laboratory_result_vision_fallback_skipped', [
                'purchase_id' => $purchaseId,
                'result_version_id' => $version->id,
                'text_report_id' => $textReport->id,
            ]);

            return;
        }

        $promptVersion = AiPrompt::query()
            ->where('key', LaboratoryResultVisionExtractor::PROMPT_KEY)
            ->where('status', AiPrompt::STATUS_ACTIVE)
            ->orderByDesc('version')
            ->value('version') ?? 1;

        $visionExtractorVersion = (string) config(
            'laboratory-results.vision_extraction.extractor_version',
            LaboratoryResultInputHash::VISION_EXTRACTOR_VERSION
        );
        $visionInputHash = LaboratoryResultInputHash::compute(
            $version->sha256,
            $visionExtractorVersion,
            (int) $promptVersion,
        );

        $existingVisionReport = LaboratoryResultReport::query()
            ->where('laboratory_result_version_id', $version->id)
            ->where('input_hash', $visionInputHash)
            ->first();

        if ($existingVisionReport) {
            Log::info('laboratory_result_vision_idempotent_skip', [
                'purchase_id' => $purchaseId,
                'result_version_id' => $version->id,
                'vision_report_id' => $existingVisionReport->id,
            ]);

            return;
        }

        $visionResult = $this->visionExtractor->extractForVersion($version, $pdfBinary);

        $textCandidates = $validationSummary['publishable'] !== []
            ? array_map(
                fn (array $item): LaboratoryResultObservationCandidate => $item['candidate'],
                $validationSummary['publishable'],
            )
            : $validationSummary['candidates'];

        $comparison = $this->extractionComparator->compare($textCandidates, $visionResult->candidates);

        $visionReport = DB::transaction(function () use (
            $version,
            $purchaseId,
            $visionResult,
            $visionInputHash,
            $visionExtractorVersion,
            $promptVersion,
            $comparison,
            $metrics,
            $validationSummary,
        ): ?LaboratoryResultReport {
            if (! $visionResult->success && $visionResult->aiExecution === null) {
                return null;
            }

            $visionValidation = $this->validateCandidates($visionResult->candidates);
            $persisted = 0;

            $extractionStatus = match (true) {
                $comparison->hasConflicts() => LaboratoryResultExtractionStatus::ManualReview,
                $visionValidation['publishable'] !== [] => LaboratoryResultExtractionStatus::Extracted,
                $visionResult->success => LaboratoryResultExtractionStatus::ManualReview,
                default => LaboratoryResultExtractionStatus::Failed,
            };

            $report = LaboratoryResultReport::query()->create([
                'laboratory_purchase_id' => $purchaseId,
                'laboratory_result_version_id' => $version->id,
                'source' => $this->resolveSource($version->source),
                'extraction_method' => LaboratoryResultExtractionMethod::Vision,
                'extraction_status' => $extractionStatus,
                'structured_status' => LaboratoryResultStructuredStatus::Draft,
                'observation_count' => 0,
                'input_hash' => $visionInputHash,
                'extractor_version' => $visionExtractorVersion,
                'prompt_version' => (int) $promptVersion,
                'ai_execution_id' => $visionResult->aiExecution?->id,
                'validation_errors' => $visionResult->success ? [] : [['error_code' => $visionResult->errorCode]],
                'raw_extraction_payload' => [
                    'shadow_mode' => $this->visionShadowMode(),
                    'pages_sent' => $visionResult->pagesSent,
                    'comparison' => $comparison->metrics(),
                    'conflicts' => collect($comparison->items)
                        ->filter(fn ($item) => $item->outcome->value === 'conflict')
                        ->map(fn ($item) => [
                            'analyte_key' => $item->analyteKey,
                            'fields' => $item->conflictFields,
                        ])
                        ->values()
                        ->all(),
                    'text_metrics' => [
                        'total_characters' => $metrics->totalCharacters,
                        'text_observations' => count($validationSummary['candidates']),
                    ],
                    'vision_observations' => count($visionResult->candidates),
                    'duration_ms' => $visionResult->durationMs,
                    'tokens' => $visionResult->aiExecution?->total_tokens,
                    'estimated_cost' => $visionResult->aiExecution?->estimated_cost_usd,
                    'outcome' => $visionResult->success ? 'vision_executed' : ($visionResult->errorCode ?? 'vision_failed'),
                ],
            ]);

            foreach ($visionValidation['publishable'] as $item) {
                $attributes = $this->observationAttributes($report->id, $item);
                $attributes['extraction_method'] = LaboratoryResultExtractionMethod::Vision;
                LaboratoryResultObservation::query()->create($attributes);
                $persisted++;
            }

            $report->update([
                'observation_count' => $persisted,
                'confidence_overall' => $visionValidation['confidence_overall'],
                'validation_errors' => array_merge(
                    $report->validation_errors ?? [],
                    $visionValidation['validation_errors'],
                ),
            ]);

            return $report->fresh(['observations']);
        });

        $this->attachShadowComparisonToTextReport($textReport, $comparison, $visionResult, $visionReport?->id);

        if ($comparison->hasConflicts()) {
            $this->markTextReportForConflictReview($textReport, $comparison, $visionResult->aiExecution?->id);
        }

        $comparisonReport = new LaboratoryResultExtractionComparisonReport(
            comparison: $comparison,
            textObservationCount: count($textCandidates),
            visionObservationCount: count($visionResult->candidates),
            visionExecuted: true,
            shadowMode: $this->visionShadowMode(),
            visionConfidenceAvg: $this->averageCandidateConfidence($visionResult->candidates),
            visionPageCount: count($visionResult->pagesSent),
        );

        $this->qaRecorder->record(
            laboratoryResultVersionId: $version->id,
            report: $comparisonReport,
            textReportId: $textReport->id,
            visionReportId: $visionReport?->id,
            aiExecutionId: $visionResult->aiExecution?->id,
            textExtractionStatus: $textReport->extraction_status->value,
            visionExtractionStatus: $visionReport?->extraction_status->value ?? ($visionResult->success ? 'executed' : 'failed'),
            textExtractorVersion: (string) config(
                'laboratory-results.structured_extraction.extractor_version',
                LaboratoryResultInputHash::DEFAULT_EXTRACTOR_VERSION
            ),
            visionExtractorVersion: $visionResult->extractorVersion,
            promptVersion: (int) $promptVersion,
            fallbackReasons: $this->visionFallbackEvaluator->resolveFallbackReasons(
                $metrics,
                $validationSummary,
                $textExtractionSucceeded,
            ),
            visionSkippedIdempotent: $visionResult->skippedIdempotent,
            visionInputHash: $visionResult->inputHash,
        );

        Log::info('laboratory_result_vision_shadow_completed', [
            'purchase_id' => $purchaseId,
            'result_version_id' => $version->id,
            'text_report_id' => $textReport->id,
            'vision_report_id' => $visionReport?->id,
            'shadow_mode' => $this->visionShadowMode(),
            'comparison' => $comparisonReport->toSummaryArray(),
            'duration_ms' => (int) round((microtime(true) - $started) * 1000),
            'ai_execution_id' => $visionResult->aiExecution?->id,
        ]);
    }

    /**
     * @param  list<LaboratoryResultObservationCandidate>  $candidates
     */
    private function averageCandidateConfidence(array $candidates): ?float
    {
        if ($candidates === []) {
            return null;
        }

        return round(array_sum(array_map(fn ($c) => $c->confidence, $candidates)) / count($candidates), 4);
    }

    private function attachShadowComparisonToTextReport(
        LaboratoryResultReport $textReport,
        LaboratoryResultExtractionComparisonSummary $comparison,
        LaboratoryResultVisionExtractionResult $visionResult,
        ?int $visionReportId,
    ): void {
        $payload = $textReport->raw_extraction_payload ?? [];
        $payload['vision_shadow'] = [
            'enabled' => true,
            'shadow_mode' => $this->visionShadowMode(),
            'vision_report_id' => $visionReportId,
            'ai_execution_id' => $visionResult->aiExecution?->id,
            'comparison' => $comparison->metrics(),
            'pages_sent' => $visionResult->pagesSent,
            'vision_observations' => count($visionResult->candidates),
            'duration_ms' => $visionResult->durationMs,
            'tokens' => $visionResult->aiExecution?->total_tokens,
            'estimated_cost' => $visionResult->aiExecution?->estimated_cost_usd,
            'outcome' => $visionResult->success ? 'executed' : ($visionResult->errorCode ?? 'failed'),
        ];

        $textReport->update(['raw_extraction_payload' => $payload]);
    }

    private function markTextReportForConflictReview(
        LaboratoryResultReport $textReport,
        LaboratoryResultExtractionComparisonSummary $comparison,
        ?int $aiExecutionId,
    ): void {
        if ($this->visionShadowMode() && $textReport->structured_status === LaboratoryResultStructuredStatus::Published) {
            $errors = $textReport->validation_errors ?? [];
            $errors[] = [
                'error_code' => 'text_vision_conflict',
                'ai_execution_id' => $aiExecutionId,
                'conflict_count' => $comparison->conflictCount,
                'conflicts' => collect($comparison->items)
                    ->filter(fn ($item) => $item->outcome->value === 'conflict')
                    ->map(fn ($item) => [
                        'analyte_key' => $item->analyteKey,
                        'fields' => $item->conflictFields,
                    ])
                    ->values()
                    ->all(),
            ];

            $textReport->update([
                'validation_errors' => $errors,
                'raw_extraction_payload' => array_merge($textReport->raw_extraction_payload ?? [], [
                    'requires_manual_review' => true,
                    'review_reason' => 'text_vision_conflict',
                ]),
            ]);

            return;
        }

        $textReport->update([
            'extraction_status' => LaboratoryResultExtractionStatus::ManualReview,
            'validation_errors' => array_merge($textReport->validation_errors ?? [], [[
                'error_code' => 'text_vision_conflict',
                'ai_execution_id' => $aiExecutionId,
                'conflict_count' => $comparison->conflictCount,
            ]]),
        ]);
    }

    /**
     * @param  list<LaboratoryResultObservationCandidate>  $candidates
     * @return array{
     *     candidates: list<LaboratoryResultObservationCandidate>,
     *     publishable: list<array{candidate: LaboratoryResultObservationCandidate, analyte: \App\Models\LaboratoryAnalyte, reference_status: \App\Enums\LaboratoryResultReferenceStatus}>,
     *     rejected_count: int,
     *     validation_errors: list<array<string, mixed>>,
     *     confidence_overall: ?float
     * }
     */
    private function validateCandidates(array $candidates): array
    {
        $publishable = [];
        $validationErrors = [];
        $confidenceTotal = 0.0;
        $confidenceCount = 0;

        foreach ($candidates as $candidate) {
            $analyte = $this->analyteResolver->resolve($candidate->analyteNameRaw);
            $validation = $this->validator->validateCandidate($candidate, $analyte);

            if (! $validation['valid']) {
                $validationErrors[] = [
                    'analyte_name_raw' => $candidate->analyteNameRaw,
                    'errors' => $validation['errors'],
                ];

                continue;
            }

            $referenceStatus = $this->referenceEvaluator->evaluate(
                $candidate->valueType,
                $candidate->numericValue,
                $candidate->referenceLow,
                $candidate->referenceHigh,
            );

            $publishable[] = [
                'candidate' => $candidate,
                'analyte' => $analyte,
                'reference_status' => $referenceStatus,
            ];

            $confidenceTotal += $candidate->confidence;
            $confidenceCount++;
        }

        return [
            'candidates' => $candidates,
            'publishable' => $publishable,
            'rejected_count' => count($validationErrors),
            'validation_errors' => $validationErrors,
            'confidence_overall' => $confidenceCount > 0
                ? round($confidenceTotal / $confidenceCount, 4)
                : null,
        ];
    }

    /**
     * @param  array{candidate: LaboratoryResultObservationCandidate, analyte: \App\Models\LaboratoryAnalyte, reference_status: \App\Enums\LaboratoryResultReferenceStatus}  $item
     * @return array<string, mixed>
     */
    private function observationAttributes(int $reportId, array $item): array
    {
        /** @var LaboratoryResultObservationCandidate $candidate */
        $candidate = $item['candidate'];
        $analyte = $item['analyte'];

        return [
            'laboratory_result_report_id' => $reportId,
            'laboratory_analyte_id' => $analyte->id,
            'analyte_code' => $analyte->code,
            'analyte_name_raw' => $candidate->analyteNameRaw,
            'analyte_name_display' => $analyte->canonical_name,
            'numeric_value' => $candidate->numericValue,
            'text_value' => $candidate->textValue,
            'value_type' => $candidate->valueType,
            'unit' => $candidate->unit,
            'unit_raw' => $candidate->unitRaw,
            'reference_low' => $candidate->referenceLow,
            'reference_high' => $candidate->referenceHigh,
            'reference_text' => $candidate->referenceText,
            'reference_status' => $item['reference_status'],
            'abnormal_flag' => in_array($item['reference_status']->value, ['low', 'high', 'abnormal'], true),
            'abnormal_source' => $candidate->valueType === LaboratoryResultObservationValueType::Qualitative
                ? LaboratoryResultAbnormalSource::None
                : LaboratoryResultAbnormalSource::Computed,
            'extraction_method' => LaboratoryResultExtractionMethod::PdfText,
            'confidence' => $candidate->confidence,
            'source_page' => $candidate->sourcePage,
            'metadata' => [
                'parse_rule' => $candidate->parseRule,
            ],
        ];
    }

    private function finalizeFailure(
        LaboratoryResultVersion $version,
        int $purchaseId,
        string $inputHash,
        string $extractorVersion,
        string $errorCode,
        float $started,
        ?array $rawPayload = null,
    ): LaboratoryResultReport {
        $report = LaboratoryResultReport::query()->create([
            'laboratory_purchase_id' => $purchaseId,
            'laboratory_result_version_id' => $version->id,
            'source' => $this->resolveSource($version->source),
            'extraction_method' => LaboratoryResultExtractionMethod::PdfText,
            'extraction_status' => LaboratoryResultExtractionStatus::Failed,
            'structured_status' => LaboratoryResultStructuredStatus::Draft,
            'observation_count' => 0,
            'input_hash' => $inputHash,
            'extractor_version' => $extractorVersion,
            'validation_errors' => [['error_code' => $errorCode]],
            'raw_extraction_payload' => $rawPayload,
        ]);

        $this->recordEvent($version, LaboratoryResultEventType::ExtractionFailed, [
            'report_id' => $report->id,
            'version_id' => $version->id,
            'extractor_version' => $extractorVersion,
            'error_code' => $errorCode,
            'duration_ms' => (int) round((microtime(true) - $started) * 1000),
        ]);

        return $report;
    }

    private function finalizeInsufficientText(
        LaboratoryResultVersion $version,
        int $purchaseId,
        string $inputHash,
        string $extractorVersion,
        LaboratoryResultTextExtractionResult $extraction,
        LaboratoryResultTextMetrics $metrics,
        float $started,
    ): LaboratoryResultReport {
        $report = LaboratoryResultReport::query()->create([
            'laboratory_purchase_id' => $purchaseId,
            'laboratory_result_version_id' => $version->id,
            'source' => $this->resolveSource($version->source),
            'extraction_method' => LaboratoryResultExtractionMethod::PdfText,
            'extraction_status' => LaboratoryResultExtractionStatus::ManualReview,
            'structured_status' => LaboratoryResultStructuredStatus::Draft,
            'observation_count' => 0,
            'input_hash' => $inputHash,
            'extractor_version' => $extractorVersion,
            'validation_errors' => [['error_code' => 'insufficient_text']],
            'raw_extraction_payload' => [
                'metrics' => [
                    'page_count' => $metrics->pageCount,
                    'total_characters' => $metrics->totalCharacters,
                    'characters_per_page' => $metrics->charactersPerPage,
                ],
            ],
        ]);

        $this->recordEvent($version, LaboratoryResultEventType::ExtractionPartial, [
            'report_id' => $report->id,
            'version_id' => $version->id,
            'extractor_version' => $extractorVersion,
            'error_code' => 'insufficient_text',
            'duration_ms' => (int) round((microtime(true) - $started) * 1000),
        ]);

        return $report;
    }

    private function resolveSource(string $versionSource): LaboratoryResultReportSource
    {
        return match ($versionSource) {
            'manual_admin', 'manual' => LaboratoryResultReportSource::ManualAdmin,
            'patient_upload' => LaboratoryResultReportSource::PatientUpload,
            default => LaboratoryResultReportSource::Gda,
        };
    }

    /**
     * @param  array<string, mixed>  $metadata
     */
    private function recordEvent(
        LaboratoryResultVersion $version,
        LaboratoryResultEventType $eventType,
        array $metadata,
    ): void {
        $statusId = $version->resultStatus?->id;

        if ($statusId === null) {
            return;
        }

        LaboratoryResultEvent::query()->create([
            'laboratory_result_status_id' => $statusId,
            'laboratory_result_version_id' => $version->id,
            'event_type' => $eventType,
            'metadata' => $metadata,
            'created_at' => now(),
        ]);
    }
}
