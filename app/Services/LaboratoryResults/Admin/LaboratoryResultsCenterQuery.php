<?php

namespace App\Services\LaboratoryResults\Admin;

use App\Enums\LaboratoryResultEventType;
use App\Enums\LaboratoryResultExtractionStatus;
use App\Enums\LaboratoryResultStatus as LaboratoryResultStatusEnum;
use App\Enums\LaboratoryResultStructuredStatus;
use App\Models\LaboratoryPurchase;
use Carbon\Carbon;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;

class LaboratoryResultsCenterQuery
{
    /**
     * @param  array<string, mixed>  $filters
     */
    public function paginate(array $filters): LengthAwarePaginator
    {
        return LaboratoryPurchase::query()
            ->withTrashed()
            ->select([
                'id',
                'customer_id',
                'gda_order_id',
                'gda_consecutivo',
                'results',
                'name',
                'paternal_lastname',
                'maternal_lastname',
                'created_at',
                'updated_at',
                'deleted_at',
            ])
            ->with($this->listRelations())
            ->withCount([
                'laboratoryResultStatuses',
                'resultReports',
                'resultReports as published_result_reports_count' => fn (Builder $query) => $query
                    ->where('structured_status', LaboratoryResultStructuredStatus::Published->value)
                    ->whereNotNull('published_version_slot'),
            ])
            ->tap(fn (Builder $query) => $this->applyFilters($query, $filters))
            ->latest('laboratory_purchases.created_at')
            ->paginate(25)
            ->withQueryString();
    }

