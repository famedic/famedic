<?php

namespace App\Services\ActiveCampaign;

use App\Models\ActiveCampaignDispatch;
use App\Models\Contact;
use App\Models\Customer;
use App\Models\LaboratoryPurchase;
use App\Models\MedicalAttentionSubscription;
use App\Models\OnlinePharmacyPurchase;
use App\Support\ActiveCampaign\ActiveCampaignDashboardFilter;
use Carbon\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Agrega métricas reales del Dashboard Ejecutivo (Marketing Intelligence).
 *
 * Prioriza pocas consultas agregadas. Las gráficas viven en un payload diferido.
 */
class ActiveCampaignDashboardService
{
    /**
     * Payload inmediato (salud, negocio, tablas, meta). Sin gráficas pesadas.
     *
     * @return array<string, mixed>
     */
    public function buildOverview(ActiveCampaignDashboardFilter $filter): array
    {
        $resolver = function () use ($filter) {
            $dispatchGlobals = $this->dispatchGlobals();
            $dispatchPeriod = $this->dispatchPeriodMetrics($filter);
            $domainPeriod = $this->domainPeriodMetrics($filter);

            return [
                'health' => $this->buildHealthCards($dispatchGlobals, $dispatchPeriod, $domainPeriod),
                'business' => $this->buildBusinessKpis($dispatchPeriod, $domainPeriod),
                'tables' => [
                    'recent_activity' => $this->recentActivity(12),
                    'recent_errors' => $this->recentErrors(12),
                    'in_flight' => $this->inFlight(12),
                ],
                'meta' => $this->buildMeta($filter),
            ];
        };

        if ($filter->bustCache) {
            Cache::forget($filter->cacheKey('overview'));
            Cache::forget($filter->cacheKey('charts'));
        }

        return Cache::remember($filter->cacheKey('overview'), now()->addMinutes(5), $resolver);
    }

    /**
     * Payload diferido de gráficas (segunda carga Inertia).
     *
     * @return array<string, mixed>
     */
    public function buildCharts(ActiveCampaignDashboardFilter $filter): array
    {
        $resolver = fn () => [
            'sync_by_day' => $this->dailySyncedSeries($filter),
            'errors_by_day' => $this->dailyFailedSeries($filter),
            'events_by_type' => $this->topEventTypes($filter),
            'dispatches_by_day' => $this->dailyCreatedSeries($filter),
        ];

        if ($filter->bustCache) {
            Cache::forget($filter->cacheKey('charts'));
        }

        return Cache::remember($filter->cacheKey('charts'), now()->addMinutes(5), $resolver);
    }

    /**
     * Una sola query: backlog + última sync global.
     *
     * @return array{backlog: int, last_synced_at: ?string}
     */
    private function dispatchGlobals(): array
    {
        $row = ActiveCampaignDispatch::query()
            ->toBase()
            ->selectRaw("SUM(CASE WHEN status IN ('pending','processing') THEN 1 ELSE 0 END) as backlog")
            ->selectRaw('MAX(synced_at) as last_synced_at')
            ->first();

        return [
            'backlog' => (int) ($row->backlog ?? 0),
            'last_synced_at' => $row->last_synced_at ?? null,
        ];
    }

