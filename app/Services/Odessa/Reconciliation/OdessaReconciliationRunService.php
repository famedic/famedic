<?php

namespace App\Services\Odessa\Reconciliation;

use App\Models\OdessaReconciliationItem;
use App\Models\OdessaReconciliationItemAudit;
use App\Models\OdessaReconciliationItemNote;
use App\Models\OdessaReconciliationRun;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

class OdessaReconciliationRunService
{
    public function __construct(
        private readonly OdessaReconciliationService $reconciliationService,
    ) {}

    public function createRun(UploadedFile $sourceFile, ?UploadedFile $murguiaFile, User $user): OdessaReconciliationRun
    {
        $run = OdessaReconciliationRun::create([
            'status' => OdessaReconciliationRun::STATUS_PENDING,
            'uploaded_by' => $user->id,
            'source_filename' => $sourceFile->getClientOriginalName(),
            'murguia_filename' => $murguiaFile?->getClientOriginalName(),
            'source_path' => '',
            'started_at' => now(),
        ]);

        $basePath = "private/odessa-reconciliation/{$run->uuid}";
        $sourcePath = $sourceFile->storeAs($basePath, 'source.'.$sourceFile->getClientOriginalExtension(), 'local');
        $murguiaPath = $murguiaFile?->storeAs($basePath, 'murguia.'.$murguiaFile->getClientOriginalExtension(), 'local');
        $exportPath = "{$basePath}/reconciliation.xlsx";

        $run->update([
            'status' => OdessaReconciliationRun::STATUS_PROCESSING,
            'source_path' => $sourcePath,
            'murguia_path' => $murguiaPath,
            'export_path' => $exportPath,
        ]);

        try {
            $report = $this->reconciliationService->reconcile($sourcePath, $murguiaPath, $exportPath);
            $this->completeRun($run->fresh(), $report);
        } catch (\Throwable $exception) {
            $run->update([
                'status' => OdessaReconciliationRun::STATUS_FAILED,
                'error_message' => mb_substr($exception->getMessage(), 0, 4000),
                'completed_at' => now(),
            ]);

            throw $exception;
        }

        return $run->fresh(['items']);
    }

    public function updateReview(
        OdessaReconciliationRun $run,
        OdessaReconciliationItem $item,
        User $user,
        string $reviewStatus,
        ?string $comment = null,
    ): OdessaReconciliationItem {
        if ((int) $item->run_id !== (int) $run->id) {
            abort(404);
        }

        if (! in_array($reviewStatus, OdessaReconciliationItem::reviewStatuses(), true)) {
            throw new \InvalidArgumentException('Estado de revisión inválido.');
        }

        return DB::transaction(function () use ($item, $user, $reviewStatus, $comment) {
            $previous = $item->review_status;

            if ($previous !== $reviewStatus) {
                $item->update([
                    'review_status' => $reviewStatus,
                    'reviewed_by' => $user->id,
                    'reviewed_at' => now(),
                ]);

                OdessaReconciliationItemAudit::create([
                    'item_id' => $item->id,
                    'user_id' => $user->id,
                    'action' => OdessaReconciliationItemAudit::ACTION_REVIEW_STATUS_CHANGED,
                    'from_value' => $previous,
                    'to_value' => $reviewStatus,
                    'metadata_json' => [],
                    'created_at' => now(),
                ]);
            }

            if ($comment !== null && trim($comment) !== '') {
                OdessaReconciliationItemNote::create([
                    'item_id' => $item->id,
                    'user_id' => $user->id,
                    'note' => trim($comment),
                ]);

                OdessaReconciliationItemAudit::create([
                    'item_id' => $item->id,
                    'user_id' => $user->id,
                    'action' => OdessaReconciliationItemAudit::ACTION_NOTE_ADDED,
                    'metadata_json' => ['note' => trim($comment)],
                    'created_at' => now(),
                ]);
            }

            $item->run()->update([
                'pending_review_count' => OdessaReconciliationItem::query()
                    ->where('run_id', $item->run_id)
                    ->where('review_status', OdessaReconciliationItem::REVIEW_PENDING)
                    ->count(),
            ]);

            return $item->fresh(['notes.user', 'audits.user', 'reviewedBy']);
        });
    }

    public function archive(OdessaReconciliationRun $run, User $user): OdessaReconciliationRun
    {
        $run->update(['status' => OdessaReconciliationRun::STATUS_ARCHIVED]);

        $firstItem = $run->items()->first();
        if ($firstItem) {
            OdessaReconciliationItemAudit::create([
                'item_id' => $firstItem->id,
                'user_id' => $user->id,
                'action' => OdessaReconciliationItemAudit::ACTION_RUN_ARCHIVED,
                'metadata_json' => ['run_id' => $run->id],
                'created_at' => now(),
            ]);
        }

        return $run->fresh();
    }

