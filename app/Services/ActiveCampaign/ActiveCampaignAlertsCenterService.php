<?php

namespace App\Services\ActiveCampaign;

use App\Enums\MedicalSubscriptionType;
use App\Enums\MonitoringCartStatus;
use App\Models\ActiveCampaignDispatch;
use App\Models\Cart;
use App\Models\Contact;
use App\Models\LaboratoryPurchase;
use App\Models\MedicalAttentionSubscription;
use App\Support\ActiveCampaign\ActiveCampaignDashboardFilter;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Session;

/**
 * Alerts Center — consola de alertas inteligentes (capa de composición).
 * No crea modelos/tablas: consolida Dashboard, dispatches, config Automation,
 * señales ligeras Lab/Membership/Ecommerce y mapas Timeline.
 */
class ActiveCampaignAlertsCenterService
{
    private const TZ = 'America/Monterrey';

    private const SESSION_STATUS = 'mi_alert_status';

    private const SESSION_SEEN = 'mi_alert_seen';

    private ActiveCampaignDashboardService $dashboard;

    public function __construct(ActiveCampaignDashboardService $dashboard)
    {
        $this->dashboard = $dashboard;
    }

    /**
     * Inbox inmediato: resumen + bandeja filtrada.
     *
     * @return array<string, mixed>
     */
    public function buildInbox(Request $request): array
    {
        $filter = ActiveCampaignDashboardFilter::fromRequest($request);
        $filters = $this->resolveFilters($request);

        if ($filter->bustCache) {
            Cache::forget($this->cacheKey($filter, 'raw-alerts'));
            Cache::forget($this->cacheKey($filter, 'executive'));
        }

        $overview = $this->dashboard->buildOverview($filter);
        $rawKey = $this->cacheKey($filter, 'raw-alerts');

        $rawItems = Cache::remember($rawKey, now()->addMinutes(5), function () use ($overview, $filter) {
            return $this->collectAlerts($overview, $filter, [], [])->all();
        });

        $statuses = $this->statusMap();
        $seen = $this->seenIds();
        $items = collect($rawItems)->map(fn (array $a) => $this->applySessionStatus($a, $statuses, $seen))->values();
        $filtered = $this->applyFilters($items, $filters);

        return [
            'filters' => $filters,
            'filterOptions' => $this->filterOptions(),
            'summary' => $this->buildSummary($items),
            'alerts' => $filtered->map(fn (array $a) => $this->listItem($a))->values()->all(),
            'actions' => $this->quickActions(),
            'meta' => [
                ...($overview['meta'] ?? []),
                'purpose' => 'Identificar rápidamente situaciones que requieren atención (Dirección, Ops, Marketing, Soporte).',
                'source_of_truth' => 'Dashboard · Dispatches/Event · Automation config · señales Lab/Membership/Ecommerce · Timeline map',
                'total' => $filtered->count(),
                'note' => 'Sin modelos nuevos. Estados Vista/En proceso/Resuelta/Ignorada en sesión.',
                'avg_resolution' => 'Requiere instrumentación',
                'avg_resolution_truth' => 'instrumentacion',
            ],
        ];
    }

    /**
     * Panel ejecutivo diferido.
     *
     * @return array<string, mixed>
     */
    public function buildExecutive(Request $request): array
    {
        $filter = ActiveCampaignDashboardFilter::fromRequest($request);
        $overview = $this->dashboard->buildOverview($filter);
        $rawKey = $this->cacheKey($filter, 'raw-alerts');
        $items = collect(Cache::remember($rawKey, now()->addMinutes(5), function () use ($overview, $filter) {
            return $this->collectAlerts($overview, $filter, [], [])->all();
        }));

        $statuses = $this->statusMap();
        $seen = $this->seenIds();
        $items = $items->map(fn (array $a) => $this->applySessionStatus($a, $statuses, $seen));

        $byCategory = $items->groupBy('category')->map(fn ($g, $k) => [
            'label' => $this->categoryLabel((string) $k),
            'value' => $g->count(),
        ])->sortByDesc('value')->values()->all();

        $byModule = $items->groupBy('module')->map(fn ($g, $k) => [
            'label' => (string) $k,
            'value' => $g->count(),
        ])->sortByDesc('value')->values()->all();

        $open = $items->whereNotIn('status', ['resuelta', 'ignorada'])->count();
        $resolved = $items->where('status', 'resuelta')->count();

        $byPriority = [
            ['label' => 'Crítica', 'value' => $items->where('priority', 'critica')->count()],
            ['label' => 'Alta', 'value' => $items->where('priority', 'alta')->count()],
            ['label' => 'Media', 'value' => $items->where('priority', 'media')->count()],
            ['label' => 'Baja', 'value' => $items->where('priority', 'baja')->count()],
        ];

        return [
            'top_categories' => $byCategory,
            'by_module' => $byModule,
            'by_priority' => $byPriority,
            'open_vs_resolved' => [
                ['label' => 'Abiertas', 'value' => $open],
                ['label' => 'Resueltas', 'value' => $resolved],
            ],
            'trend' => [
                ['label' => 'Periodo actual', 'value' => $items->count()],
                ['label' => 'Abiertas', 'value' => $open],
                ['label' => 'Resueltas', 'value' => $resolved],
            ],
            'gaps' => $this->gaps(),
            'truth' => 'proxy',
            'note' => 'Tendencia basada en stock de alertas del periodo (no serie histórica persistida).',
        ];
    }