    /**
     * Una sola query agregada para métricas de dispatches en periodo actual + anterior.
     *
     * @return array<string, int>
     */
    private function dispatchPeriodMetrics(ActiveCampaignDashboardFilter $filter): array
    {
        $cStart = $filter->start->toDateTimeString();
        $cEnd = $filter->end->toDateTimeString();
        $pStart = $filter->previousStart->toDateTimeString();
        $pEnd = $filter->previousEnd->toDateTimeString();

        // Consultas separadas por columna indexable (evita OR multi-columna del periodo amplio).
        $errorsCurrent = (int) ActiveCampaignDispatch::query()
            ->toBase()
            ->where('status', ActiveCampaignDispatch::STATUS_FAILED)
            ->whereBetween('updated_at', [$cStart, $cEnd])
            ->count();

        $errorsPrevious = (int) ActiveCampaignDispatch::query()
            ->toBase()
            ->where('status', ActiveCampaignDispatch::STATUS_FAILED)
            ->whereBetween('updated_at', [$pStart, $pEnd])
            ->count();

        $creditsCurrent = (int) ActiveCampaignDispatch::query()
            ->toBase()
            ->where('status', ActiveCampaignDispatch::STATUS_SYNCED)
            ->where('event_type', 'like', 'credit_%')
            ->whereBetween('synced_at', [$cStart, $cEnd])
            ->count();

        $creditsPrevious = (int) ActiveCampaignDispatch::query()
            ->toBase()
            ->where('status', ActiveCampaignDispatch::STATUS_SYNCED)
            ->where('event_type', 'like', 'credit_%')
            ->whereBetween('synced_at', [$pStart, $pEnd])
            ->count();

        $promosCurrent = (int) ActiveCampaignDispatch::query()
            ->toBase()
            ->where('status', ActiveCampaignDispatch::STATUS_SYNCED)
            ->where('event_type', 'like', 'promo_%')
            ->whereBetween('synced_at', [$cStart, $cEnd])
            ->count();

        $promosPrevious = (int) ActiveCampaignDispatch::query()
            ->toBase()
            ->where('status', ActiveCampaignDispatch::STATUS_SYNCED)
            ->where('event_type', 'like', 'promo_%')
            ->whereBetween('synced_at', [$pStart, $pEnd])
            ->count();

        $syncedCurrent = (int) ActiveCampaignDispatch::query()
            ->toBase()
            ->where('status', ActiveCampaignDispatch::STATUS_SYNCED)
            ->whereBetween('synced_at', [$cStart, $cEnd])
            ->count();

        return [
            'errors_current' => $errorsCurrent,
            'errors_previous' => $errorsPrevious,
            'credits_current' => $creditsCurrent,
            'credits_previous' => $creditsPrevious,
            'promos_current' => $promosCurrent,
            'promos_previous' => $promosPrevious,
            'synced_current' => $syncedCurrent,
        ];
    }

    /**
     * Proxies de dominio: 5 queries agregadas (current + previous en cada una).
     *
     * @return array<string, int>
     */
    private function domainPeriodMetrics(ActiveCampaignDashboardFilter $filter): array
    {
        $cStart = $filter->start->toDateTimeString();
        $cEnd = $filter->end->toDateTimeString();
        $pStart = $filter->previousStart->toDateTimeString();
        $pEnd = $filter->previousEnd->toDateTimeString();

        $contacts = Contact::query()
            ->toBase()
            ->whereNull('deleted_at')
            ->whereBetween('created_at', [$pStart, $cEnd])
            ->selectRaw('SUM(CASE WHEN created_at BETWEEN ? AND ? THEN 1 ELSE 0 END) as current_total', [$cStart, $cEnd])
            ->selectRaw('SUM(CASE WHEN created_at BETWEEN ? AND ? THEN 1 ELSE 0 END) as previous_total', [$pStart, $pEnd])
            ->first();

        $pharmacy = OnlinePharmacyPurchase::query()
            ->toBase()
            ->whereNull('deleted_at')
            ->whereBetween('created_at', [$pStart, $cEnd])
            ->selectRaw('SUM(CASE WHEN created_at BETWEEN ? AND ? THEN 1 ELSE 0 END) as current_total', [$cStart, $cEnd])
            ->selectRaw('SUM(CASE WHEN created_at BETWEEN ? AND ? THEN 1 ELSE 0 END) as previous_total', [$pStart, $pEnd])
            ->first();

        $memberships = MedicalAttentionSubscription::query()
            ->toBase()
            ->whereNull('deleted_at')
            ->whereBetween('created_at', [$pStart, $cEnd])
            ->selectRaw('SUM(CASE WHEN created_at BETWEEN ? AND ? THEN 1 ELSE 0 END) as current_total', [$cStart, $cEnd])
            ->selectRaw('SUM(CASE WHEN created_at BETWEEN ? AND ? THEN 1 ELSE 0 END) as previous_total', [$pStart, $pEnd])
            ->first();

        $abandoned = Customer::query()
            ->toBase()
            ->whereNull('deleted_at')
            ->whereNotNull('cart_abandoned_tagged_at')
            ->whereBetween('cart_abandoned_tagged_at', [$pStart, $cEnd])
            ->selectRaw('SUM(CASE WHEN cart_abandoned_tagged_at BETWEEN ? AND ? THEN 1 ELSE 0 END) as current_total', [$cStart, $cEnd])
            ->selectRaw('SUM(CASE WHEN cart_abandoned_tagged_at BETWEEN ? AND ? THEN 1 ELSE 0 END) as previous_total', [$pStart, $pEnd])
            ->first();

        $lab = $this->labPeriodCounts($filter);

        return [
            'patients_current' => (int) ($contacts->current_total ?? 0),
            'patients_previous' => (int) ($contacts->previous_total ?? 0),
            'lab_current' => $lab['current'],
            'lab_previous' => $lab['previous'],
            'pharmacy_current' => (int) ($pharmacy->current_total ?? 0),
            'pharmacy_previous' => (int) ($pharmacy->previous_total ?? 0),
            'memberships_current' => (int) ($memberships->current_total ?? 0),
            'memberships_previous' => (int) ($memberships->previous_total ?? 0),
            'abandoned_current' => (int) ($abandoned->current_total ?? 0),
            'abandoned_previous' => (int) ($abandoned->previous_total ?? 0),
        ];
    }

