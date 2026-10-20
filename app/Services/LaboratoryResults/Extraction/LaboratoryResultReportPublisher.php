<?php

namespace App\Services\LaboratoryResults\Extraction;

use App\Enums\LaboratoryResultEventType;
use App\Enums\LaboratoryResultStructuredStatus;
use App\Models\LaboratoryResultEvent;
use App\Models\LaboratoryResultReport;
use Illuminate\Support\Facades\DB;
use RuntimeException;

class LaboratoryResultReportPublisher
{
    public function publish(LaboratoryResultReport $report): LaboratoryResultReport
    {
        if ($report->structured_status === LaboratoryResultStructuredStatus::Published) {
            return $report;
        }

        if ($report->observations()->count() === 0) {
            throw new RuntimeException('Cannot publish a report without observations.');
        }

        if ($report->laboratory_result_version_id === null) {
            throw new RuntimeException('Cannot publish a report without a document version.');
        }

        return DB::transaction(function () use ($report): LaboratoryResultReport {
            $report = LaboratoryResultReport::query()->lockForUpdate()->findOrFail($report->id);
            $versionId = (int) $report->laboratory_result_version_id;

            $activePublished = LaboratoryResultReport::query()
                ->where('published_version_slot', $versionId)
                ->lockForUpdate()
                ->first();

            if ($activePublished && $activePublished->id !== $report->id) {
                $activePublished->update([
                    'structured_status' => LaboratoryResultStructuredStatus::Superseded,
                    'superseded_at' => now(),
                    'superseded_by_report_id' => $report->id,
                    'published_version_slot' => null,
                ]);

                $this->recordEvent(
                    $report,
                    LaboratoryResultEventType::StructureSuperseded,
                    [
                        'superseded_report_id' => $activePublished->id,
                        'new_report_id' => $report->id,
                    ],
                );
            }

            $report->update([
                'structured_status' => LaboratoryResultStructuredStatus::Published,
                'published_at' => now(),
                'published_version_slot' => $versionId,
            ]);

            $this->recordEvent(
                $report->fresh(),
                LaboratoryResultEventType::StructurePublished,
                [
                    'report_id' => $report->id,
                    'version_id' => $versionId,
                    'observation_count' => $report->observation_count,
                ],
            );

            return $report->fresh(['observations']);
        });
    }

    /**
     * @param  array<string, mixed>  $metadata
     */
    private function recordEvent(LaboratoryResultReport $report, LaboratoryResultEventType $eventType, array $metadata): void
    {
        $statusId = $report->resultVersion?->laboratory_result_status_id;

        if ($statusId === null) {
            return;
        }

        LaboratoryResultEvent::query()->create([
            'laboratory_result_status_id' => $statusId,
            'laboratory_result_version_id' => $report->laboratory_result_version_id,
            'event_type' => $eventType,
            'metadata' => $metadata,
            'created_at' => now(),
        ]);
    }
}
