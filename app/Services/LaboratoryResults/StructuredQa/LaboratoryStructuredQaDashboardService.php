<?php

namespace App\Services\LaboratoryResults\StructuredQa;

use App\Enums\LaboratoryResultReferenceStatus;
use App\Enums\LaboratoryResultStructuredStatus;
use App\Enums\LaboratoryStructuredResultPromotionStatus;
use App\Enums\LaboratoryStructuredResultPublicationApprovalStatus;
use App\Models\LaboratoryResultObservation;
use App\Models\LaboratoryResultReport;
use Illuminate\Database\Eloquent\Builder;

class LaboratoryStructuredQaDashboardService
{
    public function __construct(
        private readonly LaboratoryStructuredResultPublicationApprovalGate $publicationApprovalGate,
        private readonly LaboratoryStructuredResultPublicationGate $publicationGate,
    ) {}

    /**
     * @param  array<string, mixed>  $filters
     * @return array{
     *     summary: list<array<string, mixed>>,
     *     rows: list<array<string, mixed>>,
     *     filterOptions: array<string, mixed>,
     *     meta: array<string, mixed>
     * }
     */
    public function build(array $filters = [], ?int $detailObservationId = null): array
    {
        $baseQuery = $this->shadowObservationQuery($filters);
        $allShadowObservations = (clone $baseQuery)->get();

        $summary = $this->buildSummary($allShadowObservations);
        $rows = $this->buildRows(
            (clone $baseQuery)->orderByDesc('laboratory_result_observations.id')->limit(200)->get()
        );

        $detail = $detailObservationId !== null
            ? $this->buildDetail($detailObservationId)
            : null;

        return [
            'summary' => $summary,
            'rows' => $rows,
            'filterOptions' => $this->filterOptions($allShadowObservations),
            'detail' => $detail,
            'meta' => [
                'total' => $allShadowObservations->count(),
                'phase' => '8C-19B',
                'purpose' => 'Validación técnica y aprobación humana antes de futura publicación.',
                'note' => 'Sin PII. Aprobación NO publica al paciente.',
            ],
        ];
    }

    /**
     * @param  array<string, mixed>  $filters
     */
    private function shadowObservationQuery(array $filters): Builder
    {
        $query = LaboratoryResultObservation::query()
            ->with(['report.resultVersion', 'analyte'])
            ->whereHas('report', fn (Builder $q) => $q->shadowQa());

        if (! empty($filters['analyte_code'])) {
            $query->where('analyte_code', (string) $filters['analyte_code']);
        }

        if (! empty($filters['reference_status'])) {
            $query->where('reference_status', (string) $filters['reference_status']);
        }

        if (! empty($filters['structured_status'])) {
            $query->whereHas('report', fn (Builder $q) => $q->where('structured_status', (string) $filters['structured_status']));
        }

        if (! empty($filters['promotion_status'])) {
            $status = (string) $filters['promotion_status'];
            $query->where('metadata->promotion_evaluation->promotion_status', $status);
        }

        if (! empty($filters['extraction_method'])) {
            $query->where('extraction_method', (string) $filters['extraction_method']);
        }

        if (! empty($filters['version_id'])) {
            $query->whereHas('report', fn (Builder $q) => $q->where('laboratory_result_version_id', (int) $filters['version_id']));
        }

        if (! empty($filters['confidence_min'])) {
            $query->where('confidence', '>=', (float) $filters['confidence_min']);
        }

        if (! empty($filters['date_from'])) {
            $query->whereDate('laboratory_result_observations.created_at', '>=', (string) $filters['date_from']);
        }

        if (! empty($filters['date_to'])) {
            $query->whereDate('laboratory_result_observations.created_at', '<=', (string) $filters['date_to']);
        }

        if (! empty($filters['approval_status'])) {
            $approvalStatus = (string) $filters['approval_status'];
            $query->whereHas('report', function (Builder $reportQuery) use ($approvalStatus) {
                $reportQuery->shadowQa();

                if ($approvalStatus === LaboratoryStructuredResultPublicationApprovalStatus::Pending->value) {
                    $reportQuery->where(function (Builder $pendingQuery) {
                        $pendingQuery
                            ->whereNull('raw_extraction_payload->publication_approval->approval_status')
                            ->orWhere(
                                'raw_extraction_payload->publication_approval->approval_status',
                                LaboratoryStructuredResultPublicationApprovalStatus::Pending->value,
                            );
                    });
                } else {
                    $reportQuery->where(
                        'raw_extraction_payload->publication_approval->approval_status',
                        $approvalStatus,
                    );
                }
            });
        }

        return $query;
    }

