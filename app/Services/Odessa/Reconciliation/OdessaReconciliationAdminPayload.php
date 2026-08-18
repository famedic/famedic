<?php

namespace App\Services\Odessa\Reconciliation;

use App\Models\OdessaReconciliationItem;
use App\Models\OdessaReconciliationRun;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

class OdessaReconciliationAdminPayload
{
    public function index(LengthAwarePaginator $runs): array
    {
        return [
            'data' => $runs->getCollection()->map(fn (OdessaReconciliationRun $run) => [
                'id' => $run->id,
                'uuid' => $run->uuid,
                'status' => $run->status,
                'source_filename' => $run->source_filename,
                'murguia_filename' => $run->murguia_filename,
                'uploaded_by' => $run->uploadedBy?->full_name ?? $run->uploadedBy?->email,
                'unique_collaborators' => $run->unique_collaborators,
                'confirmed_count' => $run->confirmed_count,
                'manual_review_count' => $run->manual_review_count,
                'not_found_count' => $run->not_found_count,
                'pending_review_count' => $run->pending_review_count,
                'created_at' => $run->created_at?->toDateTimeString(),
                'completed_at' => $run->completed_at?->toDateTimeString(),
                'show_url' => route('admin.odessa.reconciliations.show', $run, absolute: false),
                'export_url' => route('admin.odessa.reconciliations.export', $run, absolute: false),
            ])->values(),
            'current_page' => $runs->currentPage(),
            'last_page' => $runs->lastPage(),
            'per_page' => $runs->perPage(),
            'total' => $runs->total(),
            'from' => $runs->firstItem(),
            'to' => $runs->lastItem(),
            'links' => $runs->linkCollection()->toArray(),
        ];
    }

    public function fromRun(OdessaReconciliationRun $run): array
    {
        $run->loadMissing(['uploadedBy', 'items.notes.user', 'items.audits.user', 'items.actions.performedBy', 'items.reviewedBy']);
        $items = $run->items->sortBy(fn (OdessaReconciliationItem $item) => $this->itemPriority($item))->values();
        $rows = $items->map(fn (OdessaReconciliationItem $item) => $this->itemRow($item))->values();

        return [
            'meta' => [
                'id' => $run->id,
                'uuid' => $run->uuid,
                'status' => $run->status,
                'source_filename' => $run->source_filename,
                'murguia_filename' => $run->murguia_filename,
                'generated_at' => $run->completed_at?->toDateTimeString(),
                'uploaded_by' => $run->uploadedBy?->full_name ?? $run->uploadedBy?->email,
                'export_path' => $run->export_path,
            ],
            'summary' => $this->runSummary($run),
            'filters' => $this->runFilters($items),
            'filter_counts' => $this->filterCounts($rows->all()),
            'operation_views' => $this->operationViews($rows->all()),
            'rows' => $rows,
            'export' => [
                'path' => $run->export_path,
                'filename' => 'odessa-conciliacion-'.$run->uuid.'.xlsx',
                'url' => route('admin.odessa.reconciliations.export', $run, absolute: false),
            ],
            'review_statuses' => OdessaReconciliationItem::reviewStatuses(),
        ];
    }

    public function fromReport(
        OdessaReconciliationReport $report,
        string $sourceFilename,
        ?string $murguiaFilename,
        string $exportPath,
    ): array {
        $rows = array_map(fn (OdessaReconciliationResult $result) => $result->toArray(), $report->results);
        $uniqueRows = array_values(array_filter($rows, fn (array $row) => $row['is_duplicate'] !== 'Sí'));
        usort($uniqueRows, fn (array $left, array $right) => $this->priority($left) <=> $this->priority($right));
        $payloadRows = array_map(fn (array $row) => $this->row($row), $uniqueRows);

        return [
            'meta' => [
                'source_filename' => $sourceFilename,
                'murguia_filename' => $murguiaFilename,
                'generated_at' => now()->toDateTimeString(),
                'export_path' => $exportPath,
            ],
            'summary' => $this->summary($report, $uniqueRows),
            'filters' => $this->filters($uniqueRows),
            'filter_counts' => $this->filterCounts($payloadRows),
            'operation_views' => $this->operationViews($payloadRows),
            'rows' => $payloadRows,
            'export' => [
                'path' => $exportPath,
                'filename' => basename($exportPath),
                'url' => route('admin.odessa.reconciliations.index', absolute: false),
            ],
        ];
    }

