<?php

namespace App\Services\LaboratoryBilling\Reports;

use App\Enums\LaboratoryBillingStatus;
use App\Models\InvoiceRequest;
use App\Models\LaboratoryPurchase;
use App\Services\LaboratoryBilling\LaboratoryBillingPresenter;
use App\Services\LaboratoryBilling\LaboratoryBillingStatusResolver;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

class LaboratoryBillingReportDataService
{
    public function __construct(
        private LaboratoryBillingStatusResolver $resolver,
        private LaboratoryBillingPresenter $presenter,
        private LaboratoryBillingReportFormatter $formatter,
    ) {}

    public function build(array $period, array $filters = [], ?Carbon $asOf = null): array
    {
        $asOf = Carbon::parse($asOf ?? now())->timezone(LaboratoryBillingReportPeriodResolver::TIMEZONE);
        $periodStart = Carbon::parse($period['start_utc'])->utc();
        $periodEnd = Carbon::parse($period['end_utc'])->utc();

        $received = $this->periodCohortQuery($periodStart, $periodEnd, $filters, $asOf);
        $completed = $this->completedQuery($periodStart, $periodEnd, $filters, $asOf);
        $pending = $this->pendingQuery($periodStart, $periodEnd, $filters, $asOf);
        $overdue = $this->pendingQuery($periodStart, $periodEnd, $filters, $asOf);
        $this->resolver->scopeOverdue($overdue, $asOf);

        $receivedCount = (clone $received)->count();
        $completedCount = (clone $completed)->count();
        $pendingCount = (clone $pending)->count();
        $overdueCount = (clone $overdue)->count();

        $completedRows = $this->rowsFromQuery($completed);
        $pendingRows = $this->rowsFromQuery($pending);
        $overdueRows = $this->rowsFromQuery($overdue);
        $receivedRows = $this->rowsFromQuery($received);
        $detailCounts = [
            'received' => $receivedCount,
            'completed' => $completedCount,
            'backlog' => $pendingCount,
            'overdue' => $overdueCount,
        ];
        $detailExportedCounts = [
            'received' => $receivedRows->count(),
            'completed' => $completedRows->count(),
            'backlog' => $pendingRows->count(),
            'overdue' => $overdueRows->count(),
        ];

        $avgResponse = $completedRows
            ->pluck('billing.response_time_hours')
            ->filter(fn ($value) => $value !== null)
            ->avg();

        $averageResponseHours = $avgResponse !== null ? round((float) $avgResponse, 2) : null;
        $oldestPending = $pendingRows->sortBy('requested_at')->first();

        return [
            'period' => [
                ...$period,
                'name' => $this->formatter->periodName(data_get($filters, '_period_type')),
                'date_label' => $this->formatter->periodDateLabel(
                    Carbon::parse($period['start'])->timezone(LaboratoryBillingReportPeriodResolver::TIMEZONE),
                    Carbon::parse($period['end'])->timezone(LaboratoryBillingReportPeriodResolver::TIMEZONE),
                ),
            ],
            'generated_at' => $asOf->toIso8601String(),
            'backlog_as_of' => $asOf->toIso8601String(),
            'applied_filters' => $this->appliedFilters($filters),
            'metrics' => [
                // Fecha canónica de solicitud: invoice_requests.created_at en UTC, derivada del rango local America/Monterrey.
                'received' => $receivedCount,
                'completed' => $completedCount,
                'pending_period' => $pendingCount,
                'overdue_period' => $overdueCount,
                // Aliases legacy para configuraciones/historial existentes; ahora significan "del periodo".
                'pending_backlog' => $pendingCount,
                'overdue_backlog' => $overdueCount,
                'compliance_percent' => $receivedCount > 0 ? round(($completedCount / $receivedCount) * 100, 1) : 0.0,
                'average_response_hours' => $averageResponseHours,
                'average_response_duration' => $this->formatter->averageResponseDuration($averageResponseHours),
                'compliance_definition' => 'Solicitudes completadas del periodo / solicitudes recibidas del periodo.',
                'oldest_pending' => $oldestPending,
                'aging' => $this->agingBuckets($pendingRows),
                'missing_files' => $this->missingFileBuckets($pendingRows),
                'detail_row_limit' => $this->detailRowLimit(),
                'detail_counts' => $detailCounts,
                'detail_exported_counts' => $detailExportedCounts,
                'detail_total_rows' => array_sum($detailCounts),
                'detail_exported_rows' => array_sum($detailExportedCounts),
                'detail_truncated' => collect($detailCounts)
                    ->contains(fn (int $count, string $section) => ($detailExportedCounts[$section] ?? 0) < $count),
            ],
            'rows' => [
                'received' => $receivedRows->values(),
                'completed' => $completedRows->values(),
                'backlog' => $pendingRows->values(),
                'pending' => $pendingRows->values(),
                'overdue' => $overdueRows->values(),
            ],
        ];
    }

