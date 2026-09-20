<?php

namespace App\Services\LaboratoryResults\Extraction;

use App\Enums\LaboratoryResultExtractionComparisonOutcome;
use App\Enums\LaboratoryResultExtractionMethod;
use App\Models\AiPrompt;
use App\Models\LaboratoryResultReport;
use App\Models\LaboratoryResultVersion;
use Illuminate\Support\Facades\Storage;
use RuntimeException;

/**
 * QA shadow experiment 8C-10B — no publica, no reemplaza Text.
 */
class LaboratoryResultHybridExtractionExperimentQaService
{
    public function __construct(
        private readonly LaboratoryResultHybridExtractionQaService $baselineQaService,
        private readonly LaboratoryResultTextExtractor $textExtractor,
        private readonly LaboratoryResultTextParser $textParser,
        private readonly LaboratoryAnalyteResolver $analyteResolver,
        private readonly LaboratoryResultExtractionValidator $validator,
        private readonly LaboratoryResultReferenceEvaluator $referenceEvaluator,
        private readonly LaboratoryResultVisionExperimentalExtractor $experimentalExtractor,
        private readonly LaboratoryResultExtractionComparator $extractionComparator,
        private readonly LaboratoryResultExtractionQaRecorder $qaRecorder,
        private readonly LaboratoryResultExtractionQaDocumentClassifier $documentClassifier,
    ) {}

    public function compareVersionExperiment(int $laboratoryResultVersionId): LaboratoryResultExtractionQaResult
    {
        $this->baselineQaService->assertAllowedEnvironment();

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

        $visionResult = $this->experimentalExtractor->extractForVersion($version, $pdfBinary);
        $visionExtractionStatus = match (true) {
            $visionResult->success => 'executed',
            $visionResult->skippedIdempotent => 'idempotent_skip',
            default => 'failed',
        };

        $visionCandidates = $visionResult->candidates;
        $comparison = $this->extractionComparator->compare($textCandidates, $visionCandidates);

        $comparisonReport = new LaboratoryResultExtractionComparisonReport(
            comparison: $comparison,
            textObservationCount: count($textCandidates),
            visionObservationCount: count($visionCandidates),
            visionExecuted: $visionResult->success || $visionResult->skippedIdempotent,
            shadowMode: true,
            visionConfidenceAvg: $this->averageConfidence($visionCandidates),
            visionPageCount: count($visionResult->pagesSent),
        );

        $promptVersion = AiPrompt::query()
            ->where('key', LaboratoryResultVisionExperimentalExtractor::PROMPT_KEY)
            ->where('status', AiPrompt::STATUS_ACTIVE)
            ->orderByDesc('version')
            ->value('version');

        $qaMetric = $this->qaRecorder->record(
            laboratoryResultVersionId: $version->id,
            report: $comparisonReport,
            textReportId: $existingTextReport?->id,
            visionReportId: null,
            aiExecutionId: $visionResult->aiExecution?->id,
            textExtractionStatus: $textExtractionStatus,
            visionExtractionStatus: $visionExtractionStatus,
            textExtractorVersion: $textExtractorVersion,
            visionExtractorVersion: $visionResult->extractorVersion,
            promptVersion: $promptVersion !== null ? (int) $promptVersion : $visionResult->promptVersion,
            fallbackReasons: ['experiment_10b_real_pdf_raster_hybrid'],
            documentCategory: $this->documentClassifier->classify($comparisonReport),
            versionSource: $version->source,
            visionSkippedIdempotent: $visionResult->skippedIdempotent,
            visionInputHash: $visionResult->inputHash,
        );

        $textPublishedStatusAfter = $existingTextReport?->fresh()?->structured_status;

        return new LaboratoryResultExtractionQaResult(
            version: $version,
            comparisonReport: $comparisonReport,
            qaMetric: $qaMetric,
            textReportId: $existingTextReport?->id,
            visionReportId: null,
            aiExecutionId: $visionResult->aiExecution?->id,
            fallbackReasons: $qaMetric->fallback_reasons ?? [],
            textPublishedStatusBefore: $textPublishedStatusBefore?->value,
            textPublishedStatusAfter: $textPublishedStatusAfter?->value,
            comparisonItems: $comparison->items,
            textPageCount: $extraction->pageCount,
        );
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function targetAnalyteSnapshots(LaboratoryResultExtractionQaResult $result): array
    {
        $targets = [
            'FAMEDIC_CBC_PLT' => 'Plaquetas',
            'FAMEDIC_CBC_HGM' => 'HGM',
            'FAMEDIC_CBC_RDW' => 'RDW',
            'FAMEDIC_CBC_RBC' => 'Eritrocitos',
            'FAMEDIC_CBC_NEUT' => 'Neutrófilos',
            'FAMEDIC_CBC_VPM' => 'VPM',
        ];

        $snapshots = [];

        foreach ($targets as $code => $label) {
            $item = collect($result->comparisonItems)->first(
                fn ($row) => $row->analyteCode === $code
            );

            $snapshots[] = [
                'analyte' => $label,
                'analyte_code' => $code,
                'outcome' => $item?->outcome->value ?? LaboratoryResultExtractionComparisonOutcome::Unresolved->value,
                'text' => $this->formatCandidateSnapshot($item?->textCandidate),
                'vision' => $this->formatCandidateSnapshot($item?->visionCandidate),
                'conflict_fields' => $item?->conflictFields ?? [],
            ];
        }

        return $snapshots;
    }

    private function findExistingTextReport(LaboratoryResultVersion $version): ?LaboratoryResultReport
    {
        return LaboratoryResultReport::query()
            ->where('laboratory_result_version_id', $version->id)
            ->where('extraction_method', LaboratoryResultExtractionMethod::PdfText)
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

    /**
     * @return array{value: mixed, unit: ?string, reference: ?string}|null
     */
    private function formatCandidateSnapshot(?LaboratoryResultObservationCandidate $candidate): ?array
    {
        if ($candidate === null) {
            return null;
        }

        return [
            'value' => $candidate->numericValue ?? $candidate->textValue,
            'unit' => $candidate->unit,
            'reference' => $candidate->referenceText,
        ];
    }
}