    /**
     * Detalle (partial reload). Marca como vista si seguía Nueva.
     *
     * @return array<string, mixed>|null
     */
    public function buildDetail(string $alertId, Request $request): ?array
    {
        if ($alertId === '') {
            return null;
        }

        $filter = ActiveCampaignDashboardFilter::fromRequest($request);
        $overview = $this->dashboard->buildOverview($filter);
        $rawKey = $this->cacheKey($filter, 'raw-alerts');
        $items = collect(Cache::remember($rawKey, now()->addMinutes(5), function () use ($overview, $filter) {
            return $this->collectAlerts($overview, $filter, [], [])->all();
        }));

        $item = $items->firstWhere('id', $alertId);
        if (! $item) {
            return null;
        }

        $statuses = $this->statusMap();
        $seen = $this->seenIds();
        $item = $this->applySessionStatus($item, $statuses, $seen);

        if (($item['status'] ?? '') === 'nueva') {
            $this->setStatus($alertId, 'vista');
            $this->markSeen($alertId);
            $item['status'] = 'vista';
            $item['status_label'] = 'Vista';
        }

        $action = trim((string) $request->input('alert_action', ''));
        if (in_array($action, ['en_proceso', 'resuelta', 'ignorada', 'vista'], true)) {
            $this->setStatus($alertId, $action);
            $item['status'] = $action;
            $item['status_label'] = $this->statusLabel($action);
        }

        return [
            ...$item,
            'description_full' => $item['description'],
            'origin_detail' => $item['origin'],
            'related_module' => $item['module'],
            'related_event' => $item['related_event'] ?? 'No disponible',
            'impact' => $item['impact'] ?? 'No disponible',
            'suggested_actions' => $item['suggested_actions'] ?? [],
            'history' => $item['history'] ?? [
                ['label' => 'Detectada', 'at' => $item['date'].' '.$item['time'], 'truth' => 'proxy'],
                ['label' => 'Historión de estado persistente', 'at' => 'Requiere instrumentación', 'truth' => 'instrumentacion'],
            ],
            'quick_links' => $item['quick_links'] ?? [],
            'owner' => 'Próximamente',
            'owner_truth' => 'proximamente',
            'truth' => $item['truth'] ?? 'disponible',
        ];
    }

    private function cacheKey(ActiveCampaignDashboardFilter $filter, string $suffix): string
    {
        return 'mi-alerts:v1:'.sha1(json_encode($filter->toArray()).'|'.$suffix);
    }

    /**
     * @return array<string, mixed>
     */
    private function resolveFilters(Request $request): array
    {
        $dash = ActiveCampaignDashboardFilter::fromRequest($request)->toArray();

        return [
            'priority' => trim((string) $request->input('priority', '')),
            'origin' => trim((string) $request->input('origin', '')),
            'status' => trim((string) $request->input('status', '')),
            'category' => trim((string) $request->input('category', '')),
            'patient' => trim((string) $request->input('patient', '')),
            'start_date' => $dash['start_date'],
            'end_date' => $dash['end_date'],
        ];
    }

    /**
     * @return array<string, list<array{value: string, label: string}>>
     */
    private function filterOptions(): array
    {
        return [
            'priorities' => [
                ['value' => '', 'label' => 'Todas'],
                ['value' => 'critica', 'label' => 'Crítica'],
                ['value' => 'alta', 'label' => 'Alta'],
                ['value' => 'media', 'label' => 'Media'],
                ['value' => 'baja', 'label' => 'Baja'],
            ],
            'statuses' => [
                ['value' => '', 'label' => 'Todos'],
                ['value' => 'nueva', 'label' => 'Nueva'],
                ['value' => 'vista', 'label' => 'Vista'],
                ['value' => 'en_proceso', 'label' => 'En proceso'],
                ['value' => 'resuelta', 'label' => 'Resuelta'],
                ['value' => 'ignorada', 'label' => 'Ignorada'],
            ],
            'categories' => [
                ['value' => '', 'label' => 'Todas'],
                ['value' => 'operativa', 'label' => 'Operativas'],
                ['value' => 'comercial', 'label' => 'Comerciales'],
                ['value' => 'laboratorios', 'label' => 'Laboratorios'],
                ['value' => 'membresias', 'label' => 'Membresías'],
                ['value' => 'automatizaciones', 'label' => 'Automatizaciones'],
            ],
            'origins' => [
                ['value' => '', 'label' => 'Todos'],
                ['value' => 'Dashboard', 'label' => 'Dashboard'],
                ['value' => 'Event Center', 'label' => 'Event Center'],
                ['value' => 'Health Center', 'label' => 'Health Center'],
                ['value' => 'Automation Center', 'label' => 'Automation Center'],
                ['value' => 'Laboratory Intelligence', 'label' => 'Laboratory Intelligence'],
                ['value' => 'Membership Intelligence', 'label' => 'Membership Intelligence'],
                ['value' => 'Ecommerce Intelligence', 'label' => 'Ecommerce Intelligence'],
            ],
        ];
    }

