<?php

namespace App\Services\ActiveCampaign;

use App\DataTransferObjects\ActiveCampaign\Operations\ExecutiveKpiDto;
use App\DataTransferObjects\ActiveCampaign\Operations\FunnelStageDto;
use App\DataTransferObjects\ActiveCampaign\Operations\OperationsAlertDto;
use App\DataTransferObjects\ActiveCampaign\Operations\OperationsPlatformDto;
use App\Models\ActiveCampaignDispatch;
use App\Models\Contact;
use App\Models\Customer;
use App\Models\LaboratoryPurchase;
use App\Models\MedicalAttentionSubscription;
use App\Models\OnlinePharmacyPurchase;
use App\Models\User;
use App\Support\ActiveCampaign\ActiveCampaignDashboardFilter;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 2 — plataforma de operaciones enterprise.
 * Orquesta inteligencia existente + métricas propias. No modifica Mirror/Drawer/MI screens.
 */
class ActiveCampaignOperationsPlatformService
{
    private const TZ = 'America/Monterrey';

    public function __construct(
        protected ActiveCampaignOperationsService $operations,
        protected ActiveCampaignLaboratoryIntelligenceService $laboratories,
        protected ActiveCampaignMembershipIntelligenceService $memberships,
        protected ActiveCampaignEcommerceIntelligenceService $ecommerce,
        protected ActiveCampaignAutomationCenterService $automations,
        protected ActiveCampaignCacheService $cache,
        protected ActiveCampaignService $activeCampaign,
    ) {}

