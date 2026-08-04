<?php

namespace App\Services\LaboratoryBilling;

use App\Enums\LaboratoryBrand;
use App\Enums\LaboratoryBillingStatus;
use App\Models\InvoiceRequest;
use App\Models\LaboratoryPurchase;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

class LaboratoryBillingRequestsQuery
{
    public function __construct(
        private LaboratoryBillingStatusResolver $resolver,
        private LaboratoryBillingPresenter $presenter,
        private LaboratoryBillingMetricsService $metrics,
    ) {}

    public function paginate(array $filters, LaboratoryBillingDateRange $range, int $perPage = 25): LengthAwarePaginator
    {
        $query = $this->filteredQuery($filters, $range);

        $paginator = $query
            ->with([
                'taxProfile' => fn ($q) => $q->withTrashed(),
                'invoiceRequestable' => fn ($morph) => $morph->withTrashed()->with([
                    'invoice',
                    'customer.user',
                ]),
            ])
            ->latest('created_at')
            ->paginate($perPage)
            ->withQueryString();

        $paginator->setCollection(
            $paginator->getCollection()->map(
                fn (InvoiceRequest $request) => $this->presenter->presentRequest($request)
            )
        );

        return $paginator;
    }

    public function statusCounts(array $filters, LaboratoryBillingDateRange $range): array
    {
        $baseFilters = $filters;
        unset($baseFilters['status']);

        $all = $this->filteredQuery($baseFilters, $range)->count();

        $counts = ['all' => $all];
        foreach (LaboratoryBillingStatus::cases() as $status) {
            $statusFilters = array_merge($baseFilters, ['status' => $status->value]);
            $counts[$status->value] = $this->filteredQuery($statusFilters, $range)->count();
        }

        return $counts;
    }

    public function exportRows(array $filters, LaboratoryBillingDateRange $range): Collection
    {
        return $this->filteredQuery($filters, $range)
            ->with([
                'taxProfile' => fn ($q) => $q->withTrashed(),
                'invoiceRequestable' => fn ($morph) => $morph->withTrashed()->with([
                    'invoice',
                    'customer.user',
                ]),
            ])
            ->latest('created_at')
            ->limit(5000)
            ->get()
            ->map(fn (InvoiceRequest $request) => $this->presenter->presentRequest($request));
    }

    public function filteredQuery(array $filters, LaboratoryBillingDateRange $range): Builder
    {
        $query = $this->metrics->requestsInRange($range);

        $search = trim((string) ($filters['search'] ?? ''));
        if ($search !== '') {
            $query->where(function (Builder $q) use ($search) {
                $q->where('name', 'like', "%{$search}%")
                    ->orWhere('rfc', 'like', "%{$search}%")
                    ->orWhereHasMorph(
                        'invoiceRequestable',
                        [LaboratoryPurchase::class],
                        function (Builder $purchaseQuery) use ($search) {
                            $purchaseQuery->withTrashed()
                                ->where(function (Builder $inner) use ($search) {
                                    $inner->where('name', 'like', "%{$search}%")
                                        ->orWhere('paternal_lastname', 'like', "%{$search}%")
                                        ->orWhere('maternal_lastname', 'like', "%{$search}%")
                                        ->orWhere('gda_order_id', 'like', "%{$search}%")
                                        ->orWhere('id', $search);
                                })
                                ->orWhereHas('customer.user', function (Builder $userQuery) use ($search) {
                                    $userQuery->where('name', 'like', "%{$search}%")
                                        ->orWhere('paternal_lastname', 'like', "%{$search}%")
                                        ->orWhere('maternal_lastname', 'like', "%{$search}%")
                                        ->orWhere('email', 'like', "%{$search}%");
                                });
                        }
                    );
            });
        }

        if (! empty($filters['status'])) {
            $this->applyStatusFilter($query, (string) $filters['status']);
        }

        if (($filters['overdue'] ?? null) === 'true' || ($filters['overdue'] ?? null) === '1') {
            $this->resolver->scopeOverdue($query);
        }

        if (! empty($filters['document'])) {
            $this->applyDocumentFilter($query, (string) $filters['document']);
        }

        if (! empty($filters['tax_profile_id'])) {
            $query->where('tax_profile_id', $filters['tax_profile_id']);
        }

        if (! empty($filters['customer_id'])) {
            $query->whereHasMorph(
                'invoiceRequestable',
                [LaboratoryPurchase::class],
                function (Builder $purchaseQuery) use ($filters) {
                    $purchaseQuery->withTrashed()->where('customer_id', $filters['customer_id']);
                }
            );
        }

        if (! empty($filters['brand'])) {
            $brand = $filters['brand'];
            $query->whereHasMorph(
                'invoiceRequestable',
                [LaboratoryPurchase::class],
                function (Builder $purchaseQuery) use ($brand) {
                    $purchaseQuery->withTrashed()->where('brand', $brand);
                }
            );
        }

        return $query;
    }

