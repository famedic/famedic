<?php

namespace App\Services\LaboratoryResults\StructuredQa;

use App\Enums\LaboratoryResultAbnormalSource;
use App\Enums\LaboratoryResultExtractionMethod;
use App\Enums\LaboratoryResultExtractionStatus;
use App\Enums\LaboratoryResultObservationValueType;
use App\Enums\LaboratoryResultReferenceStatus;
use App\Enums\LaboratoryResultReportSource;
use App\Enums\LaboratoryResultStructuredStatus;
use App\Models\LaboratoryAnalyte;
use App\Models\LaboratoryResultObservation;
use App\Models\LaboratoryResultReport;
use App\Models\LaboratoryResultVersion;
use App\Services\LaboratoryResults\Extraction\LaboratoryReferenceNormalizer;
use App\Services\LaboratoryResults\Extraction\LaboratoryResultInputHash;
use App\Services\LaboratoryResults\Extraction\LaboratoryUnitNormalizer;
use Illuminate\Support\Facades\DB;
use RuntimeException;

class LaboratoryStructuredQaPersistenceService
{
    public function __construct(
        private readonly LaboratoryStructuredQaCandidateRegistry $registry,
        private readonly LaboratoryStructuredQaValidator $validator,
        private readonly LaboratoryUnitNormalizer $unitNormalizer,
        private readonly LaboratoryReferenceNormalizer $referenceNormalizer,
    ) {}

    public function assertEnabled(): void
    {
        if (! config('laboratory-results.structured_shadow_qa.enabled', false)) {
            throw new RuntimeException(
                'Structured Shadow QA pilot is disabled. Set LAB_RESULTS_STRUCTURED_SHADOW_QA_ENABLED=true.'
            );
        }
    }

    public function assertAllowedEnvironment(): void
    {
        if (app()->environment('production')) {
            throw new RuntimeException('Structured Shadow QA pilot cannot run in production.');
        }

        $allowed = config('laboratory-results.qa.allowed_environments', ['local', 'testing']);

        if (! in_array(app()->environment(), $allowed, true)) {
            throw new RuntimeException(
                'Structured Shadow QA pilot is only allowed in: '.implode(', ', $allowed)
            );
        }
    }

    public function run(LaboratoryStructuredQaRunOptions $options): LaboratoryStructuredQaRunResult
    {
        $this->assertEnabled();
        $this->assertAllowedEnvironment();

        $candidates = $this->registry->candidates($options->versionId, $options->limit);
        $candidatesInput = count($candidates);

        $validatedPreview = [];
        $rejected = [];
        $persisted = [];
        $validCount = 0;
        $cbcValid = 0;
        $nonCbcValid = 0;
        $persistedReportCount = 0;
        $observationsPersisted = 0;
        $observationsDuplicatePrevented = 0;
        $cbcPersisted = 0;
        $nonCbcPersisted = 0;

        /** @var array<int, list<array{candidate: LaboratoryStructuredQaCandidate, analyte: LaboratoryAnalyte}>> $grouped */
        $grouped = [];

        foreach ($candidates as $candidate) {
            $validation = $this->validator->validate($candidate);

            if (! $validation->valid) {
                $rejected[] = $this->previewRow($candidate, $validation->reasons);

                continue;
            }

            $validCount++;

            if ($candidate->isCbc) {
                $cbcValid++;
            } else {
                $nonCbcValid++;
            }

            $analyte = LaboratoryAnalyte::query()
                ->where('code', $candidate->analyteCode)
                ->firstOrFail();

            $validatedPreview[] = $this->previewRow($candidate, [], $analyte);

            if ($options->dryRun) {
                continue;
            }

            $grouped[$candidate->laboratoryResultVersionId][] = [
                'candidate' => $candidate,
                'analyte' => $analyte,
            ];
        }

        if (! $options->dryRun) {
            foreach ($grouped as $versionId => $items) {
                $persistOutcome = $this->persistVersionGroup($versionId, $items);
                $persistedReportCount += $persistOutcome['report_created'] ? 1 : 0;
                $observationsPersisted += $persistOutcome['observations_created'];
                $observationsDuplicatePrevented += $persistOutcome['observations_duplicate_prevented'];

                foreach ($items as $item) {
                    /** @var LaboratoryStructuredQaCandidate $candidate */
                    $candidate = $item['candidate'];

                    if ($candidate->isCbc) {
                        $cbcPersisted++;
                    } else {
                        $nonCbcPersisted++;
                    }

                    $persisted[] = $this->previewRow($candidate, [], $item['analyte']);
                }
            }
        }

        return new LaboratoryStructuredQaRunResult(
            candidatesInput: $candidatesInput,
            validCount: $validCount,
            rejectedCount: count($rejected),
            persistedReportCount: $persistedReportCount,
            observationsPersisted: $observationsPersisted,
            observationsDuplicatePrevented: $observationsDuplicatePrevented,
            cbcValid: $cbcValid,
            nonCbcValid: $nonCbcValid,
            cbcPersisted: $cbcPersisted,
            nonCbcPersisted: $nonCbcPersisted,
            dryRun: $options->dryRun,
            validatedPreview: $validatedPreview,
            rejected: $rejected,
            persisted: $persisted,
        );
    }