    /**
     * @param  \Illuminate\Support\Collection<int, array<string, mixed>>  $items
     * @return list<array<string, mixed>>
     */
    private function buildSummary($items): array
    {
        $col = collect($items);
        $open = $col->whereNotIn('status', ['resuelta', 'ignorada']);

        return [
            [
                'id' => 'critical',
                'label' => 'Críticas',
                'value' => (string) $open->where('priority', 'critica')->count(),
                'tone' => 'red',
                'hint' => 'Requieren atención inmediata',
                'truth' => 'disponible',
            ],
            [
                'id' => 'warning',
                'label' => 'Advertencias',
                'value' => (string) $open->whereIn('priority', ['alta', 'media'])->count(),
                'tone' => 'amber',
                'hint' => 'Alta + media abiertas',
                'truth' => 'disponible',
            ],
            [
                'id' => 'info',
                'label' => 'Informativas',
                'value' => (string) $open->where('priority', 'baja')->count(),
                'tone' => 'sky',
                'hint' => 'Prioridad baja',
                'truth' => 'disponible',
            ],
            [
                'id' => 'resolved',
                'label' => 'Resueltas',
                'value' => (string) $col->where('status', 'resuelta')->count(),
                'tone' => 'zinc',
                'hint' => 'Cerradas o sync OK',
                'truth' => 'disponible',
            ],
            [
                'id' => 'avg_resolution',
                'label' => 'Tiempo prom. resolución',
                'value' => 'Requiere instrumentación',
                'tone' => 'zinc',
                'hint' => 'Sin timestamps de resolución persistidos',
                'truth' => 'instrumentacion',
            ],
        ];
    }

    /**
     * @param  array<string, mixed>  $overview
     * @param  array<string, string>  $statuses
     * @param  list<string>  $seen
     * @return \Illuminate\Support\Collection<int, array<string, mixed>>
     */
    private function collectAlerts(array $overview, ActiveCampaignDashboardFilter $filter, array $statuses, array $seen)
    {
        $items = collect();

        $items = $items->merge($this->fromFailedDispatches($statuses, $seen));
        $items = $items->merge($this->fromIntegration($overview, $statuses, $seen));
        $items = $items->merge($this->fromBacklog($overview, $statuses, $seen));
        $items = $items->merge($this->fromErrors($overview, $statuses, $seen));
        $items = $items->merge($this->fromCommercialKpis($overview, $statuses, $seen));
        $items = $items->merge($this->fromCartConversion($filter, $statuses, $seen));
        $items = $items->merge($this->fromLabPending($filter, $statuses, $seen));
        $items = $items->merge($this->fromMembershipSignals($filter, $statuses, $seen));
        $items = $items->merge($this->fromPausedAutomations($statuses, $seen));
        $items = $items->merge($this->fromRecentSynced($statuses, $seen));
        $items = $items->merge($this->instrumentationPlaceholders($statuses, $seen));

        return $items->sortByDesc(fn (array $a) => $a['occurred_at_sort'] ?? 0)->values();
    }

    /**
     * @param  list<string>  $seen
     * @param  array<string, string>  $statuses
     * @return \Illuminate\Support\Collection<int, array<string, mixed>>
     */
    private function fromFailedDispatches(array $statuses, array $seen)
    {
        return ActiveCampaignDispatch::query()
            ->where('status', ActiveCampaignDispatch::STATUS_FAILED)
            ->orderByDesc('updated_at')
            ->limit(15)
            ->get(['id', 'event_type', 'email', 'last_error', 'updated_at', 'customer_id'])
            ->map(function (ActiveCampaignDispatch $row) use ($statuses, $seen) {
                $id = 'alert-dispatch-failed-'.$row->id;

                return $this->alert(
                    id: $id,
                    priority: 'critica',
                    category: 'operativa',
                    title: 'Dispatch fallido: '.$row->event_type,
                    description: $this->shortError($row->last_error),
                    origin: 'Event Center',
                    module: 'Event Center / Automation',
                    patient: $row->email ?: '—',
                    patientEmail: $row->email,
                    contactId: $this->contactIdForDispatch($row),
                    at: $row->updated_at,
                    status: $this->resolveStatus($id, $statuses, $seen, 'nueva'),
                    relatedEvent: $row->event_type,
                    impact: 'Sincronización ActiveCampaign interrumpida para este evento.',
                    suggested: ['Abrir Event Center', 'Revisar Health Center', 'Automation · pipeline dispatches'],
                    links: [
                        ['label' => 'Event Center', 'href' => route('admin.activecampaign.events')],
                        ['label' => 'Health Center', 'href' => route('admin.activecampaign.health')],
                        ['label' => 'Automation', 'href' => route('admin.activecampaign.automations')],
                    ],
                    truth: 'disponible',
                );
            });
    }

    /**
     * @param  array<string, mixed>  $overview
     * @param  array<string, string>  $statuses
     * @param  list<string>  $seen
     * @return \Illuminate\Support\Collection<int, array<string, mixed>>
     */
    private function fromIntegration(array $overview, array $statuses, array $seen)
    {
        $health = collect($overview['health'])->keyBy('id');
        $integration = (string) ($health->get('integration')['value'] ?? '');
        if ($integration === '' || in_array($integration, ['Operativo'], true)) {
            return collect();
        }

        $id = 'alert-integration';

        return collect([
            $this->alert(
                id: $id,
                priority: 'critica',
                category: 'operativa',
                title: 'Integración desconectada / degradada',
                description: 'Estado reportado: '.$integration,
                origin: 'Health Center',
                module: 'Integrations Hub / Health',
                patient: '—',
                patientEmail: null,
                contactId: null,
                at: now(self::TZ),
                status: $this->resolveStatus($id, $statuses, $seen, 'nueva'),
                relatedEvent: 'integration_status',
                impact: 'Marketing sync y automatizaciones outbound pueden fallar.',
                suggested: ['Abrir Integrations Hub', 'Health Center', 'Configuración'],
                links: [
                    ['label' => 'Integrations Hub', 'href' => route('admin.activecampaign.integrations')],
                    ['label' => 'Health Center', 'href' => route('admin.activecampaign.health')],
                ],
                truth: 'disponible',
            ),
        ]);
    }