    public function build(Request $request): OperationsPlatformDto
    {
        $this->applyPresetDates($request);
        $filter = ActiveCampaignDashboardFilter::fromRequest($request);

        $cacheKey = 'ac-ops-platform:v1:'.sha1(json_encode([
            ...$filter->toArray(),
            'lab' => $request->input('laboratory'),
            'membership' => $request->input('membership'),
            'owner' => $request->input('owner'),
            'purchase_type' => $request->input('purchase_type'),
            'q' => $request->input('q'),
        ]));

        if ($filter->bustCache || $request->boolean('refresh')) {
            Cache::forget($cacheKey);
        }

        /** @var array<string, mixed> $payload */
        $payload = Cache::remember($cacheKey, now()->addMinutes(2), function () use ($request, $filter) {
            $opsHealth = $this->operations->health();
            $opsSync = $this->operations->synchronization();
            $opsMirror = $this->operations->mirror();
            $opsIntel = $this->operations->contactIntelligence();

            $labCore = $this->laboratories->build($request);
            $labCharts = $this->laboratories->buildCharts($request);
            $memCore = $this->memberships->build($request);
            $ecomCore = $this->ecommerce->build($request);
            $ecomCharts = $this->ecommerce->buildCharts($request);
            $autoDash = $this->automations->buildDashboard($filter);
            $autoList = $this->automations->buildList();

            $executive = $this->buildExecutive($filter, $opsSync, $opsMirror, $ecomCore, $memCore);
            $funnel = $this->buildFunnel($filter);
            $labs = $this->mapLaboratories($labCore['top_laboratories'] ?? []);
            $memberships = $this->mapMemberships($memCore);
            $purchases = $this->mapPurchases($ecomCore, $ecomCharts);
            $automations = $this->mapAutomations($autoList, $autoDash);
            $contactHealth = $this->buildContactHealth($opsIntel);
            $alerts = $this->buildAlerts($opsHealth, $opsSync, $opsMirror, $filter);
            $analytics = $this->buildAnalytics($filter, $labCharts, $ecomCharts, $opsSync, $contactHealth);

            return [
                'executive' => $executive,
                'funnel' => $funnel,
                'laboratories' => $labs,
                'memberships' => $memberships,
                'purchases' => $purchases,
                'automations' => $automations,
                'contact_health' => $contactHealth,
                'alerts' => $alerts,
                'analytics' => $analytics,
            ];
        });

        $searchResults = null;
        if ($request->filled('q')) {
            $searchResults = $this->searchGlobal((string) $request->input('q'));
        }

        return new OperationsPlatformDto(
            executive: $payload['executive'],
            funnel: $payload['funnel'],
            laboratories: $payload['laboratories'],
            memberships: $payload['memberships'],
            purchases: $payload['purchases'],
            automations: $payload['automations'],
            contactHealth: $payload['contact_health'],
            alerts: $payload['alerts'],
            analytics: $payload['analytics'],
            filters: [
                ...$filter->toArray(),
                'preset' => (string) $request->input('preset', '7d'),
                'laboratory' => $request->input('laboratory'),
                'branch' => $request->input('branch'),
                'purchase_type' => $request->input('purchase_type'),
                'membership' => $request->input('membership'),
                'owner' => $request->input('owner'),
                'q' => $request->input('q'),
                'presets' => [
                    ['key' => 'today', 'label' => 'Hoy'],
                    ['key' => '7d', 'label' => '7 días'],
                    ['key' => '30d', 'label' => '30 días'],
                    ['key' => '90d', 'label' => '90 días'],
                    ['key' => 'custom', 'label' => 'Personalizado'],
                ],
            ],
            searchResults: $searchResults,
            generatedAt: now()->timezone(self::TZ)->format('d/m/Y H:i:s'),
        );
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function searchGlobal(string $query): array
    {
        $q = trim($query);
        if ($q === '' || mb_strlen($q) < 2) {
            return [];
        }

        $results = [];

        if (filter_var($q, FILTER_VALIDATE_EMAIL)) {
            $user = User::query()->where('email', $q)->first();
            if ($user) {
                $customer = Customer::query()->where('user_id', $user->id)->first();
                $results[] = [
                    'type' => 'email',
                    'label' => $user->email,
                    'meta' => 'Customer #'.($customer?->id ?? '—'),
                    'id' => $customer?->id,
                ];
            }
            try {
                $ac = $this->activeCampaign->findContactByEmail($q);
                if ($ac) {
                    $results[] = [
                        'type' => 'ac_contact',
                        'label' => $ac['email'] ?? $q,
                        'meta' => 'AC #'.($ac['id'] ?? '—'),
                        'id' => $ac['id'] ?? null,
                    ];
                }
            } catch (\Throwable) {
                // ignore
            }
        }

        if (ctype_digit($q)) {
            $id = (int) $q;
            $dispatch = ActiveCampaignDispatch::query()->find($id);
            if ($dispatch) {
                $results[] = [
                    'type' => 'dispatch',
                    'label' => "Dispatch #{$dispatch->id}",
                    'meta' => $dispatch->event_type.' · '.$dispatch->status,
                    'id' => $dispatch->id,
                ];
            }
            $lab = LaboratoryPurchase::query()->find($id);
            if ($lab) {
                $results[] = [
                    'type' => 'order',
                    'label' => "Lab order #{$lab->id}",
                    'meta' => ($lab->brand?->label() ?? 'Lab').' · '.number_format(($lab->total_cents ?? 0) / 100, 2),
                    'id' => $lab->id,
                ];
            }
        }

        $contacts = Contact::query()
            ->where(function ($query) use ($q) {
                $query->where('name', 'like', "%{$q}%")
                    ->orWhere('paternal_lastname', 'like', "%{$q}%")
                    ->orWhere('maternal_lastname', 'like', "%{$q}%");
            })
            ->limit(5)
            ->get(['id', 'name', 'paternal_lastname', 'maternal_lastname', 'customer_id']);

        foreach ($contacts as $contact) {
            $results[] = [
                'type' => 'name',
                'label' => trim($contact->name.' '.$contact->paternal_lastname.' '.$contact->maternal_lastname),
                'meta' => 'Contact #'.$contact->id,
                'id' => $contact->id,
            ];
        }

        $tagHits = ActiveCampaignDispatch::query()
            ->where('event_type', 'like', '%tag%')
            ->where(function ($query) use ($q) {
                $query->where('email', 'like', "%{$q}%")
                    ->orWhere('payload', 'like', "%{$q}%");
            })
            ->limit(3)
            ->get(['id', 'event_type', 'email']);

        foreach ($tagHits as $hit) {
            $results[] = [
                'type' => 'tag',
                'label' => $hit->event_type,
                'meta' => $hit->email ?: ('Dispatch #'.$hit->id),
                'id' => $hit->id,
            ];
        }

        $needle = mb_strtolower($q);
        foreach ($this->automations->buildList()['items'] ?? [] as $automation) {
            $name = (string) ($automation['name'] ?? '');
            $id = (string) ($automation['id'] ?? '');
            if ($name !== '' && (str_contains(mb_strtolower($name), $needle) || str_contains(mb_strtolower($id), $needle))) {
                $results[] = [
                    'type' => 'automation',
                    'label' => $name,
                    'meta' => ($automation['status_label'] ?? $automation['status'] ?? '—').' · '.($automation['trigger_label'] ?? ''),
                    'id' => $id,
                ];
            }
        }

        return array_slice($results, 0, 20);
    }

    /**
     * @return array{filename: string, rows: list<list<string|int|float|null>>, headers: list<string>}
     */
    public function exportRows(Request $request, string $dataset): array
    {
        $platform = $this->build($request)->toArray();

        return match ($dataset) {
            'executive' => [
                'filename' => 'ac-ops-executive',
                'headers' => ['KPI', 'Valor', 'Anterior', 'Crecimiento %', 'Tendencia'],
                'rows' => collect($platform['executive'])->map(fn ($r) => [
                    $r['label'], $r['value'], $r['previous_value'], $r['growth_percent'], $r['trend'],
                ])->all(),
            ],
            'laboratories' => [
                'filename' => 'ac-ops-laboratories',
                'headers' => ['Laboratorio', 'Compras', 'Monto', 'Resultados', 'Conversión', 'Abandonados'],
                'rows' => collect($platform['laboratories'])->map(fn ($r) => [
                    $r['laboratory'], $r['orders'], $r['amount'], $r['results'], $r['conversion'], $r['abandoned'],
                ])->all(),
            ],
            'funnel' => [
                'filename' => 'ac-ops-funnel',
                'headers' => ['Etapa', 'Cantidad', 'Conversión %', 'Abandono %'],
                'rows' => collect($platform['funnel'])->map(fn ($r) => [
                    $r['label'], $r['count'], $r['conversion_percent'], $r['dropoff_percent'],
                ])->all(),
            ],
            'alerts' => [
                'filename' => 'ac-ops-alerts',
                'headers' => ['Prioridad', 'Título', 'Mensaje'],
                'rows' => collect($platform['alerts'])->map(fn ($r) => [
                    $r['priority'], $r['title'], $r['message'],
                ])->all(),
            ],
            default => [
                'filename' => 'ac-ops-activity',
                'headers' => ['Tipo', 'Estado', 'Descripción'],
                'rows' => collect($this->operations->activity(100))->map(fn ($r) => [
                    $r['type_label'], $r['status_label'], $r['description'],
                ])->all(),
            ],
        };
    }

    protected function applyPresetDates(Request $request): void
    {
        if ($request->filled('start_date') && $request->filled('end_date') && $request->input('preset') === 'custom') {
            return;
        }

        $tz = self::TZ;
        $end = Carbon::now($tz)->endOfDay();
        $preset = (string) $request->input('preset', '7d');

        $start = match ($preset) {
            'today' => $end->copy()->startOfDay(),
            '30d' => $end->copy()->subDays(29)->startOfDay(),
            '90d' => $end->copy()->subDays(89)->startOfDay(),
            '7d' => $end->copy()->subDays(6)->startOfDay(),
            default => $request->filled('start_date')
                ? Carbon::parse((string) $request->input('start_date'), $tz)->startOfDay()
                : $end->copy()->subDays(6)->startOfDay(),
        };

        $request->merge([
            'start_date' => $start->toDateString(),
            'end_date' => $end->toDateString(),
            'preset' => $preset !== '' ? $preset : '7d',
        ]);
    }

    /**
     * @param  array<string, mixed>  $opsSync
     * @param  array<string, mixed>  $opsMirror
     * @param  array<string, mixed>  $ecomCore
     * @param  array<string, mixed>  $memCore
     * @return list<array<string, mixed>>
     */
    protected function buildExecutive(
        ActiveCampaignDashboardFilter $filter,
        array $opsSync,
        array $opsMirror,
        array $ecomCore,
        array $memCore,
    ): array {
        $todayStart = now()->timezone(self::TZ)->startOfDay()->utc();
        $yesterdayStart = now()->timezone(self::TZ)->subDay()->startOfDay()->utc();
        $yesterdayEnd = now()->timezone(self::TZ)->subDay()->endOfDay()->utc();

        $contactsTotal = Customer::query()->count();
        $syncedToday = (int) ($opsMirror['synced_today'] ?? 0);
        $syncedYesterday = Customer::query()
            ->whereNotNull('ac_last_sync_at')
            ->whereBetween('ac_last_sync_at', [$yesterdayStart, $yesterdayEnd])
            ->count();

        $purchasesToday = ActiveCampaignDispatch::query()
            ->where('created_at', '>=', $todayStart)
            ->where(function ($q) {
                $q->where('event_type', 'like', '%purchase%')
                    ->orWhere('event_type', 'like', '%order%');
            })
            ->where('status', ActiveCampaignDispatch::STATUS_SYNCED)
            ->count();

        $purchasesYesterday = ActiveCampaignDispatch::query()
            ->whereBetween('created_at', [$yesterdayStart, $yesterdayEnd])
            ->where(function ($q) {
                $q->where('event_type', 'like', '%purchase%')
                    ->orWhere('event_type', 'like', '%order%');
            })
            ->where('status', ActiveCampaignDispatch::STATUS_SYNCED)
            ->count();

        $abandoned = 0;
        $abandonedPrev = 0;
        if (Schema::hasTable('laboratory_cart_items') || Schema::hasTable('carts')) {
            // Proxy: dispatches cart abandoned
            $abandoned = ActiveCampaignDispatch::query()
                ->where('created_at', '>=', $todayStart)
                ->where('event_type', 'like', '%abandon%')
                ->count();
            $abandonedPrev = ActiveCampaignDispatch::query()
                ->whereBetween('created_at', [$yesterdayStart, $yesterdayEnd])
                ->where('event_type', 'like', '%abandon%')
                ->count();
        }

        $membershipsToday = ActiveCampaignDispatch::query()
            ->where('created_at', '>=', $todayStart)
            ->where('event_type', 'like', '%membership%')
            ->where('status', ActiveCampaignDispatch::STATUS_SYNCED)
            ->count();
        $membershipsYesterday = ActiveCampaignDispatch::query()
            ->whereBetween('created_at', [$yesterdayStart, $yesterdayEnd])
            ->where('event_type', 'like', '%membership%')
            ->where('status', ActiveCampaignDispatch::STATUS_SYNCED)
            ->count();

        $webhooks = 0; // inbound no instrumentado
        $automationsFired = ActiveCampaignDispatch::query()
            ->where('created_at', '>=', $filter->start)
            ->where('created_at', '<=', $filter->end)
            ->count();
        $automationsPrev = ActiveCampaignDispatch::query()
            ->whereBetween('created_at', [$filter->previousStart, $filter->previousEnd])
            ->count();

        $errorsToday = (int) ($opsSync['failed'] ?? 0);
        $errorsYesterday = ActiveCampaignDispatch::query()
            ->whereBetween('created_at', [$yesterdayStart, $yesterdayEnd])
            ->where('status', ActiveCampaignDispatch::STATUS_FAILED)
            ->count();

        $sparkContacts = $this->dailySeriesCount(
            Customer::query(),
            'created_at',
            $filter->start,
            $filter->end
        );

        $kpis = [
            $this->kpi('contacts_total', 'Contactos totales', $contactsTotal, null, $sparkContacts, 'sky'),
            $this->kpi('synced_today', 'Sincronizados hoy', $syncedToday, $syncedYesterday, $this->lastDaysDispatchSpark('%'), 'emerald', 'Customers con ac_last_sync_at hoy'),
            $this->kpi('purchases_today', 'Compras enviadas hoy', $purchasesToday, $purchasesYesterday, $this->lastDaysDispatchSpark('%purchase%'), 'emerald'),
            $this->kpi('abandoned', 'Carritos abandonados', $abandoned, $abandonedPrev, $this->lastDaysDispatchSpark('%abandon%'), 'amber'),
            $this->kpi('memberships', 'Membresías activadas', $membershipsToday, $membershipsYesterday, $this->lastDaysDispatchSpark('%membership%'), 'sky'),
            $this->kpi('webhooks', 'Webhooks recibidos', $webhooks, 0, array_fill(0, 7, 0), 'default', 'Inbound aún no instrumentado'),
            $this->kpi('automations', 'Automatizaciones disparadas', $automationsFired, $automationsPrev, $this->lastDaysDispatchSpark(null), 'sky', 'Dispatches en el periodo filtrado'),
            $this->kpi('api_errors', 'Errores API', $errorsToday, $errorsYesterday, $this->lastDaysDispatchSpark(null, ActiveCampaignDispatch::STATUS_FAILED), 'rose'),
        ];

        return array_map(static fn (ExecutiveKpiDto $dto) => $dto->toArray(), $kpis);
    }

    /**
     * @return list<array<string, mixed>>
     */
    protected function buildFunnel(ActiveCampaignDashboardFilter $filter): array
    {
        $registrations = User::query()
            ->whereBetween('created_at', [$filter->start, $filter->end])
            ->count();

        $synced = Customer::query()
            ->whereNotNull('ac_contact_id')
            ->whereBetween('ac_last_sync_at', [$filter->start, $filter->end])
            ->count();

        $qualified = (int) ActiveCampaignDispatch::query()
            ->whereBetween('created_at', [$filter->start, $filter->end])
            ->where(function ($q) {
                $q->where('event_type', 'like', '%tag%')
                    ->orWhere('event_type', 'like', '%credit%');
            })
            ->whereNotNull('customer_id')
            ->selectRaw('COUNT(DISTINCT customer_id) as aggregate')
            ->value('aggregate');

        $purchases = LaboratoryPurchase::query()
            ->whereNull('deleted_at')
            ->whereBetween('created_at', [$filter->start, $filter->end])
            ->count()
            + OnlinePharmacyPurchase::query()
                ->whereNull('deleted_at')
                ->whereBetween('created_at', [$filter->start, $filter->end])
                ->count();

        $clients = (int) LaboratoryPurchase::query()
            ->whereNull('deleted_at')
            ->whereBetween('created_at', [$filter->start, $filter->end])
            ->whereNotNull('customer_id')
            ->selectRaw('COUNT(DISTINCT customer_id) as aggregate')
            ->value('aggregate');

        $memberships = MedicalAttentionSubscription::query()
            ->whereNull('deleted_at')
            ->whereBetween('created_at', [$filter->start, $filter->end])
            ->count();

        $repurchaseCustomers = (int) LaboratoryPurchase::query()
            ->whereNull('deleted_at')
            ->whereBetween('created_at', [$filter->start, $filter->end])
            ->whereNotNull('customer_id')
            ->select('customer_id')
            ->groupBy('customer_id')
            ->havingRaw('COUNT(*) > 1')
            ->get()
            ->count();

        $stages = [
            new FunnelStageDto('registration', 'Registro', $registrations, null, null),
            new FunnelStageDto('synced', 'Contacto sincronizado', $synced, null, null),
            new FunnelStageDto('qualified', 'Lead calificado', max($qualified, 0), null, null),
            new FunnelStageDto('purchase', 'Compra', $purchases, null, null),
            new FunnelStageDto('client', 'Cliente', $clients, null, null),
            new FunnelStageDto('membership', 'Membresía', $memberships, null, null),
            new FunnelStageDto('repurchase', 'Recompra', $repurchaseCustomers, null, null),
        ];

        $mapped = [];
        $prev = null;
        foreach ($stages as $stage) {
            $conversion = null;
            $dropoff = null;
            if ($prev !== null && $prev > 0) {
                $conversion = round(100 * $stage->count / $prev, 1);
                $dropoff = round(100 - $conversion, 1);
            }
            $mapped[] = (new FunnelStageDto(
                $stage->key,
                $stage->label,
                $stage->count,
                $conversion,
                $dropoff,
            ))->toArray();
            $prev = $stage->count;
        }

        return $mapped;
    }

    /**
     * @param  list<array<string, mixed>>  $top
     * @return list<array<string, mixed>>
     */
    protected function mapLaboratories(array $top): array
    {
        return collect($top)
            ->take(10)
            ->map(function (array $row) {
                $orders = (int) ($row['orders'] ?? 0);
                $revenueCents = (int) ($row['revenue_cents'] ?? 0);
                $average = $row['ticket_label'] ?? null;
                if ($average === null && $orders > 0) {
                    $average = '$'.number_format($revenueCents / $orders / 100, 2);
                }

                return [
                    'laboratory' => $row['label'] ?? $row['id'] ?? '—',
                    'orders' => $orders,
                    'amount' => $row['revenue_label'] ?? $row['orders_label'] ?? '—',
                    'amount_cents' => $revenueCents ?: null,
                    'results' => $row['with_results'] ?? $row['results'] ?? '—',
                    'average' => $average ?? '—',
                    'conversion' => isset($row['share_percent'])
                        ? $row['share_percent'].'%'
                        : (isset($row['share']) ? $row['share'].'%' : ($row['conversion'] ?? '—')),
                    'abandoned' => $row['abandoned'] ?? '—',
                ];
            })
            ->values()
            ->all();
    }

    /**
     * @param  array<string, mixed>  $memCore
     * @return array<string, mixed>
     */
    protected function mapMemberships(array $memCore): array
    {
        $summary = collect($memCore['summary'] ?? []);
        $find = fn (string $id) => $summary->firstWhere('id', $id)['value'] ?? '—';

        return [
            'active' => $find('activas'),
            'pending' => $find('nuevas'),
            'cancelled' => $find('canceladas'),
            'expired' => $summary->firstWhere('id', 'churn')['value']
                ?? $summary->firstWhere('id', 'canceladas')['value']
                ?? '—',
            'renewed' => $find('renovaciones'),
            'renewal_rate' => $summary->firstWhere('id', 'retencion')['value'] ?? '—',
            'cancel_rate' => $summary->firstWhere('id', 'churn_rate')['value']
                ?? $summary->firstWhere('id', 'canceladas')['hint']
                ?? '—',
            'cards' => $memCore['summary'] ?? [],
        ];
    }

    /**
     * @param  array<string, mixed>  $ecomCore
     * @param  array<string, mixed>  $ecomCharts
     * @return array<string, mixed>
     */
    protected function mapPurchases(array $ecomCore, array $ecomCharts): array
    {
        $summary = collect($ecomCore['summary'] ?? []);
        $find = fn (string $id) => $summary->firstWhere('id', $id);

        return [
            'sales' => $find('gmv')['value'] ?? '—',
            'orders' => $find('pedidos')['value'] ?? '—',
            'total_amount' => $find('gmv')['value'] ?? '—',
            'avg_ticket' => $find('ticket')['value'] ?? '—',
            'successful' => $find('pedidos')['value'] ?? '—',
            'failed' => $find('abandono')['value'] ?? '—',
            'refunds' => '—',
            'cards' => $ecomCore['summary'] ?? [],
            'by_day' => $ecomCharts['by_day'] ?? $ecomCharts['daily'] ?? [],
        ];
    }

    /**
     * @param  array<string, mixed>  $autoList
     * @param  array<string, mixed>  $autoDash
     * @return list<array<string, mixed>>
     */
    protected function mapAutomations(array $autoList, array $autoDash = []): array
    {
        $list = $autoList['items'] ?? $autoDash['catalog_preview'] ?? [];
        $avgTime = collect($autoDash['metrics'] ?? [])->firstWhere('id', 'avg_time')['value'] ?? '—';
        $errorsTotal = (int) (collect($autoDash['metrics'] ?? [])->firstWhere('id', 'errors')['value'] ?? 0);

        return collect($list)->take(25)->map(function ($row) use ($avgTime, $errorsTotal) {
            $row = is_array($row) ? $row : (array) $row;
            $prefixes = $row['dispatch_event_prefixes'] ?? [];
            $runs = 0;
            $errors = 0;
            if ($prefixes !== []) {
                $q = ActiveCampaignDispatch::query();
                $q->where(function ($inner) use ($prefixes) {
                    foreach ($prefixes as $prefix) {
                        $inner->orWhere('event_type', 'like', $prefix.'%');
                    }
                });
                $runs = (clone $q)->count();
                $errors = (clone $q)->where('status', ActiveCampaignDispatch::STATUS_FAILED)->count();
            }

            return [
                'name' => $row['name'] ?? $row['title'] ?? $row['id'] ?? '—',
                'runs' => $runs,
                'last_run' => $row['last_run'] ?? $row['last_execution'] ?? '—',
                'errors' => $errors > 0 ? $errors : ($prefixes === [] ? 0 : $errors),
                'avg_time' => $avgTime,
                'status' => $row['status_label'] ?? $row['status'] ?? '—',
                'errors_context_total' => $errorsTotal,
            ];
        })->values()->all();
    }

    /**
     * @param  array<string, mixed>  $opsIntel
     * @return array<string, mixed>
     */
    protected function buildContactHealth(array $opsIntel): array
    {
        $lead = $opsIntel['lead_score'] ?? [];

        $withoutEmail = User::query()->where(function ($q) {
            $q->whereNull('email')->orWhere('email', '');
        })->count();
        // phone on contacts
        $withoutPhone = Contact::query()->where(function ($q) {
            $q->whereNull('phone')->orWhere('phone', '');
        })->count();

        $withoutOwner = (int) ($opsIntel['owners']['without_owner'] ?? 0);
        $sample = (int) ($opsIntel['sample_size'] ?? 0);

        $duplicates = (int) User::query()
            ->select('email')
            ->whereNotNull('email')
            ->where('email', '!=', '')
            ->groupBy('email')
            ->havingRaw('COUNT(*) > 1')
            ->get()
            ->count();

        $customersWithPurchase = LaboratoryPurchase::query()
            ->whereNull('deleted_at')
            ->whereNotNull('customer_id')
            ->distinct()
            ->count('customer_id');
        $totalCustomers = Customer::query()->count();
        $withoutPurchases = max(0, $totalCustomers - $customersWithPurchase);

        $withoutActivity = Customer::query()
            ->where(function ($q) {
                $q->whereNull('ac_last_sync_at')
                    ->orWhere('ac_last_sync_at', '<', now()->subDays(90));
            })
            ->count();

        return [
            'score_bands' => [
                ['key' => 'excellent', 'label' => 'Excelente', 'count' => (int) ($lead['excellent'] ?? 0), 'tone' => 'emerald'],
                ['key' => 'good', 'label' => 'Bueno', 'count' => (int) ($lead['good'] ?? 0), 'tone' => 'sky'],
                ['key' => 'regular', 'label' => 'Regular', 'count' => (int) ($lead['risk'] ?? 0), 'tone' => 'amber'],
                ['key' => 'critical', 'label' => 'Crítico', 'count' => (int) ($lead['critical'] ?? 0), 'tone' => 'rose'],
            ],
            'indicators' => [
                ['key' => 'no_email', 'label' => 'Sin email', 'count' => $withoutEmail],
                ['key' => 'no_phone', 'label' => 'Sin teléfono', 'count' => $withoutPhone],
                ['key' => 'no_owner', 'label' => 'Sin owner', 'count' => $withoutOwner],
                ['key' => 'no_tags', 'label' => 'Sin tags', 'count' => '—'],
                ['key' => 'no_lists', 'label' => 'Sin listas', 'count' => '—'],
                ['key' => 'duplicates', 'label' => 'Duplicados', 'count' => $duplicates],
                ['key' => 'no_purchases', 'label' => 'Sin compras', 'count' => $withoutPurchases],
                ['key' => 'no_activity', 'label' => 'Sin actividad', 'count' => $withoutActivity],
            ],
            'sample_size' => $sample,
            'note' => $opsIntel['note'] ?? null,
        ];
    }

    /**
     * @param  array<string, mixed>  $health
     * @param  array<string, mixed>  $sync
     * @param  array<string, mixed>  $mirror
     * @return list<array<string, mixed>>
     */
    protected function buildAlerts(array $health, array $sync, array $mirror, ActiveCampaignDashboardFilter $filter): array
    {
        $alerts = [];

        $ms = $health['response_ms'] ?? null;
        if (is_numeric($ms) && (int) $ms > 2000) {
            $alerts[] = new OperationsAlertDto('api_slow', 'warning', 'API lenta', "Última respuesta {$ms} ms.");
        }

        if (($sync['failed'] ?? 0) >= 5) {
            $alerts[] = new OperationsAlertDto('many_errors', 'critical', 'Muchos errores', ($sync['failed'] ?? 0).' dispatches fallidos hoy.');
        } elseif (($sync['failed'] ?? 0) > 0) {
            $alerts[] = new OperationsAlertDto('some_errors', 'warning', 'Errores de sync', ($sync['failed'] ?? 0).' fallos hoy.');
        }

        if (($health['rate_limit'] ?? null) !== 'No disponible' && is_numeric($health['rate_limit'])) {
            $alerts[] = new OperationsAlertDto('rate_limit', 'warning', 'Rate limit', 'Revisa el cupo restante de la API.');
        }

        $alerts[] = new OperationsAlertDto('webhooks', 'info', 'Webhook detenido', 'Webhooks inbound aún no instrumentados.');

        if (($mirror['synced_today'] ?? 0) === 0) {
            $alerts[] = new OperationsAlertDto('no_sync', 'warning', 'Sin sincronización', 'No hay ac_last_sync_at registrados hoy.');
        }

        if (($mirror['snapshots_cached'] ?? 0) === 0) {
            $alerts[] = new OperationsAlertDto('mirror_empty', 'warning', 'Mirror vacío', 'No hay snapshots en caché de lectura.');
        }

        if (($sync['pending'] ?? 0) > 20) {
            $alerts[] = new OperationsAlertDto('dispatch_stuck', 'critical', 'Dispatch detenido', ($sync['pending'] ?? 0).' dispatches pendientes/procesando.');
        }

        $lastPurchase = LaboratoryPurchase::query()->whereNull('deleted_at')->latest('id')->value('created_at');
        if ($lastPurchase && Carbon::parse($lastPurchase)->lt(now()->subHours(12))) {
            $alerts[] = new OperationsAlertDto(
                'stale_purchases',
                'info',
                'Última compra hace muchas horas',
                'Última compra lab: '.Carbon::parse($lastPurchase)->timezone(self::TZ)->diffForHumans()
            );
        }

        if ($health['status'] === 'disabled') {
            $alerts[] = new OperationsAlertDto('ac_disabled', 'critical', 'ActiveCampaign deshabilitado', 'La integración está off en configuración.');
        }

        return array_map(static fn (OperationsAlertDto $a) => $a->toArray(), $alerts);
    }

    /**
     * @param  array<string, mixed>  $labCharts
     * @param  array<string, mixed>  $ecomCharts
     * @param  array<string, mixed>  $opsSync
     * @return array<string, mixed>
     */
    protected function buildAnalytics(
        ActiveCampaignDashboardFilter $filter,
        array $labCharts,
        array $ecomCharts,
        array $opsSync,
        array $contactHealth = [],
    ): array {
        $purchasesByDay = $this->normalizeSeries($ecomCharts['by_day'] ?? $labCharts['by_day'] ?? [], 'pedidos');
        $syncedByDay = $this->dispatchSeriesByDay($filter, null, ActiveCampaignDispatch::STATUS_SYNCED);
        $errorsByDay = $this->dispatchSeriesByDay($filter, null, ActiveCampaignDispatch::STATUS_FAILED);
        $automationsByDay = $this->dispatchSeriesByDay($filter);
        $bands = collect($contactHealth['score_bands'] ?? []);

        return [
            'purchases_by_day' => $purchasesByDay,
            'contacts_synced' => $syncedByDay,
            'lead_score' => [
                'labels' => $bands->pluck('label')->all() ?: ['Excelente', 'Bueno', 'Regular', 'Crítico'],
                'values' => $bands->pluck('count')->all() ?: [0, 0, 0, 0],
            ],
            'errors' => $errorsByDay,
            'webhooks' => ['labels' => $syncedByDay['labels'] ?? [], 'values' => array_fill(0, count($syncedByDay['labels'] ?? []), 0)],
            'automations' => $automationsByDay,
            'conversion' => $purchasesByDay,
        ];
    }

    protected function kpi(
        string $key,
        string $label,
        int|float $value,
        int|float|null $previous,
        array $sparkline,
        string $tone,
        ?string $hint = null,
    ): ExecutiveKpiDto {
        $growth = null;
        $trend = 'unknown';
        if ($previous !== null) {
            if ((float) $previous == 0.0) {
                $growth = $value > 0 ? 100.0 : 0.0;
            } else {
                $growth = round((($value - $previous) / abs($previous)) * 100, 1);
            }
            $trend = $growth > 0 ? 'up' : ($growth < 0 ? 'down' : 'flat');
        }

        return new ExecutiveKpiDto(
            key: $key,
            label: $label,
            value: $value,
            previousValue: $previous,
            growthPercent: $growth,
            trend: $trend,
            sparkline: $sparkline,
            tone: $tone,
            hint: $hint,
        );
    }

    /**
     * @return list<int>
     */
    protected function lastDaysDispatchSpark(?string $eventLike, ?string $status = null): array
    {
        $points = [];
        for ($i = 6; $i >= 0; $i--) {
            $start = now()->timezone(self::TZ)->subDays($i)->startOfDay()->utc();
            $end = now()->timezone(self::TZ)->subDays($i)->endOfDay()->utc();
            $q = ActiveCampaignDispatch::query()->whereBetween('created_at', [$start, $end]);
            if ($eventLike) {
                $q->where('event_type', 'like', $eventLike);
            }
            if ($status) {
                $q->where('status', $status);
            }
            $points[] = $q->count();
        }

        return $points;
    }

    /**
     * @param  \Illuminate\Database\Eloquent\Builder<\Illuminate\Database\Eloquent\Model>  $query
     * @return list<int>
     */
    protected function dailySeriesCount($query, string $column, Carbon $start, Carbon $end): array
    {
        $points = [];
        $cursor = $start->copy()->timezone(self::TZ)->startOfDay();
        $endLocal = $end->copy()->timezone(self::TZ)->endOfDay();
        while ($cursor->lte($endLocal) && count($points) < 14) {
            $dayStart = $cursor->copy()->utc();
            $dayEnd = $cursor->copy()->endOfDay()->utc();
            $points[] = (clone $query)->whereBetween($column, [$dayStart, $dayEnd])->count();
            $cursor->addDay();
        }

        return $points;
    }

    /**
     * @return array{labels: list<string>, values: list<int>}
     */
    protected function dispatchSeriesByDay(
        ActiveCampaignDashboardFilter $filter,
        ?string $eventLike = null,
        ?string $status = null,
    ): array {
        $labels = [];
        $values = [];
        $cursor = $filter->startLocal->copy()->startOfDay();
        while ($cursor->lte($filter->endLocal) && count($labels) < 90) {
            $labels[] = $cursor->format('d/m');
            $q = ActiveCampaignDispatch::query()->whereBetween('created_at', [
                $cursor->copy()->utc(),
                $cursor->copy()->endOfDay()->utc(),
            ]);
            if ($eventLike) {
                $q->where('event_type', 'like', $eventLike);
            }
            if ($status) {
                $q->where('status', $status);
            }
            $values[] = $q->count();
            $cursor->addDay();
        }

        return ['labels' => $labels, 'values' => $values];
    }

    /**
     * @param  mixed  $series
     * @return array{labels: list<string>, values: list<int|float>}
     */
    protected function normalizeSeries(mixed $series, string $preferredValueKey = 'value'): array
    {
        if (! is_array($series) || $series === []) {
            return ['labels' => [], 'values' => []];
        }

        if (isset($series['labels'], $series['values'])) {
            return [
                'labels' => array_values($series['labels']),
                'values' => array_values($series['values']),
            ];
        }

        $labels = [];
        $values = [];
        foreach ($series as $row) {
            if (! is_array($row)) {
                continue;
            }
            $labels[] = (string) ($row['label'] ?? $row['date'] ?? $row['day'] ?? '');
            $values[] = $row[$preferredValueKey]
                ?? $row['value']
                ?? $row['pedidos']
                ?? $row['orders']
                ?? $row['count']
                ?? $row['gmv']
                ?? $row['revenue']
                ?? 0;
        }

        return ['labels' => $labels, 'values' => $values];
    }
}