    /**
     * @param  list<array{candidate: LaboratoryStructuredQaCandidate, analyte: LaboratoryAnalyte}>  $items
     * @return array{report_created: bool, observations_created: int, observations_duplicate_prevented: int}
     */
    private function persistVersionGroup(int $versionId, array $items): array
    {
        return DB::transaction(function () use ($versionId, $items): array {
            $version = LaboratoryResultVersion::query()
                ->with('resultStatus.laboratoryPurchase')
                ->findOrFail($versionId);

            $purchaseId = (int) $version->resultStatus->laboratory_purchase_id;
            $experimentKey = (string) config(
                'laboratory-results.structured_shadow_qa.experiment_key',
                LaboratoryResultInputHash::STRUCTURED_SHADOW_QA_EXPERIMENT_KEY,
            );
            $extractorVersion = (string) config(
                'laboratory-results.structured_shadow_qa.extractor_version',
                LaboratoryResultInputHash::STRUCTURED_SHADOW_QA_EXTRACTOR_VERSION,
            );

            $inputHash = LaboratoryResultInputHash::computeExperiment(
                $version->sha256,
                $experimentKey,
                $extractorVersion,
            );

            $reportCreated = false;

            $report = LaboratoryResultReport::query()
                ->where('laboratory_result_version_id', $version->id)
                ->where('input_hash', $inputHash)
                ->lockForUpdate()
                ->first();

            if ($report === null) {
                $report = LaboratoryResultReport::query()->create([
                    'laboratory_purchase_id' => $purchaseId,
                    'laboratory_result_version_id' => $version->id,
                    'source' => LaboratoryResultReportSource::Gda,
                    'extraction_method' => LaboratoryResultExtractionMethod::Vision,
                    'extraction_status' => LaboratoryResultExtractionStatus::Extracted,
                    'structured_status' => LaboratoryResultStructuredStatus::Draft,
                    'observation_count' => 0,
                    'input_hash' => $inputHash,
                    'extractor_version' => $extractorVersion,
                    'published_version_slot' => null,
                    'raw_extraction_payload' => [
                        'shadow_qa' => true,
                        'mode' => 'shadow_qa',
                        'source' => 'vision',
                        'phase' => (string) config('laboratory-results.structured_shadow_qa.phase', '8C-17D'),
                        'experiment_key' => $experimentKey,
                    ],
                ]);
                $reportCreated = true;
            }

            $observationsCreated = 0;
            $observationsDuplicatePrevented = 0;

            foreach ($items as $item) {
                $outcome = $this->persistObservation($report, $item['candidate'], $item['analyte']);

                if ($outcome === 'created') {
                    $observationsCreated++;
                } else {
                    $observationsDuplicatePrevented++;
                }
            }

            $report->update([
                'observation_count' => $report->observations()->count(),
                'confidence_overall' => $report->observations()->avg('confidence'),
            ]);

            return [
                'report_created' => $reportCreated,
                'observations_created' => $observationsCreated,
                'observations_duplicate_prevented' => $observationsDuplicatePrevented,
            ];
        });
    }