    /**
     * @param  array<string, mixed>  $overview
     * @param  array<string, string>  $statuses
     * @param  list<string>  $seen
     * @return \Illuminate\Support\Collection<int, array<string, mixed>>
     */
    private function fromBacklog(array $overview, array $statuses, array $seen)
    {
        $health = collect($overview['health'])->keyBy('id');
        $backlog = (int) preg_replace('/[^\d]/', '', (string) ($health->get('backlog')['value'] ?? '0'));
        if ($backlog <= 0) {
            return collect();
        }

        $id = 'alert-backlog';
        $priority = $backlog >= 50 ? 'critica' : 'alta';

        return collect([
            $this->alert(
                id: $id,
                priority: $priority,
                category: 'operativa',
                title: 'Cola elevada de dispatches',
                description: $backlog.' pendientes/procesando (Dashboard).',
                origin: 'Dashboard',
                module: 'Health Center',
                patient: '—',
                patientEmail: null,
                contactId: null,
                at: now(self::TZ),
                status: $this->resolveStatus($id, $statuses, $seen, 'nueva'),
                relatedEvent: 'backlog',
                impact: 'Retrasos en sync de créditos, promos y lifecycle.',
                suggested: ['Health Center', 'Event Center', 'Revisar workers'],
                links: [
                    ['label' => 'Health Center', 'href' => route('admin.activecampaign.health')],
                    ['label' => 'Event Center', 'href' => route('admin.activecampaign.events')],
                ],
                truth: 'disponible',
            ),
        ]);
    }

    /**
     * @param  array<string, mixed>  $overview
     * @param  array<string, string>  $statuses
     * @param  list<string>  $seen
     * @return \Illuminate\Support\Collection<int, array<string, mixed>>
     */
    private function fromErrors(array $overview, array $statuses, array $seen)
    {
        $health = collect($overview['health'])->keyBy('id');
        $errors = (int) preg_replace('/[^\d]/', '', (string) ($health->get('errors')['value'] ?? '0'));
        if ($errors <= 0) {
            return collect();
        }

        $id = 'alert-errors-period';

        return collect([
            $this->alert(
                id: $id,
                priority: 'alta',
                category: 'operativa',
                title: 'Errores de sync en el periodo',
                description: $errors.' dispatches failed (Dashboard).',
                origin: 'Dashboard',
                module: 'Event Center',
                patient: '—',
                patientEmail: null,
                contactId: null,
                at: now(self::TZ),
                status: $this->resolveStatus($id, $statuses, $seen, 'nueva'),
                relatedEvent: 'dispatch_failed_period',
                impact: 'Posible pérdida de señales de marketing.',
                suggested: ['Event Center', 'Logs', 'Health Center'],
                links: [
                    ['label' => 'Event Center', 'href' => route('admin.activecampaign.events')],
                    ['label' => 'Dashboard', 'href' => route('admin.activecampaign.dashboard')],
                ],
                truth: 'disponible',
            ),
        ]);
    }

    /**
     * @param  array<string, mixed>  $overview
     * @param  array<string, string>  $statuses
     * @param  list<string>  $seen
     * @return \Illuminate\Support\Collection<int, array<string, mixed>>
     */
    private function fromCommercialKpis(array $overview, array $statuses, array $seen)
    {
        $business = collect($overview['business'])->keyBy('id');
        $items = collect();

        $abandoned = $business->get('abandoned');
        if ($abandoned && ($abandoned['delta_direction'] ?? '') === 'up' && ($abandoned['delta_is_positive'] ?? null) === false) {
            $id = 'alert-abandon-up';
            $items->push($this->alert(
                id: $id,
                priority: 'alta',
                category: 'comercial',
                title: 'Incremento de abandono',
                description: 'Carritos tagged subieron vs periodo anterior ('.$abandoned['value_formatted'].').',
                origin: 'Dashboard',
                module: 'Ecommerce Intelligence',
                patient: '—',
                patientEmail: null,
                contactId: null,
                at: now(self::TZ),
                status: $this->resolveStatus($id, $statuses, $seen, 'nueva'),
                relatedEvent: 'cart_abandoned',
                impact: 'Pérdida potencial de conversión ecommerce.',
                suggested: ['Ecommerce Intelligence', 'Automation Center', 'Funnels'],
                links: [
                    ['label' => 'Ecommerce', 'href' => route('admin.activecampaign.ecommerce')],
                    ['label' => 'Automation', 'href' => route('admin.activecampaign.automations')],
                    ['label' => 'Funnels', 'href' => route('admin.activecampaign.funnels')],
                ],
                truth: 'disponible',
            ));
        }

        foreach (['lab' => 'Laboratorios', 'pharmacy' => 'Farmacia', 'membership' => 'Membresías'] as $kpiId => $label) {
            $kpi = $business->get($kpiId);
            if (! $kpi || ($kpi['delta_direction'] ?? '') !== 'down') {
                continue;
            }
            $id = 'alert-sales-down-'.$kpiId;
            $items->push($this->alert(
                id: $id,
                priority: 'media',
                category: 'comercial',
                title: 'Caída de ventas · '.$label,
                description: $label.' '.$kpi['value_formatted'].' ('.$kpi['delta_percent'].'% vs ant.).',
                origin: 'Dashboard',
                module: $kpiId === 'membership' ? 'Membership Intelligence' : ($kpiId === 'lab' ? 'Laboratory Intelligence' : 'Ecommerce Intelligence'),
                patient: '—',
                patientEmail: null,
                contactId: null,
                at: now(self::TZ),
                status: $this->resolveStatus($id, $statuses, $seen, 'nueva'),
                relatedEvent: $kpiId.'_down',
                impact: 'Volumen comercial por debajo del periodo comparable.',
                suggested: ['Abrir Analytics', 'Abrir consola del canal'],
                links: [
                    ['label' => 'Analytics', 'href' => route('admin.activecampaign.analytics')],
                    ['label' => 'Ecommerce', 'href' => route('admin.activecampaign.ecommerce')],
                ],
                truth: 'proxy',
            ));
        }

        return $items;
    }

