<?php

namespace App\Services\LaboratoryBilling;

use App\Enums\InvoiceRequestWorkflowStatus;
use App\Models\InvoiceRequest;
use App\Models\LaboratoryPurchase;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;

class LaboratoryBillingAwaitingSampleQuery
{
    public function __construct(
        private LaboratoryBillingPresenter $presenter,
        private LaboratoryBillingMetricsService $metrics,
    ) {}

    public function count(): int
    {
        return $this->baseQuery()->count();
    }

    public function paginate(array $filters, LaboratoryBillingDateRange $range, int $perPage = 25): LengthAwarePaginator
    {
        $query = $this->filteredQuery($filters, $range);

        $paginator = $query
            ->with([
                'taxProfile' => fn ($q) => $q->withTrashed(),
                'invoiceRequestable' => fn ($morph) => $morph->with([
                    'laboratoryPurchaseItems',
                    'customer.user',
                ]),
            ])
            ->latest('created_at')
            ->paginate($perPage)
            ->withQueryString();

        $paginator->setCollection(
            $paginator->getCollection()->map(
                fn (InvoiceRequest $request) => $this->presenter->presentAwaitingSampleRequest($request)
            )
        );

        return $paginator;
    }

    public function filteredQuery(array $filters, LaboratoryBillingDateRange $range): Builder
    {
        $query = $this->baseQuery()
            ->whereBetween('created_at', [$range->from->clone()->utc(), $range->to->clone()->utc()]);

        $search = trim((string) ($filters['search'] ?? ''));
        if ($search !== '') {
            $query->where(function (Builder $q) use ($search) {
                $q->where('name', 'like', "%{$search}%")
                    ->orWhere('rfc', 'like', "%{$search}%")
                    ->orWhereHasMorph(
                        'invoiceRequestable',
                        [LaboratoryPurchase::class],
                        function (Builder $purchaseQuery) use ($search) {
                            $purchaseQuery->where(function (Builder $inner) use ($search) {
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

        if (! empty($filters['brand'])) {
            $brand = $filters['brand'];
            $query->whereHasMorph(
                'invoiceRequestable',
                [LaboratoryPurchase::class],
                fn (Builder $purchaseQuery) => $purchaseQuery->where('brand', $brand)
            );
        }

        return $query;
    }

    private function baseQuery(): Builder
    {
        return $this->metrics->baseRequestsQuery()
            ->where('workflow_status', InvoiceRequestWorkflowStatus::AwaitingSampleCollection->value);
    }
}