    public function receivedQuery(Carbon $fromUtc, Carbon $toUtc, array $filters = [], ?Carbon $asOf = null): Builder
    {
        return $this->periodCohortQuery($fromUtc, $toUtc, $filters, $asOf);
    }

    public function completedQuery(Carbon $fromUtc, Carbon $toUtc, array $filters = [], ?Carbon $asOf = null): Builder
    {
        $query = $this->periodCohortQuery($fromUtc, $toUtc, $filters, $asOf)
            ->whereHasMorph('invoiceRequestable', [LaboratoryPurchase::class], function (Builder $purchaseQuery) {
                $purchaseQuery->whereHas('invoice', function (Builder $invoiceQuery) {
                    $invoiceQuery
                        ->whereNotNull('invoice')
                        ->where('invoice', '!=', '')
                        ->whereNotNull('invoice_xml')
                        ->where('invoice_xml', '!=', '');
                });
            });

        return $query;
    }

    public function pendingQuery(Carbon $fromUtc, Carbon $toUtc, array $filters = [], ?Carbon $asOf = null): Builder
    {
        return $this->periodCohortQuery($fromUtc, $toUtc, $filters, $asOf)
            ->where(function (Builder $query) {
                $query->whereHasMorph(
                    'invoiceRequestable',
                    [LaboratoryPurchase::class],
                    fn (Builder $purchaseQuery) => $purchaseQuery->whereDoesntHave('invoice')
                )->orWhereHasMorph(
                    'invoiceRequestable',
                    [LaboratoryPurchase::class],
                    fn (Builder $purchaseQuery) => $purchaseQuery->whereHas('invoice', function (Builder $invoiceQuery) {
                        $invoiceQuery->where(function (Builder $documents) {
                            $documents
                                ->whereNull('invoice')
                                ->orWhere('invoice', '')
                                ->orWhereNull('invoice_xml')
                                ->orWhere('invoice_xml', '');
                        });
                    })
                );
            });
    }

    public function backlogQuery(Carbon $asOf, array $filters = []): Builder
    {
        return $this->baseQuery($filters, $asOf)
            ->where('created_at', '<=', $asOf->copy()->utc())
            ->where(function (Builder $query) {
                $query->whereHasMorph(
                    'invoiceRequestable',
                    [LaboratoryPurchase::class],
                    fn (Builder $purchaseQuery) => $purchaseQuery->whereDoesntHave('invoice')
                )->orWhereHasMorph(
                    'invoiceRequestable',
                    [LaboratoryPurchase::class],
                    fn (Builder $purchaseQuery) => $purchaseQuery->whereHas('invoice', function (Builder $invoiceQuery) {
                        $invoiceQuery
                            ->whereNull('invoice')
                            ->orWhere('invoice', '')
                            ->orWhereNull('invoice_xml')
                            ->orWhere('invoice_xml', '');
                    })
                );
            });
    }

    private function periodCohortQuery(Carbon $fromUtc, Carbon $toUtc, array $filters = [], ?Carbon $asOf = null): Builder
    {
        return $this->baseQuery($filters, $asOf)->whereBetween('created_at', [$fromUtc, $toUtc]);
    }