    /**
     * @return array{current: int, previous: int}
     */
    private function labPeriodCounts(ActiveCampaignDashboardFilter $filter): array
    {
        $cStart = $filter->start->toDateTimeString();
        $cEnd = $filter->end->toDateTimeString();
        $pStart = $filter->previousStart->toDateTimeString();
        $pEnd = $filter->previousEnd->toDateTimeString();

        if (Schema::hasColumn('laboratory_purchases', 'paid_at')) {
            $expr = 'COALESCE(paid_at, completed_at, created_at)';
            $row = LaboratoryPurchase::query()
                ->toBase()
                ->whereNull('deleted_at')
                ->whereBetween(DB::raw($expr), [$pStart, $cEnd])
                ->selectRaw("SUM(CASE WHEN {$expr} BETWEEN ? AND ? THEN 1 ELSE 0 END) as current_total", [$cStart, $cEnd])
                ->selectRaw("SUM(CASE WHEN {$expr} BETWEEN ? AND ? THEN 1 ELSE 0 END) as previous_total", [$pStart, $pEnd])
                ->first();
        } else {
            $row = LaboratoryPurchase::query()
                ->toBase()
                ->whereNull('deleted_at')
                ->whereBetween('created_at', [$pStart, $cEnd])
                ->selectRaw('SUM(CASE WHEN created_at BETWEEN ? AND ? THEN 1 ELSE 0 END) as current_total', [$cStart, $cEnd])
                ->selectRaw('SUM(CASE WHEN created_at BETWEEN ? AND ? THEN 1 ELSE 0 END) as previous_total', [$pStart, $pEnd])
                ->first();
        }

        return [
            'current' => (int) ($row->current_total ?? 0),
            'previous' => (int) ($row->previous_total ?? 0),
        ];
    }

