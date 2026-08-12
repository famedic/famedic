<?php

namespace App\Services\LaboratoryBilling;

use App\Models\Invoice;
use App\Models\InvoiceRequest;
use App\Models\LaboratoryPurchase;
use App\Models\TaxProfile;
use Carbon\CarbonPeriod;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class LaboratoryBillingMetricsService
{
    public function __construct(
        private LaboratoryBillingStatusResolver $resolver,
        private LaboratoryBillingPresenter $presenter,
    ) {}

    public function baseRequestsQuery(): Builder
    {
        return InvoiceRequest::query()
            ->forActiveLaboratoryPurchases();
    }

    public function requestsInRange(LaboratoryBillingDateRange $range): Builder
    {
        return $this->baseRequestsQuery()
            ->whereBetween('created_at', [$range->from->clone()->utc(), $range->to->clone()->utc()]);
    }

    public function requestCounts(LaboratoryBillingDateRange $range): array
    {
        $base = $this->requestsInRange($range);
        $now = Carbon::now('America/Monterrey');

        $pending = (clone $base);
        $this->resolver->scopePending($pending, $now);

        $inProgress = (clone $base);
        $this->resolver->scopeInProgress($inProgress, $now);

        $overdue = (clone $base);
        $this->resolver->scopeOverdue($overdue, $now);

        $completed = (clone $base);
        $this->resolver->scopeComplete($completed);

        return [
            'pending' => $pending->count(),
            'in_progress' => $inProgress->count(),
            'overdue' => $overdue->count(),
            'completed' => $completed->count(),
            'total' => (clone $base)->count(),
        ];
    }

    public function requestCountsWithDelta(LaboratoryBillingDateRange $range): array
    {
        $current = $this->requestCounts($range);
        $previous = $this->requestCounts($range->previous());

        $withDelta = [];
        foreach (['pending', 'in_progress', 'overdue', 'completed', 'total'] as $key) {
            $currentValue = $current[$key];
            $previousValue = $previous[$key];
            $delta = $currentValue - $previousValue;
            $percent = $previousValue > 0
                ? round(($delta / $previousValue) * 100, 1)
                : ($currentValue > 0 ? 100.0 : 0.0);

            $withDelta[$key] = [
                'value' => $currentValue,
                'previous' => $previousValue,
                'delta' => $delta,
                'delta_percent' => $percent,
            ];
        }

        return $withDelta;
    }

    public function compliance(LaboratoryBillingDateRange $range): array
    {
        // Cohorte: solicitudes creadas en el rango.
        // Completadas: esas mismas solicitudes con completed_at (aunque sea fuera del rango).
        $received = $this->requestsInRange($range)->count();
        $completedQuery = $this->requestsInRange($range);
        $this->resolver->scopeComplete($completedQuery);
        $completed = $completedQuery->count();
        $notCompleted = max(0, $received - $completed);
        $percent = $received > 0 ? round(($completed / $received) * 100, 1) : 0.0;

        return [
            'received' => $received,
            'completed' => $completed,
            'not_completed' => $notCompleted,
            'percent' => $percent,
            'target_percent' => null,
            'definition' => 'Cohorte por fecha de solicitud. Completada = PDF+XML con completed_at asignado (aunque la finalización sea fuera del rango).',
        ];
    }

    public function taxProfileMetrics(LaboratoryBillingDateRange $range): array
    {
        $total = TaxProfile::withTrashed()->count();
        $active = TaxProfile::query()->count();
        $newInPeriod = TaxProfile::withTrashed()
            ->whereBetween('created_at', [$range->from->clone()->utc(), $range->to->clone()->utc()])
            ->count();

        $unused = TaxProfile::query()
            ->whereDoesntHave('invoiceRequests', fn (Builder $q) => $q->withTrashed()->forActiveLaboratoryPurchases())
            ->count();

        return [
            'total' => $total,
            'active' => $active,
            'new_in_period' => $newInPeriod,
            'unused' => $unused,
            'requires_update' => null,
        ];
    }

    public function requestsVsInvoicesSeries(LaboratoryBillingDateRange $range): array
    {
        $granularity = $range->chartGranularity();
        $buckets = $this->buildBuckets($range, $granularity);

        $requestSeries = array_fill_keys(array_keys($buckets), 0);
        $invoiceSeries = array_fill_keys(array_keys($buckets), 0);

        // Solicitudes: agrupadas por fecha de solicitud dentro del rango.
        $this->requestsInRange($range)
            ->get(['id', 'created_at'])
            ->each(function (InvoiceRequest $request) use (&$requestSeries, $granularity) {
                $key = $this->bucketKey($request->created_at, $granularity);
                if ($key !== null && isset($requestSeries[$key])) {
                    $requestSeries[$key]++;
                }
            });

        // Producción: facturas con completed_at dentro del rango (pueden pertenecer
        // a solicitudes creadas fuera del periodo).
        $labType = LaboratoryPurchase::class;
        Invoice::query()
            ->whereNotNull('completed_at')
            ->whereBetween('completed_at', [$range->from->clone()->utc(), $range->to->clone()->utc()])
            ->where('invoiceable_type', $labType)
            ->whereHasMorph('invoiceable', [LaboratoryPurchase::class], function (Builder $q) {
                $q->whereNull('laboratory_purchases.deleted_at')
                    ->whereHas('invoiceRequest');
            })
            ->get(['id', 'completed_at'])
            ->each(function (Invoice $invoice) use (&$invoiceSeries, $granularity) {
                $key = $this->bucketKey($invoice->completed_at, $granularity);
                if ($key !== null && isset($invoiceSeries[$key])) {
                    $invoiceSeries[$key]++;
                }
            });

        $points = [];
        foreach ($buckets as $key => $label) {
            $points[] = [
                'key' => $key,
                'label' => $label,
                'requests' => $requestSeries[$key] ?? 0,
                'invoices_completed' => $invoiceSeries[$key] ?? 0,
            ];
        }

        return [
            'granularity' => $granularity,
            'definition' => 'Solicitudes por fecha de solicitud. Facturas completadas por invoices.completed_at (primera finalización PDF+XML; no se mueve al reemplazar documentos).',
            'points' => $points,
        ];
    }

    public function newTaxProfilesSeries(LaboratoryBillingDateRange $range): array
    {
        $granularity = $range->chartGranularity();
        $buckets = $this->buildBuckets($range, $granularity);
        $series = array_fill_keys(array_keys($buckets), 0);

        TaxProfile::withTrashed()
            ->whereBetween('created_at', [$range->from->clone()->utc(), $range->to->clone()->utc()])
            ->get(['id', 'created_at'])
            ->each(function (TaxProfile $profile) use (&$series, $granularity) {
                $key = $this->bucketKey($profile->created_at, $granularity);
                if (isset($series[$key])) {
                    $series[$key]++;
                }
            });

        $points = [];
        foreach ($buckets as $key => $label) {
            $points[] = [
                'key' => $key,
                'label' => $label,
                'value' => $series[$key] ?? 0,
            ];
        }

        return [
            'granularity' => $granularity,
            'points' => $points,
        ];
    }

    public function topOverdue(LaboratoryBillingDateRange $range, int $limit = 5): array
    {
        $query = $this->requestsInRange($range);
        $this->resolver->scopeOverdue($query);

        $items = $query
            ->with([
                'taxProfile' => fn ($q) => $q->withTrashed(),
                'invoiceRequestable' => fn ($morph) => $morph->withTrashed()->with([
                    'invoice',
                    'customer.user',
                ]),
            ])
            ->orderBy('created_at')
            ->limit(50)
            ->get()
            ->map(fn (InvoiceRequest $request) => $this->presenter->presentRequest($request))
            ->sortByDesc(fn (array $row) => $row['billing']['days_overdue'] ?? 0)
            ->take($limit)
            ->values()
            ->all();

        return $items;
    }

    public function recentActivity(LaboratoryBillingDateRange $range, int $limit = 12): array
    {
        $events = collect();

        $this->requestsInRange($range)
            ->latest('created_at')
            ->limit($limit)
            ->get(['id', 'created_at', 'name', 'rfc', 'invoice_requestable_id'])
            ->each(function (InvoiceRequest $request) use ($events) {
                $events->push([
                    'type' => 'request_created',
                    'label' => 'Solicitud creada',
                    'at' => $request->created_at?->toIso8601String(),
                    'formatted_at' => localizedDate($request->created_at)?->isoFormat('D MMM Y h:mm a'),
                    'meta' => [
                        'request_id' => $request->id,
                        'name' => $request->name,
                        'rfc' => $request->rfc,
                        'purchase_id' => $request->invoice_requestable_id,
                    ],
                ]);
            });

        $purchaseIds = $this->requestsInRange($range)->pluck('invoice_requestable_id');

        LaboratoryPurchase::query()
            ->whereIn('id', $purchaseIds)
            ->whereHas('invoice')
            ->with('invoice')
            ->get()
            ->each(function (LaboratoryPurchase $purchase) use ($events, $range) {
                $invoice = $purchase->invoice;
                if (! $invoice) {
                    return;
                }

                if ($invoice->completed_at && $range->contains($invoice->completed_at)) {
                    $events->push([
                        'type' => 'invoice_completed',
                        'label' => 'Factura completada',
                        'at' => $invoice->completed_at->toIso8601String(),
                        'formatted_at' => localizedDate($invoice->completed_at)?->isoFormat('D MMM Y h:mm a'),
                        'meta' => [
                            'invoice_id' => $invoice->id,
                            'purchase_id' => $purchase->id,
                            'has_pdf' => $this->resolver->hasPdf($invoice),
                            'has_xml' => $this->resolver->hasXml($invoice),
                        ],
                    ]);
                }

                if (
                    $invoice->updated_at
                    && $range->contains($invoice->updated_at)
                    && $invoice->completed_at
                    && $invoice->updated_at->gt($invoice->completed_at->copy()->addMinute())
                ) {
                    $events->push([
                        'type' => 'invoice_updated',
                        'label' => 'Documentos actualizados',
                        'at' => $invoice->updated_at->toIso8601String(),
                        'formatted_at' => localizedDate($invoice->updated_at)?->isoFormat('D MMM Y h:mm a'),
                        'meta' => [
                            'invoice_id' => $invoice->id,
                            'purchase_id' => $purchase->id,
                            'has_pdf' => $this->resolver->hasPdf($invoice),
                            'has_xml' => $this->resolver->hasXml($invoice),
                        ],
                    ]);
                }
            });

        TaxProfile::withTrashed()
            ->whereBetween('created_at', [$range->from->clone()->utc(), $range->to->clone()->utc()])
            ->latest('created_at')
            ->limit($limit)
            ->get(['id', 'name', 'razon_social', 'rfc', 'created_at', 'updated_at'])
            ->each(function (TaxProfile $profile) use ($events) {
                $events->push([
                    'type' => 'tax_profile_created',
                    'label' => 'Perfil fiscal creado',
                    'at' => $profile->created_at?->toIso8601String(),
                    'formatted_at' => localizedDate($profile->created_at)?->isoFormat('D MMM Y h:mm a'),
                    'meta' => [
                        'tax_profile_id' => $profile->id,
                        'name' => $profile->name ?: $profile->razon_social,
                        'rfc' => $profile->rfc,
                    ],
                ]);

                if ($profile->updated_at && $profile->updated_at->gt($profile->created_at->copy()->addMinute())
                    && $profile->updated_at->betweenIncluded(
                        Carbon::parse($profile->created_at)->timezone('America/Monterrey'),
                        Carbon::now('America/Monterrey')
                    )
                ) {
                    $events->push([
                        'type' => 'tax_profile_updated',
                        'label' => 'Perfil fiscal actualizado',
                        'at' => $profile->updated_at?->toIso8601String(),
                        'formatted_at' => localizedDate($profile->updated_at)?->isoFormat('D MMM Y h:mm a'),
                        'meta' => [
                            'tax_profile_id' => $profile->id,
                            'name' => $profile->name ?: $profile->razon_social,
                            'rfc' => $profile->rfc,
                        ],
                    ]);
                }
            });

        return $events
            ->filter(fn (array $event) => filled($event['at'] ?? null))
            ->sortByDesc('at')
            ->take($limit)
            ->values()
            ->all();
    }

    public function averageResponseTimeHours(LaboratoryBillingDateRange $range): ?float
    {
        $hours = $this->responseTimeHoursCollection($range);

        if ($hours->isEmpty()) {
            return null;
        }

        return round($hours->avg(), 2);
    }

    public function medianResponseTimeHours(LaboratoryBillingDateRange $range): ?float
    {
        $hours = $this->responseTimeHoursCollection($range)->sort()->values();

        if ($hours->isEmpty()) {
            return null;
        }

        $count = $hours->count();
        $middle = intdiv($count, 2);

        if ($count % 2 === 1) {
            return round((float) $hours[$middle], 2);
        }

        return round(((float) $hours[$middle - 1] + (float) $hours[$middle]) / 2, 2);
    }

    public function profilesByTipoPersona(): array
    {
        return TaxProfile::query()
            ->select('tipo_persona', DB::raw('count(*) as total'))
            ->groupBy('tipo_persona')
            ->get()
            ->map(fn ($row) => [
                'key' => $row->tipo_persona ?: 'desconocido',
                'label' => match ($row->tipo_persona) {
                    'fisica' => 'Persona física',
                    'moral' => 'Persona moral',
                    default => 'Desconocido',
                },
                'value' => (int) $row->total,
            ])
            ->values()
            ->all();
    }

    public function profilesByStatus(): array
    {
        $active = TaxProfile::query()->count();
        $deleted = TaxProfile::onlyTrashed()->count();
        $unused = TaxProfile::query()
            ->whereDoesntHave('invoiceRequests', fn (Builder $q) => $q->withTrashed()->forActiveLaboratoryPurchases())
            ->count();

        return [
            ['key' => 'active', 'label' => 'Activos', 'value' => $active],
            ['key' => 'deleted', 'label' => 'Eliminados lógicamente', 'value' => $deleted],
            ['key' => 'unused', 'label' => 'Sin uso', 'value' => $unused],
        ];
    }

    public function onTimeVsLate(LaboratoryBillingDateRange $range): array
    {
        $base = $this->requestsInRange($range);
        $completed = (clone $base);
        $this->resolver->scopeComplete($completed);
        $completedCount = $completed->count();

        $overdue = (clone $base);
        $this->resolver->scopeOverdue($overdue);
        $overdueCount = $overdue->count();

        $onTimeIncomplete = max(0, (clone $base)->count() - $completedCount - $overdueCount);

        return [
            ['key' => 'completed', 'label' => 'Completadas', 'value' => $completedCount],
            ['key' => 'on_time_open', 'label' => 'Dentro de plazo (abiertas)', 'value' => $onTimeIncomplete],
            ['key' => 'overdue', 'label' => 'Fuera de plazo', 'value' => $overdueCount],
        ];
    }

    public function unusedProfilesOldest(int $limit = 5): array
    {
        return TaxProfile::query()
            ->with(['customer.user'])
            ->withCount(['invoiceRequests as invoice_requests_count' => fn ($q) => $q->withTrashed()->forActiveLaboratoryPurchases()])
            ->whereDoesntHave('invoiceRequests', fn (Builder $q) => $q->withTrashed()->forActiveLaboratoryPurchases())
            ->orderBy('created_at')
            ->limit($limit)
            ->get()
            ->map(fn (TaxProfile $profile) => $this->presenter->presentTaxProfile($profile))
            ->all();
    }

    public function topPatientsByRequests(LaboratoryBillingDateRange $range, int $limit = 5): array
    {
        return $this->requestsInRange($range)
            ->select('invoice_requestable_id', DB::raw('count(*) as total'))
            ->groupBy('invoice_requestable_id')
            ->orderByDesc('total')
            ->limit($limit * 3)
            ->get()
            ->map(function ($row) {
                $purchase = LaboratoryPurchase::withTrashed()
                    ->with('customer.user')
                    ->find($row->invoice_requestable_id);

                if (! $purchase) {
                    return null;
                }

                $user = $purchase->customer?->user;
                $name = trim(collect([
                    $purchase->name,
                    $purchase->paternal_lastname,
                    $purchase->maternal_lastname,
                ])->filter()->implode(' '));

                if (! $name && $user) {
                    $name = trim(collect([$user->name, $user->paternal_lastname, $user->maternal_lastname])->filter()->implode(' '));
                }

                return [
                    'purchase_id' => $purchase->id,
                    'patient_name' => $name ?: '—',
                    'email' => $user?->email,
                    'total_requests' => (int) $row->total,
                    'show_url' => route('admin.laboratory-purchases.show', ['laboratory_purchase' => $purchase->id]),
                ];
            })
            ->filter()
            ->take($limit)
            ->values()
            ->all();
    }

    private function responseTimeHoursCollection(LaboratoryBillingDateRange $range): Collection
    {
        $requests = $this->requestsInRange($range)
            ->with([
                'invoiceRequestable' => fn ($morph) => $morph->withTrashed()->with('invoice'),
            ])
            ->get();

        return $requests
            ->map(function (InvoiceRequest $request) {
                /** @var LaboratoryPurchase|null $purchase */
                $purchase = $request->invoiceRequestable;
                if (! $this->resolver->isComplete($purchase?->invoice)) {
                    return null;
                }

                return $this->resolver->responseTimeHours($request, $purchase?->invoice);
            })
            ->filter(fn ($value) => $value !== null)
            ->values();
    }

    /**
     * @return array<string, string>
     */
    private function buildBuckets(LaboratoryBillingDateRange $range, string $granularity): array
    {
        $buckets = [];
        $cursor = $range->from->copy()->startOfDay();
        $end = $range->to->copy()->startOfDay();

        if ($granularity === 'day') {
            foreach (CarbonPeriod::create($cursor, $end) as $day) {
                $key = $day->toDateString();
                $buckets[$key] = $day->isoFormat('MMM D');
            }

            return $buckets;
        }

        if ($granularity === 'week') {
            $cursor = $cursor->startOfWeek();
            while ($cursor->lte($end)) {
                $key = $cursor->format('o-\WW');
                $buckets[$key] = 'Sem '.$cursor->isoFormat('W').' '.$cursor->isoFormat('MMM');
                $cursor->addWeek();
            }

            return $buckets;
        }

        $cursor = $cursor->startOfMonth();
        while ($cursor->lte($end)) {
            $key = $cursor->format('Y-m');
            $buckets[$key] = $cursor->isoFormat('MMM Y');
            $cursor->addMonth();
        }

        return $buckets;
    }

    private function bucketKey(Carbon|\DateTimeInterface|string|null $date, string $granularity): ?string
    {
        if (! $date) {
            return null;
        }

        $carbon = Carbon::parse($date)->timezone('America/Monterrey');

        return match ($granularity) {
            'week' => $carbon->format('o-\WW'),
            'month' => $carbon->format('Y-m'),
            default => $carbon->toDateString(),
        };
    }
}
