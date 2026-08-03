<?php

namespace App\Services\LaboratoryBilling;

use App\Models\InvoiceRequest;
use App\Models\LaboratoryPurchase;
use App\Models\TaxProfile;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

class LaboratoryBillingTaxProfilesQuery
{
    public function __construct(
        private LaboratoryBillingPresenter $presenter,
        private LaboratoryBillingMetricsService $metricsService,
    ) {}

    public function paginate(array $filters, LaboratoryBillingDateRange $range, int $perPage = 25): LengthAwarePaginator
    {
        $query = $this->filteredQuery($filters, $range);

        $labType = LaboratoryPurchase::class;

        $paginator = $query
            ->with(['customer.user'])
            ->withCount([
                'invoiceRequests as invoice_requests_count' => fn (Builder $q) => $q->withTrashed()
                    ->where('invoice_requestable_type', $labType),
            ])
            ->withMax([
                'invoiceRequests as last_used_at' => fn (Builder $q) => $q->withTrashed()
                    ->where('invoice_requestable_type', $labType),
            ], 'created_at')
            ->latest('created_at')
            ->paginate($perPage)
            ->withQueryString();

        $paginator->setCollection(
            $paginator->getCollection()->map(function (TaxProfile $profile) {
                $presented = $this->presenter->presentTaxProfile($profile);
                $presented['last_used_at'] = $profile->last_used_at
                    ? Carbon::parse($profile->last_used_at)->toIso8601String()
                    : null;
                $presented['formatted_last_used_at'] = $profile->last_used_at
                    ? localizedDate(Carbon::parse($profile->last_used_at))?->isoFormat('D MMM Y h:mm a')
                    : '—';
                $presented['invoices_count'] = (int) $profile->invoice_requests_count;
                $presented['usage_status'] = ((int) $profile->invoice_requests_count) > 0 ? 'used' : 'unused';

                return $presented;
            })
        );

        return $paginator;
    }

    public function exportRows(array $filters, LaboratoryBillingDateRange $range): Collection
    {
        $labType = LaboratoryPurchase::class;

        return $this->filteredQuery($filters, $range)
            ->with(['customer.user'])
            ->withCount([
                'invoiceRequests as invoice_requests_count' => fn (Builder $q) => $q->withTrashed()
                    ->where('invoice_requestable_type', $labType),
            ])
            ->limit(5000)
            ->get()
            ->map(fn (TaxProfile $profile) => $this->presenter->presentTaxProfile($profile));
    }

    public function findForShow(TaxProfile $taxProfile): array
    {
        $labType = LaboratoryPurchase::class;

        $taxProfile->load(['customer.user']);
        $taxProfile->loadCount([
            'invoiceRequests as invoice_requests_count' => fn (Builder $q) => $q->withTrashed()
                ->where('invoice_requestable_type', $labType),
        ]);

        $presented = $this->presenter->presentTaxProfile($taxProfile);

        $recentRequests = InvoiceRequest::withTrashed()
            ->where('tax_profile_id', $taxProfile->id)
            ->where('invoice_requestable_type', $labType)
            ->with([
                'invoiceRequestable' => fn ($morph) => $morph->withTrashed()->with(['invoice', 'customer.user']),
            ])
            ->latest('created_at')
            ->limit(10)
            ->get()
            ->map(fn (InvoiceRequest $request) => $this->presenter->presentRequest($request))
            ->all();

        $monthlyUsage = InvoiceRequest::withTrashed()
            ->where('tax_profile_id', $taxProfile->id)
            ->where('invoice_requestable_type', $labType)
            ->where('created_at', '>=', now()->subMonths(11)->startOfMonth())
            ->get(['id', 'created_at'])
            ->groupBy(fn (InvoiceRequest $request) => Carbon::parse($request->created_at)->timezone('America/Monterrey')->format('Y-m'))
            ->map(fn ($group, $period) => [
                'period' => $period,
                'label' => $period,
                'value' => $group->count(),
            ])
            ->sortKeys()
            ->values()
            ->all();

        $presented['recent_requests'] = $recentRequests;
        $presented['monthly_usage'] = $monthlyUsage;

        return $presented;
    }

    public function filteredQuery(array $filters, LaboratoryBillingDateRange $range): Builder
    {
        $includeDeleted = ($filters['include_deleted'] ?? null) === 'true'
            || ($filters['include_deleted'] ?? null) === '1';

        $query = $includeDeleted
            ? TaxProfile::withTrashed()
            : TaxProfile::query();

        if (($filters['created_in_range'] ?? null) === 'true') {
            $query->whereBetween('created_at', [$range->from->clone()->utc(), $range->to->clone()->utc()]);
        }

        $search = trim((string) ($filters['search'] ?? ''));
        if ($search !== '') {
            $query->where(function (Builder $q) use ($search) {
                $q->where('name', 'like', "%{$search}%")
                    ->orWhere('razon_social', 'like', "%{$search}%")
                    ->orWhere('rfc', 'like', "%{$search}%")
                    ->orWhereHas('customer.user', function (Builder $userQuery) use ($search) {
                        $userQuery->where('name', 'like', "%{$search}%")
                            ->orWhere('email', 'like', "%{$search}%")
                            ->orWhere('paternal_lastname', 'like', "%{$search}%");
                    });
            });
        }

        if (($filters['status'] ?? null) === 'active') {
            $query->whereNull('deleted_at');
        }

        if (($filters['status'] ?? null) === 'deleted') {
            $query->onlyTrashed();
        }

        if (($filters['usage'] ?? null) === 'unused') {
            $query->whereDoesntHave('invoiceRequests', fn (Builder $q) => $q->withTrashed());
        }

        if (($filters['usage'] ?? null) === 'used') {
            $query->whereHas('invoiceRequests', fn (Builder $q) => $q->withTrashed());
        }

        if (($filters['is_default'] ?? null) === 'true') {
            $query->where('is_default', true);
        }

        if (! empty($filters['tipo_persona'])) {
            $query->where('tipo_persona', $filters['tipo_persona']);
        }

        return $query;
    }

    public function metrics(LaboratoryBillingDateRange $range): array
    {
        return $this->metricsService->taxProfileMetrics($range);
    }
}