    /**
     * @param  \Illuminate\Support\Collection<int, LaboratoryResultObservation>  $observations
     * @return list<array<string, mixed>>
     */
    private function buildSummary($observations): array
    {
        $reportIds = $observations->pluck('laboratory_result_report_id')->unique();

        $promotionCounts = [
            'validated' => 0,
            'needs_review' => 0,
            'rejected' => 0,
            'shadow' => 0,
        ];

        $approvalCounts = [
            'pending' => 0,
            'approved' => 0,
            'rejected' => 0,
        ];

        foreach ($observations as $observation) {
            $status = $observation->metadata['promotion_evaluation']['promotion_status'] ?? 'shadow';
            $promotionCounts[$status] = ($promotionCounts[$status] ?? 0) + 1;
        }

        $reports = LaboratoryResultReport::query()
            ->whereIn('id', $reportIds)
            ->get();

        foreach ($reports as $report) {
            $approvalStatus = LaboratoryStructuredResultPublicationApproval::status($report);

            if ($approvalStatus === null) {
                continue;
            }

            $approvalCounts[$approvalStatus->value] = ($approvalCounts[$approvalStatus->value] ?? 0) + 1;
        }

        return [
            ['id' => 'reports', 'label' => 'Shadow Reports', 'value' => $reportIds->count(), 'tone' => 'sky'],
            ['id' => 'observations', 'label' => 'Shadow Observations', 'value' => $observations->count(), 'tone' => 'default'],
            ['id' => 'normal', 'label' => 'Normal', 'value' => $this->countReferenceStatus($observations, LaboratoryResultReferenceStatus::Normal), 'tone' => 'sky'],
            ['id' => 'low', 'label' => 'Low', 'value' => $this->countReferenceStatus($observations, LaboratoryResultReferenceStatus::Low), 'tone' => 'amber'],
            ['id' => 'high', 'label' => 'High', 'value' => $this->countReferenceStatus($observations, LaboratoryResultReferenceStatus::High), 'tone' => 'red'],
            ['id' => 'unknown', 'label' => 'Unknown', 'value' => $this->countReferenceStatus($observations, LaboratoryResultReferenceStatus::Unknown), 'tone' => 'zinc'],
            ['id' => 'needs_review', 'label' => 'Needs Review', 'value' => $promotionCounts['needs_review'], 'tone' => 'amber'],
            ['id' => 'validated', 'label' => 'Validated', 'value' => $promotionCounts['validated'], 'tone' => 'sky'],
            ['id' => 'rejected', 'label' => 'Rejected', 'value' => $promotionCounts['rejected'], 'tone' => 'red'],
            ['id' => 'approval_pending', 'label' => 'Approval Pending', 'value' => $approvalCounts['pending'], 'tone' => 'amber'],
            ['id' => 'approval_approved', 'label' => 'Approval Approved', 'value' => $approvalCounts['approved'], 'tone' => 'emerald'],
            ['id' => 'approval_rejected', 'label' => 'Approval Rejected', 'value' => $approvalCounts['rejected'], 'tone' => 'red'],
        ];
    }

    /**
     * @param  \Illuminate\Support\Collection<int, LaboratoryResultObservation>  $observations
     */
    private function countReferenceStatus($observations, LaboratoryResultReferenceStatus $status): int
    {
        return $observations->filter(fn (LaboratoryResultObservation $o) => $o->reference_status === $status)->count();
    }

    /**
     * @param  \Illuminate\Support\Collection<int, LaboratoryResultObservation>  $observations
     * @return list<array<string, mixed>>
     */
    private function buildRows($observations): array
    {
        return $observations->map(function (LaboratoryResultObservation $observation) {
            $report = $observation->report;
            $version = $report?->resultVersion;
            $promotion = $observation->metadata['promotion_evaluation']['promotion_status'] ?? 'shadow';
            $approval = $report !== null
                ? LaboratoryStructuredResultPublicationApproval::read($report)
                : ['approval_status' => null];

            return [
                'id' => $observation->id,
                'report_id' => $report?->id,
                'pdf' => $version ? basename($version->storage_path) : null,
                'version_id' => $report?->laboratory_result_version_id,
                'analyte' => $observation->analyte_name_display ?? $observation->analyte_name_raw,
                'analyte_code' => $observation->analyte_code,
                'value' => $observation->numeric_value ?? $observation->text_value,
                'unit' => $observation->unit ?? $observation->unit_raw,
                'reference' => $observation->reference_text,
                'reference_status' => $observation->reference_status?->value,
                'confidence' => $observation->confidence,
                'source_page' => $observation->source_page,
                'extraction_method' => $observation->extraction_method?->value,
                'structured_status' => $report?->structured_status?->value ?? LaboratoryResultStructuredStatus::Draft->value,
                'promotion_status' => $promotion,
                'approval_status' => $approval['approval_status'],
                'purchase_association' => $observation->metadata['purchase_association_method'] ?? null,
                'created_at' => $observation->created_at?->toIso8601String(),
            ];
        })->values()->all();
    }

