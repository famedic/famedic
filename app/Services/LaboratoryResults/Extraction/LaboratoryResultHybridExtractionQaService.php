<?php

namespace App\Services\LaboratoryResults\Extraction;

use App\Enums\LaboratoryResultExtractionMethod;
use App\Models\AiPrompt;
use App\Models\LaboratoryResultExtractionQaMetric;
use App\Models\LaboratoryResultReport;
use App\Models\LaboratoryResultVersion;
use Illuminate\Support\Facades\Storage;
use RuntimeException;

class LaboratoryResultHybridExtractionQaService
{
    public function __construct(
        private readonly LaboratoryResultTextExtractor $textExtractor,
        private readonly LaboratoryResultTextMetricsCalculator $metricsCalculator,
        private readonly LaboratoryResultTextParser $textParser,
        private readonly LaboratoryAnalyteResolver $analyteResolver,
        private readonly LaboratoryResultExtractionValidator $validator,
        private readonly LaboratoryResultReferenceEvaluator $referenceEvaluator,
        private readonly LaboratoryResultVisionExtractor $visionExtractor,
        private readonly LaboratoryResultVisionFallbackEvaluator $visionFallbackEvaluator,
        private readonly LaboratoryResultExtractionComparator $extractionComparator,
        private readonly LaboratoryResultExtractionQaRecorder $qaRecorder,
        private readonly LaboratoryResultExtractionQaDocumentClassifier $documentClassifier,
    ) {}

    public function assertAllowedEnvironment(): void
    {
        $allowed = config('laboratory-results.qa.allowed_environments', ['local', 'testing']);

        if (! in_array(app()->environment(), $allowed, true)) {
            throw new RuntimeException(
                'Laboratory result QA compare is only allowed in: '.implode(', ', $allowed)
            );
        }
    }