    private function baseQuery(array $filters, ?Carbon $asOf = null): Builder
    {
        $query = InvoiceRequest::query()->forActiveLaboratoryPurchases();

        if (filled($filters['brand'] ?? null)) {
            $query->whereHasMorph(
                'invoiceRequestable',
                [LaboratoryPurchase::class],
                fn (Builder $purchaseQuery) => $purchaseQuery->where('brand', $filters['brand'])
            );
        }

        if (filled($filters['laboratory_store_id'] ?? null)) {
            $query->whereHasMorph(
                'invoiceRequestable',
                [LaboratoryPurchase::class],
                function (Builder $purchaseQuery) use ($filters) {
                    if (($filters['laboratory_store_id'] ?? null) === '__none__') {
                        $purchaseQuery->where(function (Builder $query) {
                            $query->whereDoesntHave('laboratoryAppointment')
                                ->orWhereHas(
                                    'laboratoryAppointment',
                                    fn (Builder $appointmentQuery) => $appointmentQuery->whereNull('laboratory_store_id')
                                );
                        });

                        return;
                    }

                    $purchaseQuery->whereHas(
                        'laboratoryAppointment',
                        fn (Builder $appointmentQuery) => $appointmentQuery->where('laboratory_store_id', $filters['laboratory_store_id'])
                    );
                }
            );
        }

        if (filled($filters['status'] ?? null)) {
            $this->applyStatusFilter($query, (string) $filters['status'], $asOf);
        }

        return $query;
    }

    private function rowsFromQuery(Builder $query): Collection
    {
        return $query
            ->with([
                'taxProfile' => fn ($q) => $q->withTrashed(),
                'invoiceRequestable' => fn ($morph) => $morph->with([
                    'invoice',
                    'customer.user',
                    'laboratoryAppointment.laboratoryStore',
                ]),
            ])
            ->orderBy('created_at')
            ->limit($this->detailRowLimit())
            ->get()
            ->map(function (InvoiceRequest $request) {
                $row = $this->presenter->presentRequest($request);
                /** @var LaboratoryPurchase|null $purchase */
                $purchase = $request->invoiceRequestable;
                $store = $purchase?->laboratoryAppointment?->laboratoryStore;

                $row['purchase']['store'] = $store ? [
                    'id' => $store->id,
                    'name' => $store->name,
                    'state' => $store->state,
                ] : [
                    'id' => null,
                    'name' => 'Sin sucursal',
                    'state' => null,
                ];
                $row['missing_files'] = $this->missingFilesLabel($row);

                return $row;
            });
    }

    private function agingBuckets(Collection $rows): array
    {
        $buckets = [
            'within_sla' => 0,
            'overdue_1_3' => 0,
            'overdue_4_7' => 0,
            'overdue_more_7' => 0,
        ];

        foreach ($rows as $row) {
            $days = (int) data_get($row, 'billing.days_overdue', 0);
            if ($days <= 0) {
                $buckets['within_sla']++;
            } elseif ($days <= 3) {
                $buckets['overdue_1_3']++;
            } elseif ($days <= 7) {
                $buckets['overdue_4_7']++;
            } else {
                $buckets['overdue_more_7']++;
            }
        }

        return $buckets;
    }

    private function missingFileBuckets(Collection $rows): array
    {
        return [
            'missing_pdf' => $rows->filter(fn ($row) => ! data_get($row, 'billing.has_pdf') && data_get($row, 'billing.has_xml'))->count(),
            'missing_xml' => $rows->filter(fn ($row) => data_get($row, 'billing.has_pdf') && ! data_get($row, 'billing.has_xml'))->count(),
            'missing_both' => $rows->filter(fn ($row) => ! data_get($row, 'billing.has_pdf') && ! data_get($row, 'billing.has_xml'))->count(),
        ];
    }

    private function missingFilesLabel(array $row): string
    {
        $missing = [];
        if (! data_get($row, 'billing.has_pdf')) {
            $missing[] = 'PDF';
        }
        if (! data_get($row, 'billing.has_xml')) {
            $missing[] = 'XML';
        }

        return $missing === [] ? 'Ninguno' : implode(', ', $missing);
    }

    private function applyStatusFilter(Builder $query, string $status, ?Carbon $asOf = null): void
    {
        $now = $asOf ?? now(LaboratoryBillingReportPeriodResolver::TIMEZONE);

        match ($status) {
            LaboratoryBillingStatus::Pending->value => $this->resolver->scopePending($query, $now),
            LaboratoryBillingStatus::InProgress->value => $this->resolver->scopeInProgress($query, $now),
            LaboratoryBillingStatus::Completed->value => $this->resolver->scopeComplete($query),
            LaboratoryBillingStatus::Overdue->value => $this->resolver->scopeOverdue($query, $now),
            default => null,
        };
    }

    private function detailRowLimit(): int
    {
        return max(100, (int) config('famedic.laboratory_billing.report_detail_row_limit', 5000));
    }

    private function appliedFilters(array $filters): array
    {
        return collect($filters)
            ->reject(fn ($value, string $key) => str_starts_with($key, '_') || blank($value))
            ->all();
    }
}