    /**
     * @param  array<string, string>  $statuses
     * @param  list<string>  $seen
     * @return \Illuminate\Support\Collection<int, array<string, mixed>>
     */
    private function fromCartConversion(ActiveCampaignDashboardFilter $filter, array $statuses, array $seen)
    {
        $startS = $filter->start->toDateTimeString();
        $endS = $filter->end->toDateTimeString();
        $staleBefore = now()->subMinutes(Cart::ABANDONED_AFTER_MINUTES);

        $completed = (int) Cart::query()
            ->where('status', MonitoringCartStatus::Completed->value)
            ->where(function ($q) use ($startS, $endS) {
                $q->whereBetween('completed_at', [$startS, $endS])
                    ->orWhere(function ($f) use ($startS, $endS) {
                        $f->whereNull('completed_at')->whereBetween('updated_at', [$startS, $endS]);
                    });
            })
            ->count();

        $abandoned = (int) Cart::query()
            ->where('status', MonitoringCartStatus::Active->value)
            ->where('updated_at', '<', $staleBefore)
            ->whereBetween('updated_at', [$startS, $endS])
            ->count();

        $denom = $completed + $abandoned;
        if ($denom === 0) {
            return collect();
        }

        $rate = round(100 * $completed / $denom, 1);
        if ($rate >= 40) {
            return collect();
        }

        $id = 'alert-low-conversion';

        return collect([
            $this->alert(
                id: $id,
                priority: 'alta',
                category: 'comercial',
                title: 'Baja conversión carrito→compra',
                description: "Conversión {$rate}% (completados {$completed} / abandonados {$abandoned}).",
                origin: 'Ecommerce Intelligence',
                module: 'Ecommerce Intelligence',
                patient: '—',
                patientEmail: null,
                contactId: null,
                at: now(self::TZ),
                status: $this->resolveStatus($id, $statuses, $seen, 'nueva'),
                relatedEvent: 'cart_conversion',
                impact: 'Fuga en checkout / abandono elevado.',
                suggested: ['Ecommerce Intelligence', 'Funnels', 'Automation abandoned carts'],
                links: [
                    ['label' => 'Ecommerce', 'href' => route('admin.activecampaign.ecommerce')],
                    ['label' => 'Funnels', 'href' => route('admin.activecampaign.funnels')],
                ],
                truth: 'disponible',
            ),
        ]);
    }

    /**
     * @param  array<string, string>  $statuses
     * @param  list<string>  $seen
     * @return \Illuminate\Support\Collection<int, array<string, mixed>>
     */
    private function fromLabPending(ActiveCampaignDashboardFilter $filter, array $statuses, array $seen)
    {
        $expr = Schema::hasColumn('laboratory_purchases', 'paid_at')
            ? 'COALESCE(paid_at, completed_at, created_at)'
            : 'created_at';

        $q = LaboratoryPurchase::query()
            ->toBase()
            ->whereNull('deleted_at')
            ->whereRaw("{$expr} BETWEEN ? AND ?", [
                $filter->start->toDateTimeString(),
                $filter->end->toDateTimeString(),
            ]);

        if (Schema::hasColumn('laboratory_purchases', 'ready_at')) {
            $q->whereNull('results')->whereNull('ready_at');
        } else {
            $q->whereNull('results');
        }

        $pending = (int) $q->count();
        if ($pending <= 0) {
            return collect();
        }

        $id = 'alert-lab-pending-results';

        return collect([
            $this->alert(
                id: $id,
                priority: 'media',
                category: 'laboratorios',
                title: 'Resultados pendientes',
                description: $pending.' compras lab activas sin resultados listos en el periodo.',
                origin: 'Laboratory Intelligence',
                module: 'Laboratory Intelligence',
                patient: '—',
                patientEmail: null,
                contactId: null,
                at: now(self::TZ),
                status: $this->resolveStatus($id, $statuses, $seen, 'nueva'),
                relatedEvent: 'laboratory_results',
                impact: 'Experiencia paciente y ciclo resultados→factura retrasados.',
                suggested: ['Laboratory Intelligence', 'Event Center · laboratory_results'],
                links: [
                    ['label' => 'Laboratory Intelligence', 'href' => route('admin.activecampaign.laboratories')],
                    ['label' => 'Event Center', 'href' => route('admin.activecampaign.events')],
                ],
                truth: 'proxy',
            ),
        ]);
    }