    public function compareVersion(int $laboratoryResultVersionId, bool $forceVision = false): LaboratoryResultExtractionQaResult
    {
        $this->assertAllowedEnvironment();

        $version = LaboratoryResultVersion::query()->find($laboratoryResultVersionId);

        if (! $version) {
            throw new RuntimeException('LaboratoryResultVersion not found: '.$laboratoryResultVersionId);
        }

        if (! Storage::exists($version->storage_path)) {
            throw new RuntimeException('PDF not found at storage path for version '.$version->id);
        }

        $pdfBinary = Storage::get($version->storage_path);
        $textExtractorVersion = (string) config(
            'laboratory-results.structured_extraction.extractor_version',
            LaboratoryResultInputHash::DEFAULT_EXTRACTOR_VERSION
        );

        $existingTextReport = $this->findExistingTextReport($version);
        $textPublishedStatusBefore = $existingTextReport?->structured_status;

        $extraction = $this->textExtractor->extractFromBinary($pdfBinary);
        $metrics = $this->metricsCalculator->calculate($extraction);
        $candidates = $extraction->success ? $this->textParser->parse($extraction) : [];
        $validationSummary = $this->validateCandidates($candidates);

        $textCandidates = $validationSummary['publishable'] !== []
            ? array_map(
                fn (array $item): LaboratoryResultObservationCandidate => $item['candidate'],
                $validationSummary['publishable'],
            )
            : $validationSummary['candidates'];

        $textExtractionStatus = $extraction->success
            ? ($validationSummary['publishable'] !== [] ? 'extracted' : 'manual_review')
            : 'failed';

        $fallbackReasons = $this->visionFallbackEvaluator->resolveFallbackReasons(
            $metrics,
            $validationSummary,
            $extraction->success,
        );

        $visionEnabled = (bool) config('laboratory-results.vision_extraction.enabled', false);
        $shadowMode = (bool) config('laboratory-results.vision_extraction.shadow_mode', true);
        $shouldRunVision = $visionEnabled && (
            $forceVision
            || $this->visionFallbackEvaluator->shouldFallback($metrics, $validationSummary, $extraction->success)
        );

        $visionResult = null;
        $visionExtractionStatus = $visionEnabled ? 'skipped' : 'disabled';

        if ($shouldRunVision) {
            $visionResult = $this->visionExtractor->extractForVersion($version, $pdfBinary);
            $visionExtractionStatus = match (true) {
                $visionResult->success => 'executed',
                $visionResult->skippedIdempotent => 'idempotent_skip',
                default => 'failed',
            };
        }

        $visionCandidates = $visionResult?->candidates ?? [];
        $comparison = $this->extractionComparator->compare($textCandidates, $visionCandidates);

        $visionConfidenceAvg = $this->averageConfidence($visionCandidates);

        $comparisonReport = new LaboratoryResultExtractionComparisonReport(
            comparison: $comparison,
            textObservationCount: count($textCandidates),
            visionObservationCount: count($visionCandidates),
            visionExecuted: $shouldRunVision && $visionResult !== null,
            shadowMode: $shadowMode,
            visionConfidenceAvg: $visionConfidenceAvg,
            visionPageCount: $visionResult !== null ? count($visionResult->pagesSent) : null,
        );

        $promptVersion = AiPrompt::query()
            ->where('key', LaboratoryResultVisionExtractor::PROMPT_KEY)
            ->where('status', AiPrompt::STATUS_ACTIVE)
            ->orderByDesc('version')
            ->value('version');

        $existingVisionReport = $this->findExistingVisionReport($version);
        $documentCategory = $this->documentClassifier->classify($comparisonReport);
        $resolvedFallbackReasons = $this->resolveQaFallbackReasons($fallbackReasons, $forceVision);

        $qaMetric = $this->qaRecorder->record(
            laboratoryResultVersionId: $version->id,
            report: $comparisonReport,
            textReportId: $existingTextReport?->id,
            visionReportId: $existingVisionReport?->id,
            aiExecutionId: $visionResult?->aiExecution?->id,
            textExtractionStatus: $textExtractionStatus,
            visionExtractionStatus: $visionExtractionStatus,
            textExtractorVersion: $textExtractorVersion,
            visionExtractorVersion: $visionResult?->extractorVersion,
            promptVersion: $promptVersion !== null ? (int) $promptVersion : $visionResult?->promptVersion,
            fallbackReasons: $resolvedFallbackReasons,
            documentCategory: $documentCategory,
            versionSource: $version->source,
            visionSkippedIdempotent: $visionResult?->skippedIdempotent ?? false,
            visionInputHash: $visionResult?->inputHash,
        );

        $textPublishedStatusAfter = $existingTextReport?->fresh()?->structured_status;

        return new LaboratoryResultExtractionQaResult(
            version: $version,
            comparisonReport: $comparisonReport,
            qaMetric: $qaMetric,
            textReportId: $existingTextReport?->id,
            visionReportId: $existingVisionReport?->id,
            aiExecutionId: $visionResult?->aiExecution?->id,
            fallbackReasons: $qaMetric->fallback_reasons ?? [],
            textPublishedStatusBefore: $textPublishedStatusBefore?->value,
            textPublishedStatusAfter: $textPublishedStatusAfter?->value,
            comparisonItems: $comparison->items,
            textPageCount: $extraction->pageCount,
        );
    }

    private function findExistingTextReport(LaboratoryResultVersion $version): ?LaboratoryResultReport
    {
        return LaboratoryResultReport::query()
            ->where('laboratory_result_version_id', $version->id)
            ->where('extraction_method', LaboratoryResultExtractionMethod::PdfText)
            ->latest('id')
            ->first();
    }

    private function findExistingVisionReport(LaboratoryResultVersion $version): ?LaboratoryResultReport
    {
        return LaboratoryResultReport::query()
            ->where('laboratory_result_version_id', $version->id)
            ->where('extraction_method', LaboratoryResultExtractionMethod::Vision)
            ->latest('id')
            ->first();
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
     * @param  list<string>  $reasons
     * @return list<string>
     */
    private function resolveQaFallbackReasons(array $reasons, bool $forceVision): array
    {
        if (! $forceVision) {
            return $reasons;
        }

        $filtered = array_values(array_filter(
            $reasons,
            fn (string $reason): bool => $reason !== 'fallback_not_required',
        ));
        $filtered[] = 'force_vision';

        return array_values(array_unique($filtered));
    }

    /**
     * @param  list<LaboratoryResultObservationCandidate>  $candidates
     */
    private function averageConfidence(array $candidates): ?float
    {
        if ($candidates === []) {
            return null;
        }

        $total = array_sum(array_map(fn ($c) => $c->confidence, $candidates));

        return round($total / count($candidates), 4);
    }
}