    /**
     * @param  array<string, mixed>  $globals
     * @param  array<string, int>  $dispatchPeriod
     * @param  array<string, int>  $domainPeriod
     * @return list<array<string, mixed>>
     */
    private function buildHealthCards(array $globals, array $dispatchPeriod, array $domainPeriod): array
    {
        $enabled = (bool) config('services.activecampaign.enabled', true);
        $couponsEnabled = $enabled && (bool) config('services.activecampaign.coupons_enabled', true);
        $configured = filled(config('services.activecampaign.endpoint'))
            && filled(config('services.activecampaign.token'));

        $statusLabel = ! $configured
            ? 'Sin credenciales'
            : (! $enabled ? 'Desactivado' : ($couponsEnabled ? 'Operativo' : 'Parcial'));

        $lastSynced = $globals['last_synced_at']
            ? Carbon::parse($globals['last_synced_at'])->timezone('America/Monterrey')->format('d/m/Y H:i')
            : 'No disponible';

        return [
            $this->card('integration', 'Estado integración', $statusLabel, 'disponible', 'activecampaign', $configured && $enabled ? 'green' : 'amber', 'Flags de configuración local'),
            $this->card('errors', 'Errores', number_format($dispatchPeriod['errors_current']), 'disponible', 'activecampaign', $dispatchPeriod['errors_current'] > 0 ? 'red' : 'default', 'Dispatches failed del periodo'),
            $this->card('backlog', 'Dispatches pendientes', number_format($globals['backlog']), 'disponible', 'activecampaign', $globals['backlog'] > 0 ? 'amber' : 'default', 'pending + processing'),
            $this->card('last_sync', 'Última sincronización', $lastSynced, 'disponible', 'activecampaign', 'default', 'MAX(synced_at)'),
            $this->card('patients', 'Pacientes', number_format($domainPeriod['patients_current']), 'proxy', 'activecampaign', 'sky', 'Pacientes creados (disparan Job; no confirma sync)'),
            $this->card('credits', 'Créditos', number_format($dispatchPeriod['credits_current']), 'disponible', 'activecampaign', 'green', 'Dispatches credit_* synced'),
        ];
    }