    /**
     * @param  array<string, string>  $statuses
     * @param  list<string>  $seen
     * @return \Illuminate\Support\Collection<int, array<string, mixed>>
     */
    private function fromMembershipSignals(ActiveCampaignDashboardFilter $filter, array $statuses, array $seen)
    {
        $items = collect();
        $startS = $filter->start->toDateTimeString();
        $endS = $filter->end->toDateTimeString();

        $cancelled = (int) MedicalAttentionSubscription::query()
            ->withTrashed()
            ->toBase()
            ->whereNotNull('deleted_at')
            ->where('type', '!=', MedicalSubscriptionType::FAMILY_MEMBER->value)
            ->whereBetween('deleted_at', [$startS, $endS])
            ->count();

        if ($cancelled > 0) {
            $id = 'alert-membership-cancelled';
            $items->push($this->alert(
                id: $id,
                priority: 'media',
                category: 'membresias',
                title: 'Cancelaciones de membresía',
                description: $cancelled.' soft-deletes de titulares en el periodo (proxy de cancelación).',
                origin: 'Membership Intelligence',
                module: 'Membership Intelligence',
                patient: '—',
                patientEmail: null,
                contactId: null,
                at: now(self::TZ),
                status: $this->resolveStatus($id, $statuses, $seen, 'nueva'),
                relatedEvent: 'membership',
                impact: 'Churn / pérdida de retención.',
                suggested: ['Membership Intelligence', 'Customer Journey'],
                links: [
                    ['label' => 'Membership Intelligence', 'href' => route('admin.activecampaign.memberships')],
                    ['label' => 'Journey', 'href' => route('admin.activecampaign.customer-journey')],
                ],
                truth: 'proxy',
            ));
        }

        $expiring = (int) MedicalAttentionSubscription::query()
            ->toBase()
            ->whereNull('deleted_at')
            ->where('type', '!=', MedicalSubscriptionType::FAMILY_MEMBER->value)
            ->whereBetween('end_date', [now()->toDateString(), now()->addDays(14)->toDateString()])
            ->count();

        if ($expiring > 0) {
            $id = 'alert-membership-expiring';
            $items->push($this->alert(
                id: $id,
                priority: 'media',
                category: 'membresias',
                title: 'Vencimientos próximos',
                description: $expiring.' membresías titulares vencen en los próximos 14 días.',
                origin: 'Membership Intelligence',
                module: 'Membership Intelligence',
                patient: '—',
                patientEmail: null,
                contactId: null,
                at: now(self::TZ),
                status: $this->resolveStatus($id, $statuses, $seen, 'nueva'),
                relatedEvent: 'membership',
                impact: 'Oportunidad de renovación / riesgo de baja.',
                suggested: ['Membership Intelligence', 'Automation Center'],
                links: [
                    ['label' => 'Membership Intelligence', 'href' => route('admin.activecampaign.memberships')],
                    ['label' => 'Automation', 'href' => route('admin.activecampaign.automations')],
                ],
                truth: 'disponible',
            ));
        }

        return $items;
    }

    /**
     * @param  array<string, string>  $statuses
     * @param  list<string>  $seen
     * @return \Illuminate\Support\Collection<int, array<string, mixed>>
     */
    private function fromPausedAutomations(array $statuses, array $seen)
    {
        $items = collect();

        if (! config('services.activecampaign.tag_abandoned_carts_enabled', true)) {
            $id = 'alert-automation-paused-abandoned';
            $items->push($this->alert(
                id: $id,
                priority: 'baja',
                category: 'automatizaciones',
                title: 'Workflow detenido: carritos abandonados',
                description: 'Flag tag_abandoned_carts_enabled desactivado.',
                origin: 'Automation Center',
                module: 'Automation Center',
                patient: '—',
                patientEmail: null,
                contactId: null,
                at: now(self::TZ),
                status: $this->resolveStatus($id, $statuses, $seen, 'nueva'),
                relatedEvent: 'abandoned_carts',
                impact: 'No se etiqueta abandono hacia ActiveCampaign.',
                suggested: ['Abrir Automation Center'],
                links: [
                    ['label' => 'Automation', 'href' => route('admin.activecampaign.automations')],
                ],
                truth: 'disponible',
            ));
        }

        return $items;
    }

    /**
     * @param  array<string, string>  $statuses
     * @param  list<string>  $seen
     * @return \Illuminate\Support\Collection<int, array<string, mixed>>
     */
    private function fromRecentSynced(array $statuses, array $seen)
    {
        return ActiveCampaignDispatch::query()
            ->where('status', ActiveCampaignDispatch::STATUS_SYNCED)
            ->whereNotNull('synced_at')
            ->orderByDesc('synced_at')
            ->limit(5)
            ->get(['id', 'event_type', 'email', 'synced_at', 'customer_id'])
            ->map(function (ActiveCampaignDispatch $row) use ($statuses, $seen) {
                $id = 'alert-dispatch-synced-'.$row->id;

                return $this->alert(
                    id: $id,
                    priority: 'baja',
                    category: 'operativa',
                    title: 'Sync completado: '.$row->event_type,
                    description: 'Dispatch sincronizado correctamente.',
                    origin: 'Event Center',
                    module: 'Event Center',
                    patient: $row->email ?: '—',
                    patientEmail: $row->email,
                    contactId: $this->contactIdForDispatch($row),
                    at: $row->synced_at,
                    status: 'resuelta',
                    relatedEvent: $row->event_type,
                    impact: 'Sin impacto negativo.',
                    suggested: ['Event Center'],
                    links: [
                        ['label' => 'Event Center', 'href' => route('admin.activecampaign.events')],
                    ],
                    truth: 'disponible',
                );
            });
    }