    private function runSummary(OdessaReconciliationRun $run): array
    {
        return [
            'total_rows' => $run->total_rows,
            'unique_collaborators' => $run->unique_collaborators,
            'duplicates' => max(0, $run->total_rows - $run->unique_collaborators),
            'confirmed' => $run->confirmed_count,
            'manual_review' => $run->manual_review_count,
            'not_found' => $run->not_found_count,
            'complete' => $run->complete_count,
            'email_different' => $run->email_different_count,
            'without_membership' => $run->without_membership_count,
            'expired_membership' => $run->expired_membership_count,
            'famedic_and_murguia' => $run->famedic_and_murguia_count,
            'famedic_no_murguia' => $run->famedic_not_murguia_count,
            'murguia_no_famedic' => $run->murguia_not_famedic_count,
            'pending_review' => $run->pending_review_count,
            'possible_duplicates' => $run->items->filter(fn (OdessaReconciliationItem $item) => $this->hasDuplicateFlag($item->data_quality_flags_json ?? []))->count(),
            'without_credit_number' => $run->items->filter(fn (OdessaReconciliationItem $item) => ! $item->medical_attention_identifier)->count(),
            'altas' => $run->items()->where('source_action', 'ALTA')->count(),
            'bajas' => $run->items()->where('source_action', 'BAJA')->count(),
            'sin_accion' => $run->items()->where('source_action', 'NONE')->count(),
            'acciones_pendientes' => $run->items()->whereIn('source_action_status', ['PENDING_ACTIVATION', 'PENDING_DEACTIVATION'])->count(),
            'acciones_procesadas' => $run->items()->whereIn('source_action_status', ['ACTIVATED', 'DEACTIVATED', 'ALREADY_ACTIVE', 'ALREADY_INACTIVE'])->count(),
            'acciones_bloqueadas' => $run->items()->where('source_action_status', 'BLOCKED')->count(),
            'acciones_error' => $run->items()->where('source_action_status', 'FAILED')->count(),
            ...($run->summary_json ?? []),
        ];
    }