    /**
     * @param  array<string, int>  $dispatchPeriod
     * @param  array<string, int>  $domainPeriod
     * @return list<array<string, mixed>>
     */
    private function buildBusinessKpis(array $dispatchPeriod, array $domainPeriod): array
    {
        return [
            $this->kpi('lab', 'Laboratorios', $domainPeriod['lab_current'], $domainPeriod['lab_previous'], 'blue', 'proxy', 'activecampaign', 'Compras lab (proxy de trigger)'),
            $this->kpi('pharmacy', 'Farmacia', $domainPeriod['pharmacy_current'], $domainPeriod['pharmacy_previous'], 'purple', 'proxy', 'activecampaign', 'Compras farmacia por created_at'),
            $this->kpi('membership', 'Membresías', $domainPeriod['memberships_current'], $domainPeriod['memberships_previous'], 'green', 'proxy', 'activecampaign', 'Altas en el periodo'),
            $this->kpi('abandoned', 'Carritos', $domainPeriod['abandoned_current'], $domainPeriod['abandoned_previous'], 'orange', 'disponible', 'activecampaign', 'Tag AC de abandono', true),
            $this->kpi('promo', 'Promociones', $dispatchPeriod['promos_current'], $dispatchPeriod['promos_previous'], 'blue', 'disponible', 'activecampaign', 'Dispatches promo_* synced'),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function card(
        string $id,
        string $label,
        string $value,
        string $truth,
        string $source,
        string $tone,
        ?string $hint = null,
    ): array {
        return [
            'id' => $id,
            'label' => $label,
            'value' => $value,
            'truth' => $truth,
            'source' => $source,
            'tone' => $tone,
            'hint' => $hint,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function kpi(
        string $id,
        string $label,
        int $current,
        int $previous,
        string $tone,
        string $truth,
        string $source,
        ?string $hint = null,
        bool $higherIsWorse = false,
    ): array {
        $delta = $this->delta($current, $previous, $higherIsWorse);

        return [
            'id' => $id,
            'label' => $label,
            'value_formatted' => number_format($current),
            'previous_formatted' => number_format($previous),
            'hint' => $hint,
            'tone' => $tone,
            'truth' => $truth,
            'source' => $source,
            'sparkline' => [],
            ...$delta,
        ];
    }

    /**
     * @return array{delta_percent: float|null, delta_direction: string, delta_is_positive: bool|null}
     */
    private function delta(int $current, int $previous, bool $higherIsWorse = false): array
    {
        if ($previous === 0) {
            if ($current === 0) {
                return [
                    'delta_percent' => 0.0,
                    'delta_direction' => 'flat',
                    'delta_is_positive' => null,
                ];
            }

            return [
                'delta_percent' => 100.0,
                'delta_direction' => 'up',
                'delta_is_positive' => ! $higherIsWorse,
            ];
        }

        $raw = (($current - $previous) / abs($previous)) * 100;
        $direction = $raw > 0.05 ? 'up' : ($raw < -0.05 ? 'down' : 'flat');
        $isPositive = match ($direction) {
            'flat' => null,
            'up' => ! $higherIsWorse,
            'down' => $higherIsWorse,
        };

        return [
            'delta_percent' => round(abs($raw), 1),
            'delta_direction' => $direction,
            'delta_is_positive' => $isPositive,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function buildMeta(ActiveCampaignDashboardFilter $filter): array
    {
        return [
            'generated_at' => now('America/Monterrey')->format('d/m/Y H:i'),
            'previous_period' => [
                'start_date' => $filter->previousStart->timezone('America/Monterrey')->toDateString(),
                'end_date' => $filter->previousEnd->timezone('America/Monterrey')->toDateString(),
            ],
            'sources' => [
                ['id' => 'activecampaign', 'label' => 'ActiveCampaign', 'status' => 'active'],
                ['id' => 'google_analytics', 'label' => 'Google Analytics', 'status' => 'planned'],
                ['id' => 'meta_ads', 'label' => 'Meta Ads', 'status' => 'planned'],
                ['id' => 'whatsapp', 'label' => 'WhatsApp', 'status' => 'planned'],
                ['id' => 'mailgun', 'label' => 'Mailgun', 'status' => 'planned'],
                ['id' => 'insights', 'label' => 'Insights', 'status' => 'planned'],
                ['id' => 'ai', 'label' => 'IA', 'status' => 'planned'],
            ],
            'definitions' => [
                'disponible' => 'Dato confirmado en Famedic (dispatches, flags, tags de abandono).',
                'proxy' => 'Volumen de negocio que dispara sync; no confirma éxito en ActiveCampaign.',
                'instrumentacion' => 'Requiere instrumentación o nueva fuente de datos.',
            ],
            'notes' => [
                'Errores, créditos, promociones y gráficas de sync reflejan dispatches de cupones/promos/beneficiarios.',
                'Compras y membresías son proxies de dominio hasta instrumentar sync legacy.',
            ],
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function recentActivity(int $limit): array
    {
        return ActiveCampaignDispatch::query()
            ->orderByDesc('id')
            ->limit($limit)
            ->get(['id', 'event_type', 'email', 'status', 'created_at', 'synced_at'])
            ->map(fn (ActiveCampaignDispatch $row) => $this->mapDispatchRow($row))
            ->all();
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function recentErrors(int $limit): array
    {
        return ActiveCampaignDispatch::query()
            ->where('status', ActiveCampaignDispatch::STATUS_FAILED)
            ->orderByDesc('updated_at')
            ->limit($limit)
            ->get(['id', 'event_type', 'email', 'status', 'attempts', 'last_error', 'updated_at', 'created_at'])
            ->map(function (ActiveCampaignDispatch $row) {
                $mapped = $this->mapDispatchRow($row);
                $mapped['attempts'] = (int) $row->attempts;
                $mapped['last_error'] = $this->truncateError($row->last_error);
                $mapped['when'] = optional($row->updated_at)?->timezone('America/Monterrey')->format('d/m/Y H:i');

                return $mapped;
            })
            ->all();
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function inFlight(int $limit): array
    {
        return ActiveCampaignDispatch::query()
            ->whereIn('status', [
                ActiveCampaignDispatch::STATUS_PENDING,
                ActiveCampaignDispatch::STATUS_PROCESSING,
            ])
            ->orderBy('id')
            ->limit($limit)
            ->get(['id', 'event_type', 'email', 'status', 'attempts', 'created_at'])
            ->map(fn (ActiveCampaignDispatch $row) => $this->mapDispatchRow($row))
            ->all();
    }

    /**
     * @return array<string, mixed>
     */
    private function mapDispatchRow(ActiveCampaignDispatch $row): array
    {
        return [
            'id' => $row->id,
            'event_type' => $row->event_type,
            'email' => $row->email ?: '—',
            'status' => $row->status,
            'attempts' => (int) ($row->attempts ?? 0),
            'when' => optional($row->created_at)?->timezone('America/Monterrey')->format('d/m/Y H:i'),
            'synced_at' => optional($row->synced_at)?->timezone('America/Monterrey')->format('d/m/Y H:i'),
            'source' => 'activecampaign',
        ];
    }

    private function truncateError(?string $error): string
    {
        if ($error === null || trim($error) === '') {
            return '—';
        }

        $error = trim(preg_replace('/\s+/', ' ', $error) ?? $error);

        return mb_strlen($error) > 120 ? mb_substr($error, 0, 117).'…' : $error;
    }

    /**
     * @return list<array{date: string, label: string, value: int}>
     */
    private function dailySyncedSeries(ActiveCampaignDashboardFilter $filter): array
    {
        $rows = ActiveCampaignDispatch::query()
            ->toBase()
            ->selectRaw('DATE(synced_at) as day, COUNT(*) as total')
            ->where('status', ActiveCampaignDispatch::STATUS_SYNCED)
            ->whereNotNull('synced_at')
            ->whereBetween('synced_at', [$filter->start, $filter->end])
            ->groupBy('day')
            ->pluck('total', 'day')
            ->mapWithKeys(fn ($total, $day) => [(string) $day => (int) $total])
            ->all();

        return $this->fillDailySeries($filter, $rows);
    }

    /**
     * @return list<array{date: string, label: string, value: int}>
     */
    private function dailyFailedSeries(ActiveCampaignDashboardFilter $filter): array
    {
        $rows = ActiveCampaignDispatch::query()
            ->toBase()
            ->selectRaw('DATE(updated_at) as day, COUNT(*) as total')
            ->where('status', ActiveCampaignDispatch::STATUS_FAILED)
            ->whereBetween('updated_at', [$filter->start, $filter->end])
            ->groupBy('day')
            ->pluck('total', 'day')
            ->mapWithKeys(fn ($total, $day) => [(string) $day => (int) $total])
            ->all();

        return $this->fillDailySeries($filter, $rows);
    }

    /**
     * @return list<array{date: string, label: string, value: int}>
     */
    private function dailyCreatedSeries(ActiveCampaignDashboardFilter $filter): array
    {
        $rows = ActiveCampaignDispatch::query()
            ->toBase()
            ->selectRaw('DATE(created_at) as day, COUNT(*) as total')
            ->whereBetween('created_at', [$filter->start, $filter->end])
            ->groupBy('day')
            ->pluck('total', 'day')
            ->mapWithKeys(fn ($total, $day) => [(string) $day => (int) $total])
            ->all();

        return $this->fillDailySeries($filter, $rows);
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function topEventTypes(ActiveCampaignDashboardFilter $filter): array
    {
        return ActiveCampaignDispatch::query()
            ->toBase()
            ->selectRaw('event_type, COUNT(*) as total')
            ->selectRaw("SUM(CASE WHEN status = 'synced' THEN 1 ELSE 0 END) as synced")
            ->selectRaw("SUM(CASE WHEN status = 'failed' THEN 1 ELSE 0 END) as failed")
            ->whereBetween('created_at', [$filter->start, $filter->end])
            ->groupBy('event_type')
            ->orderByDesc('total')
            ->limit(8)
            ->get()
            ->map(fn ($row) => [
                'event_type' => (string) $row->event_type,
                'label' => (string) $row->event_type,
                'total' => (int) $row->total,
                'synced' => (int) $row->synced,
                'failed' => (int) $row->failed,
                'value' => (int) $row->total,
            ])
            ->all();
    }

    /**
     * @param  array<string, int>  $byDay
     * @return list<array{date: string, label: string, value: int}>
     */
    private function fillDailySeries(ActiveCampaignDashboardFilter $filter, array $byDay): array
    {
        $cursor = $filter->startLocal->copy()->startOfDay();
        $end = $filter->endLocal->copy()->startOfDay();
        $series = [];

        while ($cursor->lte($end)) {
            $key = $cursor->toDateString();
            $series[] = [
                'date' => $key,
                'label' => $cursor->format('d/m'),
                'value' => (int) ($byDay[$key] ?? 0),
            ];
            $cursor->addDay();
        }

        return $series;
    }
}
