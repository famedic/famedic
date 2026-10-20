<?php

namespace App\Services\LaboratoryResults\StructuredQa;

use App\Enums\LaboratoryResultEventType;
use App\Enums\LaboratoryResultStructuredStatus;
use App\Models\LaboratoryResultEvent;
use App\Models\LaboratoryResultReport;
use App\Models\User;
use App\Services\LaboratoryResults\Extraction\LaboratoryResultReportPublisher;
use Illuminate\Support\Facades\DB;
use RuntimeException;

class LaboratoryStructuredResultPublicationService
{
    public function __construct(
        private readonly LaboratoryStructuredResultPublicationGate $publicationGate,
        private readonly LaboratoryResultReportPublisher $publisher,
    ) {}

    public function assertEnabled(): void
    {
        if (! config('laboratory-results.structured_publication.enabled', false)) {
            throw new RuntimeException(
                'Structured publication is disabled. Set LAB_RESULTS_STRUCTURED_PUBLICATION_ENABLED=true.'
            );
        }
    }

    public function publish(
        LaboratoryResultReport $report,
        ?User $actor = null,
        bool $dryRun = false,
    ): LaboratoryStructuredResultPublicationResult {

        $report->loadMissing(['observations', 'resultVersion']);
        $gateResult = $this->publicationGate->evaluate($report);

        if ($gateResult->reasonCodes === ['already_published']) {
            return new LaboratoryStructuredResultPublicationResult(
                report: $report,
                published: true,
                idempotent: true,
                dryRun: $dryRun,
                preview: $gateResult->preview,
            );
        }

        if (! $gateResult->canPublish) {
            throw new LaboratoryStructuredResultPublicationException(
                reasonCode: $gateResult->reasonCodes[0] ?? 'publication_blocked',
                message: $gateResult->reasonsHuman[0] ?? 'Publicación bloqueada.',
                reasonCodes: $gateResult->reasonCodes,
                reasonsHuman: $gateResult->reasonsHuman,
            );
        }

        if ($dryRun) {
            return new LaboratoryStructuredResultPublicationResult(
                report: $report,
                published: false,
                idempotent: false,
                dryRun: true,
                preview: $gateResult->preview,
            );
        }

        return DB::transaction(function () use ($report, $actor, $gateResult) {
            $locked = LaboratoryResultReport::query()->lockForUpdate()->findOrFail($report->id);
            $recheck = $this->publicationGate->evaluate($locked);

            if ($recheck->reasonCodes === ['already_published']) {
                return new LaboratoryStructuredResultPublicationResult(
                    report: $locked,
                    published: true,
                    idempotent: true,
                    dryRun: false,
                    preview: $recheck->preview,
                );
            }

            if (! $recheck->canPublish) {
                throw new LaboratoryStructuredResultPublicationException(
                    reasonCode: $recheck->reasonCodes[0] ?? 'publication_blocked',
                    message: $recheck->reasonsHuman[0] ?? 'Publicación bloqueada tras revalidación.',
                    reasonCodes: $recheck->reasonCodes,
                    reasonsHuman: $recheck->reasonsHuman,
                );
            }

            $previousStatus = $locked->structured_status?->value ?? LaboratoryResultStructuredStatus::Draft->value;

            $published = $this->publisher->publish($locked);

            $this->recordControlledPublicationEvent($published, $actor, $previousStatus, $recheck);

            return new LaboratoryStructuredResultPublicationResult(
                report: $published->fresh(['observations']),
                published: true,
                idempotent: false,
                dryRun: false,
                preview: $recheck->preview,
            );
        });
    }

    private function recordControlledPublicationEvent(
        LaboratoryResultReport $report,
        ?User $actor,
        string $previousStatus,
        LaboratoryStructuredResultPublicationGateResult $gateResult,
    ): void {
        $statusId = $report->resultVersion?->laboratory_result_status_id;

        if ($statusId === null) {
            return;
        }

        LaboratoryResultEvent::query()->create([
            'laboratory_result_status_id' => $statusId,
            'laboratory_result_version_id' => $report->laboratory_result_version_id,
            'event_type' => LaboratoryResultEventType::StructuredResultControlledPublished,
            'actor_type' => $actor !== null ? User::class : 'system',
            'actor_id' => $actor?->id,
            'metadata' => [
                'report_id' => $report->id,
                'version_id' => $report->laboratory_result_version_id,
                'previous_status' => $previousStatus,
                'new_status' => LaboratoryResultStructuredStatus::Published->value,
                'publication_gate_version' => config(
                    'laboratory-results.structured_publication.gate_version',
                    'publication_gate_v1',
                ),
                'observation_count' => $report->observation_count,
                'preview' => $gateResult->preview,
            ],
            'created_at' => now(),
        ]);
    }
}