    private function itemRow(OdessaReconciliationItem $item): array
    {
        return [
            'id' => $item->id,
            'source' => [
                'sheet' => $item->source_sheet,
                'row' => $item->source_row,
                'action' => $item->source_action ?? ($item->snapshot_json['source_action'] ?? 'NONE'),
                'action_color' => $item->source_action_color ?? ($item->snapshot_json['source_action_color'] ?? null),
                'name' => $item->name_excel,
                'birth_date' => $item->birth_date_excel?->toDateString(),
                'email' => $item->email_excel,
                'company' => $item->company_excel,
                'employee' => $item->employee_excel,
                'odessa_id' => $item->odessa_id_excel,
            ],
            'match' => [
                'type' => $item->match_type,
                'label' => $this->matchLabel($item->match_type),
                'confidence' => $item->match_confidence,
                'evidence' => $item->evidence_json ?? [],
                'candidate_count' => 0,
                'candidates' => [],
            ],
            'famedic' => [
                'exists' => $item->user_id !== null || $item->customer_id !== null,
                'customer_exists' => $item->customer_id !== null,
                'odessa_exists' => $item->odessa_account_id !== null,
                'user_id' => $item->user_id,
                'customer_id' => $item->customer_id,
                'odessa_account_id' => $item->odessa_account_id,
                'name' => $item->name_db,
                'email' => $item->email_db,
                'birth_date' => $item->birth_date_db?->toDateString(),
                'odessa_id' => $item->odessa_id_db,
                'company_internal_id' => $item->snapshot_json['company_internal_id'] ?? null,
                'company' => $item->company_external_db,
                'employee' => $item->partner_db,
                'customer_url' => $item->customer_id
                    ? route('admin.customers.show', ['customer' => $item->customer_id], absolute: false)
                    : null,
                'user_url' => $item->user_id
                    ? route('admin.users.show', ['user' => $item->user_id], absolute: false)
                    : null,
            ],
            'membership' => [
                'identifier' => $item->medical_attention_identifier,
                'number_label' => 'noCredito / medical_attention_identifier',
                'subscription_id' => $item->subscription_id,
                'type' => $item->snapshot_json['subscription_type'] ?? null,
                'start_date' => $item->subscription_start_date?->toDateTimeString(),
                'end_date' => $item->subscription_end_date?->toDateTimeString(),
                'status' => $item->subscription_status,
                'status_label' => $this->membershipLabel($item->subscription_status),
                'last_sync' => $item->last_murguia_sync_at?->toDateTimeString(),
                'count' => $item->snapshot_json['subscription_count'] ?? null,
            ],
            'dimensions' => [
                'identity_status' => $item->identity_status,
                'account_status' => $item->account_status,
                'membership_status' => $item->membership_status,
                'murguia_status' => $item->murguia_status,
                'murguia_audit_status' => $item->snapshot_json['murguia_audit_status'] ?? null,
                'source_action_status' => $item->source_action_status ?? ($item->snapshot_json['source_action_status'] ?? 'NO_ACTION'),
                'blocked_reasons' => $this->snapshotList($item->snapshot_json['source_action_blocked_reasons'] ?? null),
                'final_status' => $item->primary_status,
                'audit_reason' => $item->audit_reason,
                'flags' => $item->data_quality_flags_json ?? [],
                'flag_labels' => $this->flagLabels($item->data_quality_flags_json ?? []),
            ],
            'comparisons' => [
                'email_status' => $item->snapshot_json['email_status'] ?? null,
                'email_matches' => $item->snapshot_json['email_matches'] ?? null,
                'name_match' => $item->snapshot_json['name_match'] ?? null,
                'paternal_lastname_match' => $item->snapshot_json['paternal_lastname_match'] ?? null,
                'maternal_lastname_match' => $item->snapshot_json['maternal_lastname_match'] ?? null,
                'full_name_match' => $item->snapshot_json['full_name_match'] ?? null,
                'birth_date_match' => $item->snapshot_json['birth_date_match'] ?? null,
                'company_matches' => $item->snapshot_json['company_matches'] ?? null,
                'partner_matches' => $item->snapshot_json['partner_matches'] ?? null,
                'odessa_id_matches' => $item->snapshot_json['odessa_id_matches'] ?? null,
            ],
            'murguia' => [
                'exists_in_report' => $item->snapshot_json['exists_in_murguia_report'] ?? null,
                'exists' => $item->murguia_status === 'FAMEDIC_Y_MURGUIA' || $item->murguia_status === 'MURGUIA_NO_FAMEDIC',
                'status' => $item->murguia_status,
                'audit_status' => $item->snapshot_json['murguia_audit_status'] ?? null,
                'last_log_id' => $item->snapshot_json['last_murguia_log_id'] ?? null,
                'last_log_action' => $item->snapshot_json['last_murguia_log_action'] ?? null,
                'last_log_status' => $item->snapshot_json['last_murguia_log_status'] ?? null,
                'last_log_email' => $item->snapshot_json['last_murguia_log_email'] ?? null,
                'last_log_date' => $item->snapshot_json['last_murguia_log_date'] ?? null,
                'last_error' => $item->snapshot_json['last_murguia_log_status'] === 'failed'
                    ? ($item->snapshot_json['last_murguia_log_message'] ?? null)
                    : null,
            ],
            'review' => [
                'status' => $item->review_status,
                'reviewed_by' => $item->reviewedBy?->full_name ?? $item->reviewedBy?->email,
                'reviewed_at' => $item->reviewed_at?->toDateTimeString(),
                'update_url' => route('admin.odessa.reconciliations.items.review', [$item->run_id, $item], absolute: false),
                'notes' => $item->notes->map(fn ($note) => [
                    'note' => $note->note,
                    'user' => $note->user?->full_name ?? $note->user?->email,
                    'created_at' => $note->created_at?->toDateTimeString(),
                ])->values(),
                'audits' => $item->audits->map(fn ($audit) => [
                    'action' => $audit->action,
                    'from_value' => $audit->from_value,
                    'to_value' => $audit->to_value,
                    'user' => $audit->user?->full_name ?? $audit->user?->email,
                    'created_at' => $audit->created_at?->toDateTimeString(),
                ])->values(),
            ],
            'actions' => [
                'enabled' => (bool) config('famedic.odessa_reconciliation_actions.enabled', false),
                'resolution_status' => $item->resolution_status,
                'resolved_flags' => $item->resolved_flags_json ?? [],
                'preview_url_template' => route('admin.odessa.reconciliations.items.actions.preview', [$item->run_id, $item, '__ACTION__'], absolute: false),
                'execute_url_template' => route('admin.odessa.reconciliations.items.actions.execute', [$item->run_id, $item, '__ACTION__'], absolute: false),
                'items' => $item->actions->map(fn ($action) => [
                    'action_type' => $action->action_type,
                    'status' => $action->status,
                    'target_type' => $action->target_type,
                    'target_id' => $action->target_id,
                    'before' => $action->before_json ?? [],
                    'after' => $action->after_json ?? [],
                    'result' => $action->result_json ?? [],
                    'reason' => $action->reason,
                    'error_message' => $action->error_message,
                    'performed_by' => $action->performedBy?->full_name ?? $action->performedBy?->email,
                    'performed_at' => $action->performed_at?->toDateTimeString(),
                    'created_at' => $action->created_at?->toDateTimeString(),
                ])->values(),
            ],
            'review_notes' => $item->review_notes_json ?? [],
            'search_text' => mb_strtolower(implode(' ', array_filter([
                $item->name_excel,
                $item->email_excel,
                $item->email_db,
                $item->odessa_id_excel,
                $item->employee_excel,
                $item->user_id,
                $item->customer_id,
                $item->medical_attention_identifier,
                $item->source_action,
                $item->source_action_status,
            ]))),
        ];
    }