    private function applyStatusFilter(Builder $query, string $status): void
    {
        $now = now('America/Monterrey');

        match ($status) {
            LaboratoryBillingStatus::Pending->value => $this->resolver->scopePending($query, $now),
            LaboratoryBillingStatus::InProgress->value => $this->resolver->scopeInProgress($query, $now),
            LaboratoryBillingStatus::Completed->value => $this->resolver->scopeComplete($query),
            LaboratoryBillingStatus::Overdue->value => $this->resolver->scopeOverdue($query, $now),
            default => null,
        };
    }

    private function applyDocumentFilter(Builder $query, string $document): void
    {
        match ($document) {
            'with_pdf' => $query->whereHasMorph(
                'invoiceRequestable',
                [LaboratoryPurchase::class],
                fn (Builder $purchaseQuery) => $purchaseQuery->withTrashed()->whereHas(
                    'invoice',
                    fn (Builder $q) => $q->whereNotNull('invoice')->where('invoice', '!=', '')
                )
            ),
            'without_pdf' => $query->where(function (Builder $q) {
                $q->whereHasMorph(
                    'invoiceRequestable',
                    [LaboratoryPurchase::class],
                    fn (Builder $purchaseQuery) => $purchaseQuery->withTrashed()->whereDoesntHave('invoice')
                )->orWhereHasMorph(
                    'invoiceRequestable',
                    [LaboratoryPurchase::class],
                    fn (Builder $purchaseQuery) => $purchaseQuery->withTrashed()->whereHas(
                        'invoice',
                        fn (Builder $iq) => $iq->whereNull('invoice')->orWhere('invoice', '')
                    )
                );
            }),
            'with_xml' => $query->whereHasMorph(
                'invoiceRequestable',
                [LaboratoryPurchase::class],
                fn (Builder $purchaseQuery) => $purchaseQuery->withTrashed()->whereHas(
                    'invoice',
                    fn (Builder $q) => $q->whereNotNull('invoice_xml')->where('invoice_xml', '!=', '')
                )
            ),
            'without_xml' => $query->where(function (Builder $q) {
                $q->whereHasMorph(
                    'invoiceRequestable',
                    [LaboratoryPurchase::class],
                    fn (Builder $purchaseQuery) => $purchaseQuery->withTrashed()->whereDoesntHave('invoice')
                )->orWhereHasMorph(
                    'invoiceRequestable',
                    [LaboratoryPurchase::class],
                    fn (Builder $purchaseQuery) => $purchaseQuery->withTrashed()->whereHas(
                        'invoice',
                        fn (Builder $iq) => $iq->whereNull('invoice_xml')->orWhere('invoice_xml', '')
                    )
                );
            }),
            'complete' => $this->resolver->scopeComplete($query),
            'incomplete' => $this->resolver->scopeNotComplete($query),
            default => null,
        };
    }

    public function brandOptions(): array
    {
        return collect(LaboratoryBrand::cases())
            ->map(fn (LaboratoryBrand $brand) => [
                'value' => $brand->value,
                'label' => $brand->label(),
            ])
            ->values()
            ->all();
    }
}
