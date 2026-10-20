<?php

namespace App\Services\LaboratoryResults\StructuredQa;

use App\Enums\LaboratoryResultStructuredStatus;
use App\Enums\LaboratoryStructuredResultPublicationApprovalStatus;
use App\Models\LaboratoryResultReport;

class LaboratoryStructuredResultPublicationGate
{
    public function __construct(
        private readonly LaboratoryStructuredResultPublicationApprovalGate $approvalGate,
    ) {}

    public function evaluate(LaboratoryResultReport $report): LaboratoryStructuredResultPublicationGateResult
    {
        $report->loadMissing(['observations', 'resultVersion']);

        $approval = LaboratoryStructuredResultPublicationApproval::read($report);
        $preview = [
            'report_id' => $report->id,
            'version_id' => $report->laboratory_result_version_id,
            'promotion_validated' => LaboratoryStructuredResultPublicationApproval::isPromotionValidated($report),
            'approval_status' => $approval['approval_status'],
            'observation_count' => $report->observations->count(),
            'pii_safe' => ! $report->observations->contains(
                fn ($observation) => ($observation->metadata['pii_unsafe'] ?? false) === true,
            ),
        ];

        if (! config('laboratory-results.structured_publication.enabled', false)) {
            return $this->blocked(
                ['publication_disabled'],
                ['Publicación estructurada deshabilitada (LAB_RESULTS_STRUCTURED_PUBLICATION_ENABLED).'],
                $preview,
            );
        }

        if ($report->structured_status === LaboratoryResultStructuredStatus::Published
            && $report->published_version_slot !== null) {
            return new LaboratoryStructuredResultPublicationGateResult(
                canPublish: false,
                reasonCodes: ['already_published'],
                reasonsHuman: ['El reporte ya está publicado.'],
                preview: array_merge($preview, ['already_published' => true]),
            );
        }

        if (($approval['approval_status'] ?? null) !== LaboratoryStructuredResultPublicationApprovalStatus::Approved->value) {
            return $this->blocked(
                ['approval_not_approved'],
                ['El reporte no tiene aprobación humana APPROVED.'],
                $preview,
            );
        }

        $approvalGate = $this->approvalGate->evaluate($report);

        if (! $approvalGate->canApprove) {
            return $this->blocked(
                $approvalGate->reasonCodes,
                $approvalGate->reasonsHuman,
                $preview,
            );
        }

        $snapshotAtApproval = $approval['content_snapshot_hash'] ?? null;
        $currentSnapshot = LaboratoryStructuredResultContentSnapshot::compute($report);

        if ($snapshotAtApproval !== null && $snapshotAtApproval !== $currentSnapshot) {
            return $this->blocked(
                ['approval_stale'],
                ['El contenido estructurado cambió después de la aprobación.'],
                array_merge($preview, ['content_snapshot_hash' => $currentSnapshot]),
            );
        }

        $versionId = $report->laboratory_result_version_id;

        if ($versionId === null) {
            return $this->blocked(
                ['version_missing'],
                ['Versión documental ausente.'],
                $preview,
            );
        }

        $conflictingPublished = LaboratoryResultReport::query()
            ->where('published_version_slot', $versionId)
            ->where('id', '!=', $report->id)
            ->activePublished()
            ->exists();

        if ($conflictingPublished) {
            return $this->blocked(
                ['published_slot_occupied'],
                ['Ya existe un reporte publicado activo para esta versión.'],
                $preview,
            );
        }

        return new LaboratoryStructuredResultPublicationGateResult(
            canPublish: true,
            reasonCodes: [],
            reasonsHuman: [],
            preview: array_merge($preview, [
                'content_snapshot_hash' => $currentSnapshot,
                'publication_eligible' => true,
            ]),
        );
    }

    /**
     * @param  list<string>  $reasonCodes
     * @param  list<string>  $reasonsHuman
     * @param  array<string, mixed>  $preview
     */
    private function blocked(
        array $reasonCodes,
        array $reasonsHuman,
        array $preview,
    ): LaboratoryStructuredResultPublicationGateResult {
        return new LaboratoryStructuredResultPublicationGateResult(
            canPublish: false,
            reasonCodes: $reasonCodes,
            reasonsHuman: $reasonsHuman,
            preview: array_merge($preview, ['publication_eligible' => false]),
        );
    }
}