    private function summary(OdessaReconciliationReport $report, array $rows): array
    {
        $count = fn (callable $predicate): int => count(array_filter($rows, $predicate));

        return [
            'total_rows' => $report->summary->total,
            'unique_collaborators' => $report->summary->uniqueTotal,
            'duplicates' => $report->summary->duplicates,
            'confirmed' => $count(fn (array $row) => $row['exists_in_famedic'] === 'Sí' && $row['final_status'] !== OdessaReconciliationStatuses::MANUAL_REVIEW),
            'manual_review' => $report->summary->statuses[OdessaReconciliationStatuses::MANUAL_REVIEW] ?? 0,
            'not_found' => $report->summary->statuses[OdessaReconciliationStatuses::NOT_FOUND] ?? 0,
            'complete' => $report->summary->statuses[OdessaReconciliationStatuses::COMPLETE] ?? 0,
            'email_different' => $report->summary->emailMetrics['email_different'] ?? 0,
            'without_membership' => $report->summary->statuses[OdessaReconciliationStatuses::AFFILIATE_WITHOUT_MEMBERSHIP] ?? 0,
            'expired_membership' => $report->summary->statuses[OdessaReconciliationStatuses::EXPIRED_MEMBERSHIP] ?? 0,
            'famedic_and_murguia' => $report->summary->murguiaStatuses['FAMEDIC_Y_MURGUIA'] ?? 0,
            'famedic_no_murguia' => $report->summary->murguiaStatuses['FAMEDIC_NO_MURGUIA'] ?? 0,
            'murguia_no_famedic' => $report->summary->murguiaStatuses['MURGUIA_NO_FAMEDIC'] ?? 0,
            'match_types' => $report->summary->matchTypes,
            'statuses' => $report->summary->statuses,
            'membership_metrics' => $report->summary->membershipMetrics,
            'email_metrics' => $report->summary->emailMetrics,
            'murguia_statuses' => $report->summary->murguiaStatuses,
            'murguia_audit_statuses' => $report->summary->murguiaAuditStatuses,
            'audit_reasons' => $report->summary->auditReasons,
            'source_actions' => $report->summary->sourceActions,
            'source_action_statuses' => $report->summary->sourceActionStatuses,
            'altas' => $report->summary->sourceActions['ALTA'] ?? 0,
            'bajas' => $report->summary->sourceActions['BAJA'] ?? 0,
            'sin_accion' => $report->summary->sourceActions['NONE'] ?? 0,
            'acciones_pendientes' => ($report->summary->sourceActionStatuses['PENDING_ACTIVATION'] ?? 0) + ($report->summary->sourceActionStatuses['PENDING_DEACTIVATION'] ?? 0),
            'acciones_procesadas' => ($report->summary->sourceActionStatuses['ACTIVATED'] ?? 0) + ($report->summary->sourceActionStatuses['DEACTIVATED'] ?? 0) + ($report->summary->sourceActionStatuses['ALREADY_ACTIVE'] ?? 0) + ($report->summary->sourceActionStatuses['ALREADY_INACTIVE'] ?? 0),
            'acciones_bloqueadas' => $report->summary->sourceActionStatuses['BLOCKED'] ?? 0,
            'acciones_error' => $report->summary->sourceActionStatuses['FAILED'] ?? 0,
            'possible_duplicates' => $count(fn (array $row) => $this->hasDuplicateFlag($this->splitList($row['data_quality_flags'] ?? ''))),
            'without_credit_number' => $count(fn (array $row) => ! ($row['medical_attention_identifier'] ?? null)),
        ];
    }

