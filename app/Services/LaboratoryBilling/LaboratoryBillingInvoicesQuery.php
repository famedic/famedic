<?php

namespace App\Services\LaboratoryBilling;

use App\Models\InvoiceRequest;
use App\Models\LaboratoryPurchase;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

class LaboratoryBillingInvoicesQuery
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
        $query = $this->metrics->requestsInRange($range)
            ->whereHasMorph(
                'invoiceRequestable',
                [LaboratoryPurchase::class],
                fn (Builder $purchaseQuery) => $purchaseQuery->withTrashed()->whereHas('invoice')
            );

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
                                    $inner->where('gda_order_id', 'like', "%{$search}%")
                                        ->orWhere('name', 'like', "%{$search}%")
                                        ->orWhere('id', $search);
                                });
                        }
                    );
            });
        }

        if (! empty($filters['document'])) {
            match ($filters['document']) {
                'complete' => $this->resolver->scopeComplete($query),
                'missing_pdf' => $query->whereHasMorph(
                    'invoiceRequestable',
                    [LaboratoryPurchase::class],
                    function (Builder $purchaseQuery) {
                        $purchaseQuery->withTrashed()->whereHas('invoice', function (Builder $q) {
                            $q->where(function (Builder $inner) {
                                $inner->whereNull('invoice')->orWhere('invoice', '');
                            })->whereNotNull('invoice_xml')->where('invoice_xml', '!=', '');
                        });
                    }
                ),
                'missing_xml' => $query->whereHasMorph(
                    'invoiceRequestable',
                    [LaboratoryPurchase::class],
                    function (Builder $purchaseQuery) {
                        $purchaseQuery->withTrashed()->whereHas('invoice', function (Builder $q) {
                            $q->whereNotNull('invoice')->where('invoice', '!=', '')
                                ->where(function (Builder $inner) {
                                    $inner->whereNull('invoice_xml')->orWhere('invoice_xml', '');
                                });
                        });
                    }
                ),
                'no_documents' => $query->whereHasMorph(
                    'invoiceRequestable',
                    [LaboratoryPurchase::class],
                    function (Builder $purchaseQuery) {
                        $purchaseQuery->withTrashed()->whereHas('invoice', function (Builder $q) {
                            $q->where(function (Builder $inner) {
                                $inner->whereNull('invoice')->orWhere('invoice', '');
                            })->where(function (Builder $inner) {
                                $inner->whereNull('invoice_xml')->orWhere('invoice_xml', '');
                            });
                        });
                    }
                ),
                default => null,
            };
        }

        return $query;
    }
}