    /**
     * @return array<string, mixed>|null
     */
    private function buildDetail(int $observationId): ?array
    {
        $observation = LaboratoryResultObservation::query()
            ->with(['report.resultVersion', 'analyte'])
            ->whereHas('report', fn (Builder $q) => $q->shadowQa())
            ->find($observationId);

        if ($observation === null) {
            return null;
        }

        $report = $observation->report;
        $referenceEvaluation = $observation->metadata['reference_evaluation'] ?? [];
        $promotionEvaluation = $observation->metadata['promotion_evaluation'] ?? [];
        $approval = $report !== null
            ? LaboratoryStructuredResultPublicationApproval::read($report)
            : ['approval_status' => null];
        $approvalGateResult = $report !== null
            ? $this->publicationApprovalGate->evaluate($report)
            : null;
        $publicationGateResult = $report !== null
            ? $this->publicationGate->evaluate($report)
            : null;

        return [
            'id' => $observation->id,
            'report_id' => $report?->id,
            'identity' => [
                'analyte' => $observation->analyte_name_display ?? $observation->analyte_name_raw,
                'code' => $observation->analyte_code,
                'value' => $observation->numeric_value ?? $observation->text_value,
                'value_type' => $observation->value_type?->value,
                'unit' => $observation->unit,
                'unit_raw' => $observation->unit_raw,
            ],
            'reference' => [
                'text' => $observation->reference_text,
                'low' => $observation->reference_low,
                'high' => $observation->reference_high,
                'status' => $observation->reference_status?->value,
            ],
            'out_of_range' => [
                'status' => $observation->reference_status?->value,
                'method' => $referenceEvaluation['method'] ?? null,
                'reason' => $referenceEvaluation['reason'] ?? null,
                'evaluated' => $referenceEvaluation['evaluated'] ?? false,
            ],
            'extraction' => [
                'method' => $observation->extraction_method?->value,
                'confidence' => $observation->confidence,
                'source_page' => $observation->source_page,
                'input_hash' => $report?->input_hash,
            ],
            'qa' => [
                'structured_status' => $report?->structured_status?->value,
                'promotion_status' => $promotionEvaluation['promotion_status'] ?? 'shadow',
                'reason_codes' => $promotionEvaluation['reason_codes'] ?? [],
                'reasons' => $promotionEvaluation['reasons'] ?? [],
                'gate_version' => $promotionEvaluation['gate_version'] ?? null,
                'association_method' => $observation->metadata['purchase_association_method'] ?? null,
            ],
            'approval' => [
                'status' => $approval['approval_status'],
                'approved_by_name' => $approval['approved_by_name'] ?? null,
                'approved_at' => $approval['approved_at'] ?? null,
                'reason' => $approval['reason'] ?? null,
                'rejected_by_name' => $approval['rejected_by_name'] ?? null,
                'rejected_at' => $approval['rejected_at'] ?? null,
                'rejection_reason' => $approval['rejection_reason'] ?? null,
                'approval_version' => $approval['approval_version'] ?? null,
                'can_approve' => $approvalGateResult?->canApprove ?? false,
                'can_reject' => ($approval['approval_status'] ?? null) === LaboratoryStructuredResultPublicationApprovalStatus::Pending->value
                    || ($approval['approval_status'] ?? null) === null,
                'block_reasons' => $approvalGateResult?->reasonsHuman ?? [],
            ],
            'publication' => [
                'can_publish' => $publicationGateResult?->canPublish ?? false,
                'eligible' => $publicationGateResult?->preview['publication_eligible'] ?? false,
                'block_reasons' => $publicationGateResult?->reasonsHuman ?? [],
                'preview' => $publicationGateResult?->preview ?? [],
                'gate_version' => config(
                    'laboratory-results.structured_publication.gate_version',
                    'publication_gate_v1',
                ),
                'feature_enabled' => (bool) config('laboratory-results.structured_publication.enabled', false),
            ],
            'why_here' => $this->whyHereMessage($promotionEvaluation),
        ];
    }

    /**
     * @param  array<string, mixed>  $promotionEvaluation
     */
    private function whyHereMessage(array $promotionEvaluation): string
    {
        $status = $promotionEvaluation['promotion_status'] ?? 'shadow';
        $reasons = $promotionEvaluation['reasons'] ?? [];

        if ($reasons === []) {
            return match ($status) {
                'validated' => 'Cumple criterios técnicos mínimos del promotion gate.',
                default => 'Observación Shadow QA pendiente de evaluación de promoción.',
            };
        }

        return implode(' ', $reasons);
    }

    /**
     * @param  \Illuminate\Support\Collection<int, LaboratoryResultObservation>  $observations
     * @return array<string, mixed>
     */
    private function filterOptions($observations): array
    {
        return [
            'analyte_codes' => $observations->pluck('analyte_code')->filter()->unique()->sort()->values()->all(),
            'reference_statuses' => collect(LaboratoryResultReferenceStatus::cases())->map->value->all(),
            'structured_statuses' => collect(LaboratoryResultStructuredStatus::cases())->map->value->all(),
            'promotion_statuses' => collect(LaboratoryStructuredResultPromotionStatus::cases())->map->value->all(),
            'approval_statuses' => collect(LaboratoryStructuredResultPublicationApprovalStatus::cases())->map->value->all(),
            'version_ids' => $observations
                ->map(fn (LaboratoryResultObservation $o) => $o->report?->laboratory_result_version_id)
                ->filter()
                ->unique()
                ->sort()
                ->values()
                ->all(),
        ];
    }
}