    private function row(array $row): array
    {
        return [
            'id' => $row['canonical_id'],
            'source' => [
                'sheet' => $row['source_sheet'],
                'row' => $row['source_row'],
                'action' => $row['source_action'] ?? 'NONE',
                'action_color' => $row['source_action_color'] ?? null,
                'name' => $row['name_excel'],
                'birth_date' => $row['birth_date_excel'],
                'email' => $row['email_excel'],
                'company' => $row['company_excel'],
                'employee' => $row['employee_excel'],
                'odessa_id' => $row['odessa_id_excel'],
            ],
            'match' => [
                'type' => $row['match_type'],
                'label' => $this->matchLabel($row['match_type']),
                'confidence' => $row['match_confidence'],
                'evidence' => $this->splitList($row['evidence']),
                'candidate_count' => (int) ($row['candidate_count'] ?? 0),
                'candidates' => $this->splitList($row['candidates'], '|'),
            ],
            'famedic' => [
                'exists' => $row['exists_in_famedic'] === 'Sí',
                'customer_exists' => $row['customer_id'] !== null,
                'odessa_exists' => $row['odessa_account_id'] !== null,
                'user_id' => $row['user_id'],
                'customer_id' => $row['customer_id'],
                'odessa_account_id' => $row['odessa_account_id'],
                'name' => $row['name_db'],
                'email' => $row['email_db'],
                'birth_date' => $row['birth_date_db'],
                'odessa_id' => $row['odessa_id_db'],
                'company_internal_id' => $row['company_internal_id'],
                'company' => $row['company_external_id_db'],
                'employee' => $row['partner_identifier_db'],
                'customer_url' => $row['customer_id']
                    ? route('admin.customers.show', ['customer' => $row['customer_id']], absolute: false)
                    : null,
                'user_url' => $row['user_id']
                    ? route('admin.users.show', ['user' => $row['user_id']], absolute: false)
                    : null,
            ],
            'membership' => [
                'identifier' => $row['medical_attention_identifier'],
                'number_label' => 'noCredito / medical_attention_identifier',
                'subscription_id' => $row['subscription_id'],
                'type' => $row['subscription_type'],
                'start_date' => $row['subscription_start_date'],
                'end_date' => $row['subscription_end_date'],
                'status' => $row['subscription_status'],
                'status_label' => $this->membershipLabel($row['subscription_status']),
                'last_sync' => $row['synced_with_murguia_at'],
                'count' => $row['subscription_count'],
            ],
            'dimensions' => [
                'identity_status' => $row['identity_status'],
                'account_status' => $row['account_status'],
                'membership_status' => $row['membership_status'],
                'murguia_status' => $row['murguia_status'],
                'murguia_audit_status' => $row['murguia_audit_status'],
                'source_action_status' => $row['source_action_status'] ?? 'NO_ACTION',
                'blocked_reasons' => $this->splitList($row['source_action_blocked_reasons'] ?? null),
                'final_status' => $row['final_status'],
                'audit_reason' => $row['audit_reason'],
                'flags' => $this->splitList($row['data_quality_flags']),
                'flag_labels' => $this->flagLabels($this->splitList($row['data_quality_flags'])),
            ],
            'comparisons' => [
                'email_status' => $row['email_status'],
                'email_matches' => $row['email_matches'],
                'name_match' => $row['name_match'],
                'paternal_lastname_match' => $row['paternal_lastname_match'],
                'maternal_lastname_match' => $row['maternal_lastname_match'],
                'full_name_match' => $row['full_name_match'],
                'birth_date_match' => $row['birth_date_match'],
                'company_matches' => $row['company_matches'],
                'partner_matches' => $row['partner_matches'],
                'odessa_id_matches' => $row['odessa_id_matches'],
            ],
            'murguia' => [
                'exists_in_report' => $row['exists_in_murguia_report'],
                'exists' => $row['murguia_status'] === 'FAMEDIC_Y_MURGUIA' || $row['murguia_status'] === 'MURGUIA_NO_FAMEDIC',
                'status' => $row['murguia_status'],
                'audit_status' => $row['murguia_audit_status'],
                'last_log_id' => $row['last_murguia_log_id'],
                'last_log_action' => $row['last_murguia_log_action'],
                'last_log_status' => $row['last_murguia_log_status'],
                'last_log_email' => $row['last_murguia_log_email'],
                'last_log_date' => $row['last_murguia_log_date'],
                'last_error' => $row['last_murguia_log_status'] === 'failed'
                    ? ($row['last_murguia_log_message'] ?? null)
                    : null,
            ],
            'review_notes' => $this->splitList($row['review_notes']),
            'search_text' => mb_strtolower(implode(' ', array_filter([
                $row['name_excel'],
                $row['email_excel'],
                $row['email_db'],
                $row['odessa_id_excel'],
                $row['employee_excel'],
                $row['user_id'],
                $row['customer_id'],
                $row['medical_attention_identifier'],
                $row['source_action'] ?? null,
                $row['source_action_status'] ?? null,
            ]))),
        ];
    }