    public function detail(LaboratoryPurchase $purchase): LaboratoryPurchase
    {
        return $purchase->load([
            'laboratoryPurchaseItems:id,laboratory_purchase_id,gda_id,name',
            'laboratoryResultStatuses' => fn ($query) => $query
                ->select([
                    'id',
                    'laboratory_purchase_id',
                    'laboratory_purchase_item_id',
                    'status',
                    'first_available_at',
                    'interpreted_at',
                    'last_checked_at',
                    'next_check_at',
                    'check_attempts',
                    'completed_notified_at',
                    'created_at',
                    'updated_at',
                ])
                ->with([
                    'laboratoryPurchaseItem:id,name,gda_id',
                    'versions' => fn ($versionQuery) => $versionQuery
                        ->select([
                            'id',
                            'laboratory_result_status_id',
                            'laboratory_notification_id',
                            'storage_path',
                            'sha256',
                            'source',
                            'classification',
                            'classification_reason',
                            'matched_rule',
                            'classifier',
                            'classified_at',
                            'pdf_available_at',
                            'created_at',
                            'updated_at',
                        ])
                        ->latest('id')
                        ->with([
                            'resultReports' => fn ($reportQuery) => $reportQuery
                                ->select([
                                    'id',
                                    'laboratory_purchase_id',
                                    'laboratory_result_version_id',
                                    'source',
                                    'extraction_method',
                                    'extraction_status',
                                    'structured_status',
                                    'reported_at',
                                    'specimen_collected_at',
                                    'confidence_overall',
                                    'observation_count',
                                    'validation_errors',
                                    'published_at',
                                    'superseded_at',
                                    'superseded_by_report_id',
                                    'ai_execution_id',
                                    'input_hash',
                                    'extractor_version',
                                    'prompt_version',
                                    'published_version_slot',
                                    'created_at',
                                    'updated_at',
                                ])
                                ->with([
                                    'aiExecution:id,status,duration_ms,prompt_tokens,completion_tokens,total_tokens,estimated_cost_usd,error,created_at,updated_at',
                                    'observations' => fn ($observationQuery) => $observationQuery
                                        ->select([
                                            'id',
                                            'laboratory_result_report_id',
                                            'analyte_code',
                                            'analyte_name_raw',
                                            'analyte_name_display',
                                            'reference_status',
                                            'extraction_method',
                                            'confidence',
                                            'source_page',
                                            'created_at',
                                        ])
                                        ->with([
                                            'aiExplanations' => fn ($explanationQuery) => $explanationQuery
                                                ->select([
                                                    'id',
                                                    'laboratory_result_observation_id',
                                                    'status',
                                                    'ai_execution_id',
                                                    'generated_at',
                                                    'created_at',
                                                    'updated_at',
                                                ])
                                                ->with('aiExecution:id,status,duration_ms,prompt_tokens,completion_tokens,total_tokens,estimated_cost_usd,error,created_at,updated_at')
                                                ->latest('id'),
                                        ]),
                                ])
                                ->latest('id'),
                        ]),
                    'events' => fn ($eventQuery) => $eventQuery
                        ->select([
                            'id',
                            'laboratory_result_status_id',
                            'laboratory_result_version_id',
                            'event_type',
                            'from_status',
                            'to_status',
                            'metadata',
                            'created_at',
                        ])
                        ->latest('created_at')
                        ->limit(50),
                ]),
            'laboratoryPurchaseItems:id,laboratory_purchase_id,gda_id,name',
            'resultReports' => fn ($reportQuery) => $reportQuery
                ->select([
                    'id',
                    'laboratory_purchase_id',
                    'laboratory_result_version_id',
                    'source',
                    'extraction_method',
                    'extraction_status',
                    'structured_status',
                    'confidence_overall',
                    'observation_count',
                    'validation_errors',
                    'published_at',
                    'ai_execution_id',
                    'input_hash',
                    'extractor_version',
                    'prompt_version',
                    'published_version_slot',
                    'created_at',
                    'updated_at',
                ])
                ->with('aiExecution:id,status,duration_ms,prompt_tokens,completion_tokens,total_tokens,estimated_cost_usd,error,created_at,updated_at')
                ->latest('id'),
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function listRelations(): array
    {
        return [
            'laboratoryResultStatuses' => fn ($query) => $query
                ->select([
                    'id',
                    'laboratory_purchase_id',
                    'laboratory_purchase_item_id',
                    'status',
                    'last_checked_at',
                    'next_check_at',
                    'check_attempts',
                    'created_at',
                    'updated_at',
                ])
                ->with([
                    'versions' => fn ($versionQuery) => $versionQuery
                        ->select([
                            'id',
                            'laboratory_result_status_id',
                            'storage_path',
                            'sha256',
                            'source',
                            'classification',
                            'classified_at',
                            'pdf_available_at',
                            'created_at',
                            'updated_at',
                        ])
                        ->latest('id'),
                    'events' => fn ($eventQuery) => $eventQuery
                        ->select([
                            'id',
                            'laboratory_result_status_id',
                            'laboratory_result_version_id',
                            'event_type',
                            'metadata',
                            'created_at',
                        ])
                        ->latest('created_at')
                        ->limit(5),
                ]),
            'resultReports' => fn ($reportQuery) => $reportQuery
                ->select([
                    'id',
                    'laboratory_purchase_id',
                    'laboratory_result_version_id',
                    'extraction_method',
                    'extraction_status',
                    'structured_status',
                    'confidence_overall',
                    'observation_count',
                    'validation_errors',
                    'published_at',
                    'ai_execution_id',
                    'published_version_slot',
                    'created_at',
                    'updated_at',
                ])
                ->with([
                    'aiExecution:id,status,duration_ms,prompt_tokens,completion_tokens,total_tokens,estimated_cost_usd,error,created_at,updated_at',
                    'observations:id,laboratory_result_report_id',
                    'observations.aiExplanations:id,laboratory_result_observation_id,status,ai_execution_id,generated_at,created_at,updated_at',
                ])
                ->latest('id'),
        ];
    }

    /**
     * @param  array<string, mixed>  $filters
     */
    private function applyFilters(Builder $query, array $filters): void
    {
        $query
            ->when($filters['purchase_id'] ?? null, fn (Builder $q, $id) => $q->where('laboratory_purchases.id', (int) $id))
            ->when($filters['folio'] ?? null, function (Builder $q, $folio) {
                $value = trim((string) $folio);
                $q->where(function (Builder $folioQuery) use ($value) {
                    $folioQuery
                        ->where('laboratory_purchases.gda_order_id', 'like', "%{$value}%")
                        ->orWhere('laboratory_purchases.gda_consecutivo', 'like', "%{$value}%");
                });
            })
            ->when($filters['date_from'] ?? null, fn (Builder $q, $date) => $q->where('laboratory_purchases.created_at', '>=', Carbon::parse((string) $date)->startOfDay()))
            ->when($filters['date_to'] ?? null, fn (Builder $q, $date) => $q->where('laboratory_purchases.created_at', '<=', Carbon::parse((string) $date)->endOfDay()))
            ->when($filters['extraction_status'] ?? null, fn (Builder $q, $status) => $q->whereHas('resultReports', fn (Builder $reportQuery) => $reportQuery->where('extraction_status', (string) $status)))
            ->when($filters['structured_status'] ?? null, fn (Builder $q, $status) => $q->whereHas('resultReports', fn (Builder $reportQuery) => $reportQuery->where('structured_status', (string) $status)))
            ->when($filters['extraction_method'] ?? null, fn (Builder $q, $method) => $q->whereHas('resultReports', fn (Builder $reportQuery) => $reportQuery->where('extraction_method', (string) $method)))
            ->when($filters['ai_explanation_status'] ?? null, fn (Builder $q, $status) => $q->whereHas(
                'resultReports.observations.aiExplanations',
                fn (Builder $explanationQuery) => $explanationQuery->where('status', (string) $status),
            ))
            ->when(($filters['has_errors'] ?? '') !== '', function (Builder $q) use ($filters) {
                $hasErrors = filter_var($filters['has_errors'], FILTER_VALIDATE_BOOLEAN);

                if ($hasErrors) {
                    $q->where(fn (Builder $errorQuery) => $this->whereHasOperationalErrors($errorQuery));

                    return;
                }

                $q->where(fn (Builder $errorQuery) => $this->whereDoesntHaveOperationalErrors($errorQuery));
            })
            ->when($filters['status'] ?? null, fn (Builder $q, $status) => $this->applyDerivedStatusFilter($q, strtoupper((string) $status)));
    }

    private function applyDerivedStatusFilter(Builder $query, string $status): void
    {
        match ($status) {
            'PUBLISHED' => $query->whereHas('resultReports', fn (Builder $q) => $q
                ->where('structured_status', LaboratoryResultStructuredStatus::Published->value)
                ->whereNotNull('published_version_slot')),
            'FAILED' => $query->where(fn (Builder $q) => $this->whereHasOperationalErrors($q)),
            'REVIEW' => $query->where(function (Builder $q) {
                $q->whereHas('laboratoryResultStatuses', fn (Builder $statusQuery) => $statusQuery->where('status', LaboratoryResultStatusEnum::ManualReview->value))
                    ->orWhereHas('resultReports', fn (Builder $reportQuery) => $reportQuery
                        ->whereIn('extraction_status', [
                            LaboratoryResultExtractionStatus::ManualReview->value,
                            LaboratoryResultExtractionStatus::Partial->value,
                        ])
                        ->orWhereNotNull('validation_errors'));
            }),
            'PROCESSING' => $query->where(function (Builder $q) {
                $q->whereHas('laboratoryResultStatuses', fn (Builder $statusQuery) => $statusQuery->where('status', LaboratoryResultStatusEnum::AvailableUnchecked->value))
                    ->orWhereHas('resultReports', fn (Builder $reportQuery) => $reportQuery->whereIn('extraction_status', [
                        LaboratoryResultExtractionStatus::Pending->value,
                        LaboratoryResultExtractionStatus::Processing->value,
                    ]));
            }),
            'STRUCTURED' => $query->whereHas('resultReports', fn (Builder $q) => $q
                ->whereIn('structured_status', [
                    LaboratoryResultStructuredStatus::Draft->value,
                    LaboratoryResultStructuredStatus::Validated->value,
                ])
                ->where('observation_count', '>', 0)),
            'RECEIVED' => $query
                ->whereHas('laboratoryResultStatuses.versions')
                ->whereDoesntHave('resultReports'),
            'NOT_AVAILABLE' => $query->where(function (Builder $q) {
                $q->whereDoesntHave('laboratoryResultStatuses')
                    ->orWhere(function (Builder $statusQuery) {
                        $statusQuery
                            ->whereHas('laboratoryResultStatuses', fn (Builder $s) => $s->where('status', LaboratoryResultStatusEnum::NotAvailable->value))
                            ->whereDoesntHave('laboratoryResultStatuses.versions');
                    });
            }),
            default => null,
        };
    }

    private function whereHasOperationalErrors(Builder $query): void
    {
        $query
            ->whereHas('laboratoryResultStatuses', fn (Builder $statusQuery) => $statusQuery->where('status', LaboratoryResultStatusEnum::Error->value))
            ->orWhereHas('resultReports', fn (Builder $reportQuery) => $reportQuery
                ->where('extraction_status', LaboratoryResultExtractionStatus::Failed->value)
                ->orWhereNotNull('validation_errors'))
            ->orWhereHas('laboratoryResultStatuses.events', fn (Builder $eventQuery) => $eventQuery->where('event_type', LaboratoryResultEventType::ExtractionFailed->value))
            ->orWhereHas('resultReports.aiExecution', fn (Builder $executionQuery) => $executionQuery->where('status', 'failed'))
            ->orWhereHas('resultReports.observations.aiExplanations', fn (Builder $explanationQuery) => $explanationQuery->whereIn('status', ['failed', 'invalid']));
    }

    private function whereDoesntHaveOperationalErrors(Builder $query): void
    {
        $query
            ->whereDoesntHave('laboratoryResultStatuses', fn (Builder $statusQuery) => $statusQuery->where('status', LaboratoryResultStatusEnum::Error->value))
            ->whereDoesntHave('resultReports', fn (Builder $reportQuery) => $reportQuery
                ->where('extraction_status', LaboratoryResultExtractionStatus::Failed->value)
                ->orWhereNotNull('validation_errors'))
            ->whereDoesntHave('laboratoryResultStatuses.events', fn (Builder $eventQuery) => $eventQuery->where('event_type', LaboratoryResultEventType::ExtractionFailed->value))
            ->whereDoesntHave('resultReports.aiExecution', fn (Builder $executionQuery) => $executionQuery->where('status', 'failed'))
            ->whereDoesntHave('resultReports.observations.aiExplanations', fn (Builder $explanationQuery) => $explanationQuery->whereIn('status', ['failed', 'invalid']));
    }
}