    private function persistObservation(
        LaboratoryResultReport $report,
        LaboratoryStructuredQaCandidate $candidate,
        LaboratoryAnalyte $analyte,
    ): string {
        $identityHash = $candidate->observationIdentityHash();

        $existing = LaboratoryResultObservation::query()
            ->where('laboratory_result_report_id', $report->id)
            ->where('metadata->shadow_qa_identity', $identityHash)
            ->first();

        if ($existing !== null) {
            return 'duplicate';
        }

        $canonicalUnit = $this->resolveCanonicalUnit($analyte, $candidate->unitRaw);
        $parsedReference = $this->referenceNormalizer->parse($candidate->referenceText);
        $referenceLow = $candidate->referenceLow ?? $parsedReference->referenceLow;
        $referenceHigh = $candidate->referenceHigh ?? $parsedReference->referenceHigh;

        LaboratoryResultObservation::query()->create([
            'laboratory_result_report_id' => $report->id,
            'laboratory_analyte_id' => $analyte->id,
            'analyte_code' => $analyte->code,
            'analyte_name_raw' => $candidate->analyteNameRaw,
            'analyte_name_display' => $analyte->canonical_name,
            'numeric_value' => is_numeric($candidate->value) ? (float) $candidate->value : null,
            'text_value' => null,
            'value_type' => LaboratoryResultObservationValueType::Numeric,
            'unit' => $canonicalUnit,
            'unit_raw' => $candidate->unitRaw,
            'reference_low' => $referenceLow,
            'reference_high' => $referenceHigh,
            'reference_text' => $candidate->referenceText,
            'reference_status' => LaboratoryResultReferenceStatus::Unknown,
            'abnormal_flag' => null,
            'abnormal_source' => LaboratoryResultAbnormalSource::None,
            'laboratory_purchase_item_id' => $candidate->laboratoryPurchaseItemId,
            'panel_name_raw' => $candidate->purchaseItemAssociation['purchase_item_name'] ?? null,
            'extraction_method' => LaboratoryResultExtractionMethod::Vision,
            'confidence' => $candidate->confidence,
            'source_page' => $candidate->sourcePage,
            'metadata' => [
                'shadow_qa' => true,
                'shadow_qa_identity' => $identityHash,
                'mode' => 'shadow_qa',
                'source' => 'vision',
                'phase' => (string) config('laboratory-results.structured_shadow_qa.phase', '8C-17D'),
                'validation_status' => 'ready',
                'purchase_association_method' => $candidate->purchaseAssociationMethod,
                'physical_pdf' => $candidate->physicalPdf,
                'laboratory_result_version_id' => $candidate->laboratoryResultVersionId,
                'identity_evidence' => $candidate->identity['evidence'] ?? null,
            ],
        ]);

        return 'created';
    }

    private function resolveCanonicalUnit(LaboratoryAnalyte $analyte, string $unitRaw): string
    {
        $catalogUnit = $analyte->default_unit;

        if ($catalogUnit !== null && $this->unitNormalizer->areEquivalent($unitRaw, $catalogUnit)) {
            return $catalogUnit;
        }

        return $unitRaw;
    }

    /**
     * @param  list<string>  $reasons
     * @return array<string, mixed>
     */
    private function previewRow(
        LaboratoryStructuredQaCandidate $candidate,
        array $reasons = [],
        ?LaboratoryAnalyte $analyte = null,
    ): array {
        $canonicalUnit = $analyte !== null
            ? $this->resolveCanonicalUnit($analyte, $candidate->unitRaw)
            : null;

        return [
            'version_id' => $candidate->laboratoryResultVersionId,
            'physical_pdf' => $candidate->physicalPdf,
            'analyte_code' => $candidate->analyteCode,
            'analyte_name' => $candidate->analyteDisplayName,
            'value' => $candidate->value,
            'unit_raw' => $candidate->unitRaw,
            'unit_canonical' => $canonicalUnit,
            'reference_text' => $candidate->referenceText,
            'reference_low' => $candidate->referenceLow,
            'reference_high' => $candidate->referenceHigh,
            'purchase_item_id' => $candidate->laboratoryPurchaseItemId,
            'purchase_association_method' => $candidate->purchaseAssociationMethod,
            'source_page' => $candidate->sourcePage,
            'confidence' => $candidate->confidence,
            'is_cbc' => $candidate->isCbc,
            'validation' => $reasons === [] ? 'valid' : 'rejected',
            'validation_reasons' => $reasons,
        ];
    }
}