    private function filters(array $rows): array
    {
        return [
            'match_types' => $this->options($rows, 'match_type'),
            'membership_statuses' => $this->options($rows, 'subscription_status'),
            'murguia_statuses' => $this->options($rows, 'murguia_status'),
            'account_statuses' => $this->options($rows, 'account_status'),
            'source_actions' => $this->options($rows, 'source_action'),
            'source_action_statuses' => $this->options($rows, 'source_action_status'),
            'flags' => array_values(array_unique(array_filter(array_merge(...array_map(
                fn (array $row) => $this->splitList($row['data_quality_flags']),
                $rows,
            ))))),
            'companies' => $this->options($rows, 'company_excel'),
        ];
    }

    private function runFilters($items): array
    {
        return [
            'match_types' => $items->pluck('match_type')->filter()->unique()->sort()->values(),
            'membership_statuses' => $items->pluck('subscription_status')->filter()->unique()->sort()->values(),
            'murguia_statuses' => $items->pluck('murguia_status')->filter()->unique()->sort()->values(),
            'account_statuses' => $items->pluck('account_status')->filter()->unique()->sort()->values(),
            'review_statuses' => $items->pluck('review_status')->filter()->unique()->sort()->values(),
            'source_actions' => $items->pluck('source_action')->filter()->unique()->sort()->values(),
            'source_action_statuses' => $items->pluck('source_action_status')->filter()->unique()->sort()->values(),
            'flags' => $items->flatMap(fn (OdessaReconciliationItem $item) => $item->data_quality_flags_json ?? [])->filter()->unique()->sort()->values(),
            'companies' => $items->pluck('company_excel')->filter()->unique()->sort()->values(),
        ];
    }

    private function itemPriority(OdessaReconciliationItem $item): int
    {
        return $this->operationalPriority(
            $item->source_action_status ?? ($item->snapshot_json['source_action_status'] ?? 'NO_ACTION'),
            $item->review_status,
            $item->primary_status,
            $item->data_quality_flags_json ?? [],
            $item->snapshot_json['email_status'] ?? null,
        );
    }

    private function legacyItemPriority(OdessaReconciliationItem $item): int
    {
        if ($item->review_status === OdessaReconciliationItem::REVIEW_PENDING) {
            return 0;
        }
        if ($item->primary_status === OdessaReconciliationStatuses::MANUAL_REVIEW) {
            return 10;
        }
        if ($item->primary_status === OdessaReconciliationStatuses::NOT_FOUND) {
            return 20;
        }
        if (in_array('DISCREPANCIA_IDENTITY', $item->data_quality_flags_json ?? [], true)) {
            return 30;
        }
        if ($item->murguia_status === 'FAMEDIC_NO_MURGUIA') {
            return 40;
        }
        if ($item->primary_status === OdessaReconciliationStatuses::AFFILIATE_WITHOUT_MEMBERSHIP) {
            return 50;
        }
        if (($item->snapshot_json['email_status'] ?? null) === 'email_different') {
            return 60;
        }

        return 70;
    }