    private function completeRun(OdessaReconciliationRun $run, OdessaReconciliationReport $report): void
    {
        $rows = array_map(fn (OdessaReconciliationResult $result) => $result->toArray(), $report->results);
        $uniqueRows = array_values(array_filter($rows, fn (array $row) => $row['is_duplicate'] !== 'Sí'));

        DB::transaction(function () use ($run, $report, $uniqueRows) {
            foreach ($uniqueRows as $row) {
                $item = OdessaReconciliationItem::create($this->itemAttributes($run, $row));
                OdessaReconciliationItemAudit::create([
                    'item_id' => $item->id,
                    'user_id' => $run->uploaded_by,
                    'action' => OdessaReconciliationItemAudit::ACTION_RUN_CREATED,
                    'metadata_json' => ['run_id' => $run->id],
                    'created_at' => now(),
                ]);
            }

            $summary = $this->summaryAttributes($report, $uniqueRows);
            $run->update(array_merge($summary, [
                'status' => OdessaReconciliationRun::STATUS_COMPLETED,
                'summary_json' => [
                    'match_types' => $report->summary->matchTypes,
                    'statuses' => $report->summary->statuses,
                    'membership_metrics' => $report->summary->membershipMetrics,
                    'email_metrics' => $report->summary->emailMetrics,
                    'murguia_statuses' => $report->summary->murguiaStatuses,
                    'murguia_audit_statuses' => $report->summary->murguiaAuditStatuses,
                    'audit_reasons' => $report->summary->auditReasons,
                    'source_actions' => $report->summary->sourceActions,
                    'source_action_statuses' => $report->summary->sourceActionStatuses,
                ],
                'completed_at' => now(),
                'error_message' => null,
            ]));
        });

        if (! Storage::disk('local')->exists($run->export_path)) {
            throw new \RuntimeException('La conciliación terminó, pero no se pudo conservar el XLSX histórico.');
        }
    }

    private function itemAttributes(OdessaReconciliationRun $run, array $row): array
    {
        $requiresReview = OdessaReconciliationItem::requiresManualReviewFromSnapshot($row);

        return [
            'run_id' => $run->id,
            'canonical_id' => $row['canonical_id'],
            'source_sheet' => $row['source_sheet'],
            'source_row' => $row['source_row'],
            'source_action' => $row['source_action'],
            'source_action_color' => $row['source_action_color'],
            'source_action_status' => $row['source_action_status'],
            'company_excel' => $row['company_excel'],
            'employee_excel' => $row['employee_excel'],
            'odessa_id_excel' => $row['odessa_id_excel'],
            'name_excel' => $row['name_excel'],
            'birth_date_excel' => $row['birth_date_excel'],
            'email_excel' => $row['email_excel'],
            'match_type' => $row['match_type'],
            'match_confidence' => $row['match_confidence'],
            'identity_status' => $row['identity_status'],
            'account_status' => $row['account_status'],
            'membership_status' => $row['membership_status'],
            'murguia_status' => $row['murguia_status'],
            'primary_status' => $row['final_status'],
            'data_quality_flags_json' => $this->splitList($row['data_quality_flags']),
            'audit_reason' => $row['audit_reason'],
            'review_notes_json' => $this->splitList($row['review_notes']),
            'user_id' => $row['user_id'],
            'customer_id' => $row['customer_id'],
            'odessa_account_id' => $row['odessa_account_id'],
            'odessa_id_db' => $row['odessa_id_db'],
            'company_external_db' => $row['company_external_id_db'],
            'partner_db' => $row['partner_identifier_db'],
            'name_db' => $row['name_db'],
            'birth_date_db' => $row['birth_date_db'],
            'email_db' => $row['email_db'],
            'medical_attention_identifier' => $row['medical_attention_identifier'],
            'subscription_id' => $row['subscription_id'],
            'subscription_start_date' => $row['subscription_start_date'],
            'subscription_end_date' => $row['subscription_end_date'],
            'subscription_status' => $row['subscription_status'],
            'last_murguia_sync_at' => $row['synced_with_murguia_at'],
            'evidence_json' => $this->splitList($row['evidence']),
            'snapshot_json' => $row,
            'review_status' => $requiresReview ? OdessaReconciliationItem::REVIEW_PENDING : OdessaReconciliationItem::REVIEW_NOT_APPLICABLE,
        ];
    }

    private function summaryAttributes(OdessaReconciliationReport $report, array $rows): array
    {
        $count = fn (callable $predicate): int => count(array_filter($rows, $predicate));

        return [
            'total_rows' => $report->summary->total,
            'unique_collaborators' => $report->summary->uniqueTotal,
            'confirmed_count' => $count(fn (array $row) => $row['exists_in_famedic'] === 'Sí' && $row['final_status'] !== OdessaReconciliationStatuses::MANUAL_REVIEW),
            'manual_review_count' => $report->summary->statuses[OdessaReconciliationStatuses::MANUAL_REVIEW] ?? 0,
            'not_found_count' => $report->summary->statuses[OdessaReconciliationStatuses::NOT_FOUND] ?? 0,
            'complete_count' => $report->summary->statuses[OdessaReconciliationStatuses::COMPLETE] ?? 0,
            'email_different_count' => $report->summary->emailMetrics['email_different'] ?? 0,
            'without_membership_count' => $report->summary->statuses[OdessaReconciliationStatuses::AFFILIATE_WITHOUT_MEMBERSHIP] ?? 0,
            'expired_membership_count' => $report->summary->statuses[OdessaReconciliationStatuses::EXPIRED_MEMBERSHIP] ?? 0,
            'famedic_and_murguia_count' => $report->summary->murguiaStatuses['FAMEDIC_Y_MURGUIA'] ?? 0,
            'famedic_not_murguia_count' => $report->summary->murguiaStatuses['FAMEDIC_NO_MURGUIA'] ?? 0,
            'murguia_not_famedic_count' => $report->summary->murguiaStatuses['MURGUIA_NO_FAMEDIC'] ?? 0,
            'pending_review_count' => $count(fn (array $row) => OdessaReconciliationItem::requiresManualReviewFromSnapshot($row)),
        ];
    }

    private function splitList(?string $value, string $separator = ';'): array
    {
        return array_values(array_filter(array_map('trim', explode($separator, (string) $value))));
    }
}