    /**
     * Placeholders honestos para capacidades aún no instrumentadas.
     *
     * @param  array<string, string>  $statuses
     * @param  list<string>  $seen
     * @return \Illuminate\Support\Collection<int, array<string, mixed>>
     */
    private function instrumentationPlaceholders(array $statuses, array $seen)
    {
        $defs = [
            [
                'id' => 'alert-scheduler-down',
                'priority' => 'media',
                'category' => 'operativa',
                'title' => 'Scheduler detenido',
                'description' => 'Monitoreo de scheduler/horizon aún no instrumentado en MI.',
                'origin' => 'Health Center',
                'module' => 'Health Center',
                'truth' => 'instrumentacion',
            ],
            [
                'id' => 'alert-lab-sla',
                'priority' => 'baja',
                'category' => 'laboratorios',
                'title' => 'Alto tiempo de entrega (SLA resultados)',
                'description' => 'SLA de entrega de resultados requiere instrumentación de tiempos.',
                'origin' => 'Laboratory Intelligence',
                'module' => 'Laboratory Intelligence',
                'truth' => 'instrumentacion',
            ],
            [
                'id' => 'alert-membership-retention',
                'priority' => 'baja',
                'category' => 'membresias',
                'title' => 'Baja retención (cohort)',
                'description' => 'Retención cohort formal requiere definición de renovación.',
                'origin' => 'Membership Intelligence',
                'module' => 'Membership Intelligence',
                'truth' => 'instrumentacion',
            ],
        ];

        // Solo mostrar placeholders si el usuario filtra por esa categoría o pide ver instrumentación — 
        // Mejor: siempre incluirlos como informativos de baja prioridad para cumplir el catálogo de tipos.
        return collect($defs)->map(function (array $d) use ($statuses, $seen) {
            return $this->alert(
                id: $d['id'],
                priority: $d['priority'],
                category: $d['category'],
                title: $d['title'],
                description: $d['description'],
                origin: $d['origin'],
                module: $d['module'],
                patient: '—',
                patientEmail: null,
                contactId: null,
                at: now(self::TZ),
                status: $this->resolveStatus($d['id'], $statuses, $seen, 'nueva'),
                relatedEvent: 'pending_instrumentation',
                impact: 'Visibilidad incompleta para Dirección.',
                suggested: ['Revisar gaps del módulo origen'],
                links: [
                    ['label' => 'Health Center', 'href' => route('admin.activecampaign.health')],
                ],
                truth: $d['truth'],
            );
        });
    }

    /**
     * @param  \Illuminate\Support\Collection<int, array<string, mixed>>  $items
     * @param  array<string, mixed>  $filters
     * @return \Illuminate\Support\Collection<int, array<string, mixed>>
     */
    private function applyFilters($items, array $filters)
    {
        return $items->filter(function (array $a) use ($filters) {
            if ($filters['priority'] !== '' && ($a['priority'] ?? '') !== $filters['priority']) {
                return false;
            }
            if ($filters['status'] !== '' && ($a['status'] ?? '') !== $filters['status']) {
                return false;
            }
            if ($filters['category'] !== '' && ($a['category'] ?? '') !== $filters['category']) {
                return false;
            }
            if ($filters['origin'] !== '' && ! str_contains((string) ($a['origin'] ?? ''), $filters['origin'])) {
                return false;
            }
            if ($filters['patient'] !== '') {
                $hay = strtolower(($a['patient'] ?? '').' '.($a['patient_email'] ?? ''));
                if (! str_contains($hay, strtolower($filters['patient']))) {
                    return false;
                }
            }

            return true;
        });
    }

    /**
     * @param  array<string, mixed>  $a
     * @return array<string, mixed>
     */
    private function listItem(array $a): array
    {
        return [
            'id' => $a['id'],
            'priority' => $a['priority'],
            'priority_label' => $a['priority_label'],
            'category' => $a['category'],
            'category_label' => $a['category_label'],
            'title' => $a['title'],
            'description' => $a['description'],
            'origin' => $a['origin'],
            'module' => $a['module'],
            'patient' => $a['patient'],
            'patient_email' => $a['patient_email'],
            'contact_id' => $a['contact_id'],
            'date' => $a['date'],
            'time' => $a['time'],
            'status' => $a['status'],
            'status_label' => $a['status_label'],
            'owner' => 'Próximamente',
            'owner_truth' => 'proximamente',
            'truth' => $a['truth'],
        ];
    }

    /**
     * @param  list<array{label: string, href: string}>  $links
     * @param  list<string>  $suggested
     * @return array<string, mixed>
     */
    private function alert(
        string $id,
        string $priority,
        string $category,
        string $title,
        string $description,
        string $origin,
        string $module,
        string $patient,
        ?string $patientEmail,
        ?int $contactId,
        mixed $at,
        string $status,
        string $relatedEvent,
        string $impact,
        array $suggested,
        array $links,
        string $truth,
    ): array {
        $carbon = $at instanceof Carbon ? $at->copy() : Carbon::parse($at);
        $local = $carbon->timezone(self::TZ);

        return [
            'id' => $id,
            'priority' => $priority,
            'priority_label' => match ($priority) {
                'critica' => 'Crítica',
                'alta' => 'Alta',
                'media' => 'Media',
                default => 'Baja',
            },
            'category' => $category,
            'category_label' => $this->categoryLabel($category),
            'title' => $title,
            'description' => $description,
            'origin' => $origin,
            'module' => $module,
            'patient' => $patient,
            'patient_email' => $patientEmail,
            'contact_id' => $contactId,
            'occurred_at' => $local->toIso8601String(),
            'occurred_at_sort' => $local->timestamp,
            'date' => $local->format('d/m/Y'),
            'time' => $local->format('H:i'),
            'status' => $status,
            'status_label' => $this->statusLabel($status),
            'related_event' => $relatedEvent,
            'impact' => $impact,
            'suggested_actions' => $suggested,
            'quick_links' => $links,
            'truth' => $truth,
        ];
    }