    private function options(array $rows, string $key): array
    {
        $values = array_values(array_unique(array_filter(array_map(fn (array $row) => $row[$key] ?? null, $rows))));
        sort($values);

        return $values;
    }

    private function splitList(?string $value, string $separator = ';'): array
    {
        return array_values(array_filter(array_map('trim', explode($separator, (string) $value))));
    }

    private function snapshotList(mixed $value): array
    {
        if (is_array($value)) {
            return array_values(array_filter($value));
        }

        return $this->splitList(is_string($value) ? $value : null);
    }

    /** @param list<array<string, mixed>> $rows */
    private function filterCounts(array $rows): array
    {
        $count = fn (callable $predicate): int => count(array_filter($rows, $predicate));

        return [
            'all' => count($rows),
            'altas' => $count(fn ($row) => $row['source']['action'] === 'ALTA'),
            'bajas' => $count(fn ($row) => $row['source']['action'] === 'BAJA'),
            'sin_accion' => $count(fn ($row) => $row['source']['action'] === 'NONE'),
            'pending' => $count(fn ($row) => in_array($row['dimensions']['source_action_status'], ['PENDING_ACTIVATION', 'PENDING_DEACTIVATION'], true)),
            'processed' => $count(fn ($row) => in_array($row['dimensions']['source_action_status'], ['ACTIVATED', 'DEACTIVATED', 'ALREADY_ACTIVE', 'ALREADY_INACTIVE', 'COMPLETED'], true)),
            'blocked' => $count(fn ($row) => $row['dimensions']['source_action_status'] === 'BLOCKED'),
            'errors' => $count(fn ($row) => $row['dimensions']['source_action_status'] === 'FAILED' || $row['murguia']['audit_status'] === 'MURGUIA_SYNC_ERROR'),
            'email_different' => $count(fn ($row) => $row['comparisons']['email_status'] === 'email_different'),
            'without_number' => $count(fn ($row) => ! $row['membership']['identifier']),
            'not_found' => $count(fn ($row) => $row['dimensions']['final_status'] === OdessaReconciliationStatuses::NOT_FOUND),
            'not_found_murguia' => $count(fn ($row) => $row['murguia']['status'] === 'FAMEDIC_NO_MURGUIA'),
            'possible_duplicates' => $count(fn ($row) => $this->hasDuplicateFlag($row['dimensions']['flags'] ?? [])),
        ];
    }

    /** @param list<array<string, mixed>> $rows */
    private function operationViews(array $rows): array
    {
        return [
            'altas' => $this->operationView($rows, 'ALTA'),
            'bajas' => $this->operationView($rows, 'BAJA'),
        ];
    }

    /** @param list<array<string, mixed>> $rows */
    private function operationView(array $rows, string $action): array
    {
        $filtered = array_values(array_filter($rows, fn ($row) => $row['source']['action'] === $action));
        $statuses = array_count_values(array_map(fn ($row) => $row['dimensions']['source_action_status'], $filtered));

        return [
            'total' => count($filtered),
            'ready' => ($statuses['PENDING_ACTIVATION'] ?? 0) + ($statuses['PENDING_DEACTIVATION'] ?? 0),
            'already_ok' => ($statuses['ALREADY_ACTIVE'] ?? 0) + ($statuses['ALREADY_INACTIVE'] ?? 0),
            'blocked' => $statuses['BLOCKED'] ?? 0,
            'error' => $statuses['FAILED'] ?? 0,
            'without_credit_number' => count(array_filter($filtered, fn ($row) => ! $row['membership']['identifier'])),
            'email_different' => count(array_filter($filtered, fn ($row) => $row['comparisons']['email_status'] === 'email_different')),
        ];
    }

    /** @param list<string> $flags */
    private function hasDuplicateFlag(array $flags): bool
    {
        return array_intersect($flags, [
            'POSSIBLE_DUPLICATE_PERSON',
            'POSSIBLE_EXISTING_USER',
            'DUPLICATE_ODESSA_ID',
            'DUPLICATE_COMPANY_PARTNER',
            'DUPLICATE_MEMBERSHIP_IDENTIFIER',
        ]) !== [];
    }

    /** @param list<string> $flags @return list<string> */
    private function flagLabels(array $flags): array
    {
        $labels = [
            'EMAIL_DIFFERENT' => 'Correo diferente en FAMEDIC',
            'POSSIBLE_DUPLICATE_PERSON' => 'Posible persona duplicada',
            'POSSIBLE_EXISTING_USER' => 'Posible usuario existente',
            'DUPLICATE_ODESSA_ID' => 'ID ODESSA duplicado',
            'DUPLICATE_COMPANY_PARTNER' => 'Empresa + empleado duplicado',
            'DUPLICATE_MEMBERSHIP_IDENTIFIER' => 'noCredito duplicado',
            'DISCREPANCIA_IDENTITY' => 'Discrepancia de identidad',
            'SUBSCRIPTION_WITHOUT_IDENTIFIER' => 'Suscripción sin noCredito',
            'IDENTIFIER_WITHOUT_SUBSCRIPTION' => 'noCredito sin suscripción activa',
        ];

        return array_values(array_map(fn (string $flag) => $labels[$flag] ?? $flag, $flags));
    }

    private function priority(array $row): int
    {
        return $this->operationalPriority(
            $row['source_action_status'] ?? 'NO_ACTION',
            OdessaReconciliationItem::requiresManualReviewFromSnapshot($row)
                ? OdessaReconciliationItem::REVIEW_PENDING
                : OdessaReconciliationItem::REVIEW_NOT_APPLICABLE,
            $row['final_status'] ?? null,
            $this->splitList($row['data_quality_flags'] ?? ''),
            $row['email_status'] ?? null,
        );
    }

    private function legacyPriority(array $row): int
    {
        if ($row['final_status'] === OdessaReconciliationStatuses::MANUAL_REVIEW) {
            return 10;
        }
        if ($row['final_status'] === OdessaReconciliationStatuses::NOT_FOUND) {
            return 20;
        }
        if (str_contains((string) $row['data_quality_flags'], 'DISCREPANCIA_IDENTITY')) {
            return 30;
        }
        if ($row['murguia_status'] === 'FAMEDIC_NO_MURGUIA') {
            return 40;
        }
        if ($row['final_status'] === OdessaReconciliationStatuses::AFFILIATE_WITHOUT_MEMBERSHIP) {
            return 50;
        }
        if (($row['email_status'] ?? null) === 'email_different') {
            return 60;
        }

        return 70;
    }

    /** @param list<string> $flags */
    private function operationalPriority(?string $actionStatus, ?string $reviewStatus, ?string $finalStatus, array $flags, ?string $emailStatus): int
    {
        if ($actionStatus === 'FAILED') {
            return 0;
        }
        if ($actionStatus === 'BLOCKED') {
            return 10;
        }
        if ($actionStatus === 'PENDING_DEACTIVATION') {
            return 20;
        }
        if ($actionStatus === 'PENDING_ACTIVATION') {
            return 30;
        }
        if ($reviewStatus === OdessaReconciliationItem::REVIEW_PENDING || $finalStatus === OdessaReconciliationStatuses::MANUAL_REVIEW) {
            return 40;
        }
        if ($emailStatus === 'email_different' || in_array('EMAIL_DIFFERENT', $flags, true)) {
            return 50;
        }

        return 60;
    }

    private function matchLabel(?string $type): string
    {
        return match ($type) {
            OdessaReconciliationMatchTypes::CONFIRMED_ODESSA_ID => 'ID ODESSA',
            OdessaReconciliationMatchTypes::CONFIRMED_COMPANY_PARTNER => 'Empresa + socio',
            OdessaReconciliationMatchTypes::CONFIRMED_MEMBERSHIP => 'Membresía',
            OdessaReconciliationMatchTypes::CONFIRMED_EMAIL => 'Correo',
            OdessaReconciliationMatchTypes::PROBABLE_IDENTITY => 'Identidad probable',
            OdessaReconciliationMatchTypes::DELETED => 'Registro eliminado',
            OdessaReconciliationMatchTypes::AMBIGUOUS => 'Ambiguo',
            default => 'Sin match',
        };
    }

    private function membershipLabel(?string $status): string
    {
        return match ($status) {
            'ACTIVE' => 'Activa',
            'EXPIRED' => 'Vencida',
            'FUTURE' => 'Futura',
            'DELETED_ONLY' => 'Eliminada/Histórica',
            'MISSING' => 'Sin membresía',
            default => 'Sin datos',
        };
    }
}