    private function categoryLabel(string $category): string
    {
        return match ($category) {
            'operativa' => 'Operativas',
            'comercial' => 'Comerciales',
            'laboratorios' => 'Laboratorios',
            'membresias' => 'Membresías',
            'automatizaciones' => 'Automatizaciones',
            default => $category,
        };
    }

    private function statusLabel(string $status): string
    {
        return match ($status) {
            'nueva' => 'Nueva',
            'vista' => 'Vista',
            'en_proceso' => 'En proceso',
            'resuelta' => 'Resuelta',
            'ignorada' => 'Ignorada',
            default => $status,
        };
    }

    /**
     * @param  array<string, string>  $statuses
     * @param  list<string>  $seen
     */
    private function resolveStatus(string $id, array $statuses, array $seen, string $default): string
    {
        if (isset($statuses[$id])) {
            return $statuses[$id];
        }
        if ($default === 'resuelta') {
            return 'resuelta';
        }

        return in_array($id, $seen, true) ? 'vista' : $default;
    }

    /**
     * @param  array<string, mixed>  $alert
     * @param  array<string, string>  $statuses
     * @param  list<string>  $seen
     * @return array<string, mixed>
     */
    private function applySessionStatus(array $alert, array $statuses, array $seen): array
    {
        if (($alert['status'] ?? '') === 'resuelta' && ! isset($statuses[$alert['id']])) {
            return $alert;
        }

        $status = $this->resolveStatus($alert['id'], $statuses, $seen, $alert['status'] ?? 'nueva');
        $alert['status'] = $status;
        $alert['status_label'] = $this->statusLabel($status);

        return $alert;
    }

    /**
     * @return array<string, string>
     */
    private function statusMap(): array
    {
        $map = Session::get(self::SESSION_STATUS, []);

        return is_array($map) ? array_filter($map, 'is_string') : [];
    }

    private function setStatus(string $id, string $status): void
    {
        $map = $this->statusMap();
        $map[$id] = $status;
        if (count($map) > 300) {
            $map = array_slice($map, -300, null, true);
        }
        Session::put(self::SESSION_STATUS, $map);
    }

    /**
     * @return list<string>
     */
    private function seenIds(): array
    {
        $ids = Session::get(self::SESSION_SEEN, []);

        return is_array($ids) ? array_values(array_filter($ids, 'is_string')) : [];
    }

    private function markSeen(string $id): void
    {
        $ids = $this->seenIds();
        if (! in_array($id, $ids, true)) {
            $ids[] = $id;
            $ids = array_slice($ids, -200);
            Session::put(self::SESSION_SEEN, $ids);
        }
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function quickActions(): array
    {
        return [
            ['id' => 'health', 'label' => 'Health Center', 'href' => route('admin.activecampaign.health'), 'enabled' => true],
            ['id' => 'events', 'label' => 'Event Center', 'href' => route('admin.activecampaign.events'), 'enabled' => true],
            ['id' => 'ecommerce', 'label' => 'Ecommerce', 'href' => route('admin.activecampaign.ecommerce'), 'enabled' => true],
            ['id' => 'lab', 'label' => 'Laboratory', 'href' => route('admin.activecampaign.laboratories'), 'enabled' => true],
            ['id' => 'membership', 'label' => 'Membership', 'href' => route('admin.activecampaign.memberships'), 'enabled' => true],
            ['id' => 'automation', 'label' => 'Automation', 'href' => route('admin.activecampaign.automations'), 'enabled' => true],
            ['id' => 'notifications', 'label' => 'Notification Center', 'href' => route('admin.activecampaign.notifications'), 'enabled' => true],
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function gaps(): array
    {
        return [
            [
                'label' => 'Tiempo promedio de resolución',
                'reason' => 'No hay timestamps de cierre persistidos (solo sesión).',
                'truth' => 'instrumentacion',
            ],
            [
                'label' => 'Responsable / asignación',
                'reason' => 'Sin modelo de ownership.',
                'truth' => 'proximamente',
            ],
            [
                'label' => 'Scheduler / Horizon down',
                'reason' => 'Señal de infraestructura no cableada a MI.',
                'truth' => 'instrumentacion',
            ],
            [
                'label' => 'SLA resultados laboratorio',
                'reason' => 'Falta métrica de tiempo de entrega agregada.',
                'truth' => 'instrumentacion',
            ],
        ];
    }

    private function contactIdForDispatch(ActiveCampaignDispatch $dispatch): ?int
    {
        if ($dispatch->customer_id) {
            $id = Contact::query()->where('customer_id', $dispatch->customer_id)->value('id');
            if ($id) {
                return (int) $id;
            }
        }

        return null;
    }

    private function shortError(?string $error): string
    {
        if ($error === null || trim($error) === '') {
            return 'No disponible';
        }
        $line = trim(explode("\n", $error)[0] ?? '');
        $line = preg_replace('/\s+/', ' ', $line) ?? $line;

        return mb_strlen($line) > 140 ? mb_substr($line, 0, 137).'…' : $line;
    }
}
