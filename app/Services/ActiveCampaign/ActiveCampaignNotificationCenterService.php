<?php

namespace App\Services\ActiveCampaign;

use App\Models\ActiveCampaignDispatch;
use App\Models\Contact;
use App\Support\ActiveCampaign\ActiveCampaignDashboardFilter;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Session;

/**
 * Notification Center — bandeja de prioridades del ecosistema Famedic.
 * No crea fuente de eventos: consolida Dashboard, dispatches, Health signals y Automation flags.
 */
class ActiveCampaignNotificationCenterService
{
    private const TZ = 'America/Monterrey';

    private const UNAVAILABLE = 'No disponible';

    private const SESSION_SEEN = 'mi_notification_seen';

    private ActiveCampaignDashboardService $dashboard;

    public function __construct(ActiveCampaignDashboardService $dashboard)
    {
        $this->dashboard = $dashboard;
    }

    /**
     * Resumen + bandeja (sin payload pesado de detalle).
     *
     * @return array<string, mixed>
     */
    public function buildInbox(Request $request): array
    {
        $filters = $this->resolveFilters($request);
        $dashFilter = ActiveCampaignDashboardFilter::fromRequest($request);
        $overview = $this->dashboard->buildOverview($dashFilter);
        $seen = $this->seenIds();

        $items = $this->collectNotifications($overview, $seen);
        $filtered = $this->applyFilters($items, $filters);

        return [
            'filters' => $filters,
            'filterOptions' => $this->filterOptions(),
            'summary' => $this->buildSummary($items),
            'notifications' => $filtered->values()->all(),
            'actions' => $this->quickActions(),
            'meta' => [
                'generated_at' => $overview['meta']['generated_at'] ?? now(self::TZ)->format('d/m/Y H:i'),
                'source_of_truth' => 'Dashboard + Event Center (dispatches/dominio Timeline) + Health + Automation config',
                'total' => $filtered->count(),
                'note' => 'Sin fuente nueva: consolida señales existentes. Vista se marca en sesión al abrir detalle.',
            ],
        ];
    }

    /**
     * Detalle bajo demanda (partial reload). Marca la notificación como vista.
     *
     * @return array<string, mixed>|null
     */
    public function buildDetail(string $notificationId): ?array
    {
        if ($notificationId === '') {
            return null;
        }

        $filter = ActiveCampaignDashboardFilter::fromRequest(request());
        $overview = $this->dashboard->buildOverview($filter);
        $seen = $this->seenIds();
        $items = $this->collectNotifications($overview, $seen);
        $item = $items->firstWhere('id', $notificationId);

        if (! $item) {
            return null;
        }

        $this->markSeen($notificationId);
        $item['status'] = 'vista';
        $item['status_label'] = 'Vista';

        return [
            ...$item,
            'origin_detail' => $item['origin'],
            'related_event' => $item['related_event'] ?? self::UNAVAILABLE,
            'suggested_actions' => $item['suggested_actions'] ?? [],
            'quick_links' => $item['quick_links'] ?? $this->defaultLinks($item),
            'raw' => $item['raw'] ?? null,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function resolveFilters(Request $request): array
    {
        $dash = ActiveCampaignDashboardFilter::fromRequest($request)->toArray();

        return [
            'type' => trim((string) $request->input('type', '')),
            'priority' => trim((string) $request->input('priority', '')),
            'status' => trim((string) $request->input('status', '')),
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
            'types' => [
                ['value' => '', 'label' => 'Todos'],
                ['value' => 'dispatch', 'label' => 'Dispatch / Event Center'],
                ['value' => 'health', 'label' => 'Health Center'],
                ['value' => 'automation', 'label' => 'Automation Center'],
                ['value' => 'dashboard', 'label' => 'Dashboard'],
            ],
            'priorities' => [
                ['value' => '', 'label' => 'Todas'],
                ['value' => 'critical', 'label' => 'Crítica'],
                ['value' => 'warning', 'label' => 'Advertencia'],
                ['value' => 'info', 'label' => 'Información'],
            ],
            'statuses' => [
                ['value' => '', 'label' => 'Todos'],
                ['value' => 'nueva', 'label' => 'Nueva'],
                ['value' => 'vista', 'label' => 'Vista'],
                ['value' => 'en_proceso', 'label' => 'En proceso'],
                ['value' => 'resuelta', 'label' => 'Resuelta'],
            ],
        ];
    }

    /**
     * @param  list<array<string, mixed>>|\Illuminate\Support\Collection<int, array<string, mixed>>  $items
     * @return list<array<string, mixed>>
     */
    private function buildSummary($items): array
    {
        $col = collect($items);

        return [
            [
                'id' => 'critical',
                'label' => 'Críticas',
                'value' => (string) $col->where('priority', 'critical')->count(),
                'tone' => 'red',
                'hint' => 'Requieren atención inmediata',
            ],
            [
                'id' => 'warning',
                'label' => 'Advertencias',
                'value' => (string) $col->where('priority', 'warning')->count(),
                'tone' => 'amber',
                'hint' => 'Backlog, pausas, parciales',
            ],
            [
                'id' => 'info',
                'label' => 'Información',
                'value' => (string) $col->where('priority', 'info')->count(),
                'tone' => 'sky',
                'hint' => 'Contexto operativo',
            ],
            [
                'id' => 'resolved',
                'label' => 'Resueltas',
                'value' => (string) $col->where('status', 'resuelta')->count(),
                'tone' => 'zinc',
                'hint' => 'Sync recientes / señales cerradas',
            ],
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function quickActions(): array
    {
        return [
            [
                'id' => 'crm',
                'label' => 'Ir al CRM',
                'href' => route('admin.activecampaign.contacts'),
                'enabled' => true,
            ],
            [
                'id' => 'journey',
                'label' => 'Abrir Journey',
                'href' => route('admin.activecampaign.customer-journey'),
                'enabled' => true,
            ],
            [
                'id' => 'analytics',
                'label' => 'Abrir Analytics',
                'href' => route('admin.activecampaign.analytics'),
                'enabled' => true,
            ],
            [
                'id' => 'events',
                'label' => 'Abrir Event Center',
                'href' => route('admin.activecampaign.events'),
                'enabled' => true,
            ],
            [
                'id' => 'automation',
                'label' => 'Abrir Automation',
                'href' => route('admin.activecampaign.automations'),
                'enabled' => true,
            ],
        ];
    }

    /**
     * @param  array<string, mixed>  $overview
     * @param  list<string>  $seen
     * @return \Illuminate\Support\Collection<int, array<string, mixed>>
     */
    private function collectNotifications(array $overview, array $seen)
    {
        $items = collect();

        $items = $items->merge($this->fromFailedDispatches($seen));
        $items = $items->merge($this->fromInFlightDispatches($seen));
        $items = $items->merge($this->fromBacklog($overview, $seen));
        $items = $items->merge($this->fromIntegrationHealth($overview, $seen));
        $items = $items->merge($this->fromPausedAutomations($seen));
        $items = $items->merge($this->fromRecentSynced($seen));

        return $items
            ->sortByDesc(fn (array $n) => $n['occurred_at_sort'] ?? 0)
            ->values();
    }

    /**
     * @param  list<string>  $seen
     * @return \Illuminate\Support\Collection<int, array<string, mixed>>
     */
    private function fromFailedDispatches(array $seen)
    {
        return ActiveCampaignDispatch::query()
            ->where('status', ActiveCampaignDispatch::STATUS_FAILED)
            ->orderByDesc('updated_at')
            ->limit(20)
            ->get(['id', 'event_type', 'email', 'status', 'last_error', 'updated_at', 'customer_id'])
            ->map(function (ActiveCampaignDispatch $row) use ($seen) {
                $id = 'dispatch-failed-'.$row->id;
                $error = $this->shortError($row->last_error);

                return $this->notification(
                    id: $id,
                    priority: 'critical',
                    type: 'dispatch',
                    title: 'Dispatch fallido: '.$row->event_type,
                    description: $error,
                    patient: $row->email ?: '—',
                    patientEmail: $row->email,
                    contactId: $this->contactIdForDispatch($row),
                    origin: 'Event Center · ActiveCampaignDispatch',
                    at: $row->updated_at,
                    status: $this->statusFor($id, $seen, 'nueva'),
                    relatedEvent: $row->event_type,
                    suggested: [
                        'Revisar en Event Center',
                        'Ver Health Center',
                        'Abrir Automation · pipeline dispatches',
                    ],
                    links: [
                        ['label' => 'Event Center', 'href' => route('admin.activecampaign.events')],
                        ['label' => 'Health Center', 'href' => route('admin.activecampaign.health')],
                        ['label' => 'Automation', 'href' => route('admin.activecampaign.automations.show', ['automation' => 'ac_dispatch_pipeline'])],
                    ],
                    raw: [
                        'dispatch_id' => $row->id,
                        'status' => $row->status,
                    ],
                );
            });
    }

    /**
     * @param  list<string>  $seen
     * @return \Illuminate\Support\Collection<int, array<string, mixed>>
     */
    private function fromInFlightDispatches(array $seen)
    {
        return ActiveCampaignDispatch::query()
            ->whereIn('status', [
                ActiveCampaignDispatch::STATUS_PENDING,
                ActiveCampaignDispatch::STATUS_PROCESSING,
            ])
            ->orderBy('id')
            ->limit(12)
            ->get(['id', 'event_type', 'email', 'status', 'created_at', 'customer_id'])
            ->map(function (ActiveCampaignDispatch $row) use ($seen) {
                $id = 'dispatch-inflight-'.$row->id;

                return $this->notification(
                    id: $id,
                    priority: 'warning',
                    type: 'dispatch',
                    title: 'Dispatch en proceso: '.$row->event_type,
                    description: 'Estado '.$row->status.' en cola local.',
                    patient: $row->email ?: '—',
                    patientEmail: $row->email,
                    contactId: $this->contactIdForDispatch($row),
                    origin: 'Event Center · cola local',
                    at: $row->created_at,
                    status: $this->statusFor($id, $seen, 'en_proceso'),
                    relatedEvent: $row->event_type,
                    suggested: [
                        'Monitorear cola en Health Center',
                        'Abrir Event Center',
                    ],
                    links: [
                        ['label' => 'Event Center', 'href' => route('admin.activecampaign.events')],
                        ['label' => 'Health Center', 'href' => route('admin.activecampaign.health')],
                    ],
                    raw: [
                        'dispatch_id' => $row->id,
                        'status' => $row->status,
                    ],
                );
            });
    }

    /**
     * @param  array<string, mixed>  $overview
     * @param  list<string>  $seen
     * @return \Illuminate\Support\Collection<int, array<string, mixed>>
     */
    private function fromBacklog(array $overview, array $seen)
    {
        $health = collect($overview['health'])->keyBy('id');
        $backlogRaw = (string) ($health->get('backlog')['value'] ?? '0');
        $backlog = (int) preg_replace('/[^\d]/', '', $backlogRaw);

        if ($backlog <= 0) {
            return collect();
        }

        $id = 'dashboard-backlog';

        return collect([
            $this->notification(
                id: $id,
                priority: 'warning',
                type: 'dashboard',
                title: 'Backlog de dispatches elevado',
                description: $backlog.' pendientes o en procesamiento (Dashboard).',
                patient: '—',
                patientEmail: null,
                contactId: null,
                origin: 'Dashboard Ejecutivo',
                at: now(self::TZ),
                status: $this->statusFor($id, $seen, 'nueva'),
                relatedEvent: 'backlog',
                suggested: [
                    'Revisar Health Center · cola',
                    'Abrir Event Center',
                ],
                links: [
                    ['label' => 'Dashboard', 'href' => route('admin.activecampaign.dashboard')],
                    ['label' => 'Health Center', 'href' => route('admin.activecampaign.health')],
                    ['label' => 'Event Center', 'href' => route('admin.activecampaign.events')],
                ],
                raw: ['backlog' => $backlog],
            ),
        ]);
    }

    /**
     * @param  array<string, mixed>  $overview
     * @param  list<string>  $seen
     * @return \Illuminate\Support\Collection<int, array<string, mixed>>
     */
    private function fromIntegrationHealth(array $overview, array $seen)
    {
        $health = collect($overview['health'])->keyBy('id');
        $integration = (string) ($health->get('integration')['value'] ?? '');

        if ($integration === '' || in_array($integration, ['Operativo'], true)) {
            return collect();
        }

        $id = 'health-integration';

        return collect([
            $this->notification(
                id: $id,
                priority: 'warning',
                type: 'health',
                title: 'Integración ActiveCampaign: '.$integration,
                description: 'Estado de configuración local reportado por Dashboard/Health.',
                patient: '—',
                patientEmail: null,
                contactId: null,
                origin: 'Health Center',
                at: now(self::TZ),
                status: $this->statusFor($id, $seen, 'nueva'),
                relatedEvent: 'integration_status',
                suggested: [
                    'Abrir Integrations Hub',
                    'Revisar Configuración',
                    'Health Center',
                ],
                links: [
                    ['label' => 'Integrations Hub', 'href' => route('admin.activecampaign.integrations')],
                    ['label' => 'Health Center', 'href' => route('admin.activecampaign.health')],
                    ['label' => 'Configuración', 'href' => route('admin.activecampaign.settings')],
                ],
                raw: ['integration' => $integration],
            ),
        ]);
    }

    /**
     * @param  list<string>  $seen
     * @return \Illuminate\Support\Collection<int, array<string, mixed>>
     */
    private function fromPausedAutomations(array $seen)
    {
        $items = collect();

        if (! config('services.activecampaign.tag_abandoned_carts_enabled', true)) {
            $id = 'automation-paused-abandoned_carts';
            $items->push($this->notification(
                id: $id,
                priority: 'info',
                type: 'automation',
                title: 'Automatización pausada: carritos abandonados',
                description: 'Flag tag_abandoned_carts_enabled desactivado.',
                patient: '—',
                patientEmail: null,
                contactId: null,
                origin: 'Automation Center',
                at: now(self::TZ),
                status: $this->statusFor($id, $seen, 'nueva'),
                relatedEvent: 'abandoned_carts',
                suggested: ['Abrir Automation Center', 'Revisar detalle abandoned_carts'],
                links: [
                    ['label' => 'Automation', 'href' => route('admin.activecampaign.automations')],
                    ['label' => 'Detalle', 'href' => route('admin.activecampaign.automations.show', ['automation' => 'abandoned_carts'])],
                ],
                raw: null,
            ));
        }

        if (! config('services.activecampaign.coupons_expiring_enabled', false)) {
            $id = 'automation-paused-expiring_coupons';
            $items->push($this->notification(
                id: $id,
                priority: 'info',
                type: 'automation',
                title: 'Automatización pausada: cupones por vencer',
                description: 'Flag coupons_expiring_enabled desactivado (default).',
                patient: '—',
                patientEmail: null,
                contactId: null,
                origin: 'Automation Center',
                at: now(self::TZ),
                status: $this->statusFor($id, $seen, 'nueva'),
                relatedEvent: 'expiring_coupons',
                suggested: ['Abrir Automation Center'],
                links: [
                    ['label' => 'Automation', 'href' => route('admin.activecampaign.automations')],
                    ['label' => 'Detalle', 'href' => route('admin.activecampaign.automations.show', ['automation' => 'expiring_coupons'])],
                ],
                raw: null,
            ));
        }

        return $items;
    }

    /**
     * Sync recientes como notificaciones informativas "resueltas".
     *
     * @param  list<string>  $seen
     * @return \Illuminate\Support\Collection<int, array<string, mixed>>
     */
    private function fromRecentSynced(array $seen)
    {
        return ActiveCampaignDispatch::query()
            ->where('status', ActiveCampaignDispatch::STATUS_SYNCED)
            ->whereNotNull('synced_at')
            ->orderByDesc('synced_at')
            ->limit(8)
            ->get(['id', 'event_type', 'email', 'synced_at', 'customer_id'])
            ->map(function (ActiveCampaignDispatch $row) use ($seen) {
                $id = 'dispatch-synced-'.$row->id;

                return $this->notification(
                    id: $id,
                    priority: 'info',
                    type: 'dispatch',
                    title: 'Sync completado: '.$row->event_type,
                    description: 'Dispatch sincronizado correctamente.',
                    patient: $row->email ?: '—',
                    patientEmail: $row->email,
                    contactId: $this->contactIdForDispatch($row),
                    origin: 'Event Center',
                    at: $row->synced_at,
                    status: 'resuelta',
                    relatedEvent: $row->event_type,
                    suggested: ['Abrir Event Center', 'Abrir Journey del paciente'],
                    links: [
                        ['label' => 'Event Center', 'href' => route('admin.activecampaign.events')],
                        ['label' => 'CRM', 'href' => route('admin.activecampaign.contacts')],
                    ],
                    raw: ['dispatch_id' => $row->id],
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
        $start = Carbon::parse($filters['start_date'], self::TZ)->startOfDay();
        $end = Carbon::parse($filters['end_date'], self::TZ)->endOfDay();

        return $items->filter(function (array $n) use ($filters, $start, $end) {
            $at = Carbon::parse($n['occurred_at'])->timezone(self::TZ);
            if ($at->lt($start) || $at->gt($end)) {
                // Señales sintéticas "now" (backlog/health/automation) siempre pasan el filtro de periodo.
                if (! in_array($n['type'], ['health', 'automation', 'dashboard'], true)) {
                    return false;
                }
            }
            if ($filters['type'] !== '' && ($n['type'] ?? '') !== $filters['type']) {
                return false;
            }
            if ($filters['priority'] !== '' && ($n['priority'] ?? '') !== $filters['priority']) {
                return false;
            }
            if ($filters['status'] !== '' && ($n['status'] ?? '') !== $filters['status']) {
                return false;
            }
            if ($filters['patient'] !== '') {
                $hay = strtolower(($n['patient'] ?? '').' '.($n['patient_email'] ?? ''));
                if (! str_contains($hay, strtolower($filters['patient']))) {
                    return false;
                }
            }

            return true;
        })->map(function (array $n) {
            unset($n['occurred_at_sort'], $n['suggested_actions'], $n['quick_links'], $n['raw'], $n['related_event']);

            return $n;
        });
    }

    /**
     * @param  list<array{label: string, href: string}>  $links
     * @param  list<string>  $suggested
     * @return array<string, mixed>
     */
    private function notification(
        string $id,
        string $priority,
        string $type,
        string $title,
        string $description,
        string $patient,
        ?string $patientEmail,
        ?int $contactId,
        string $origin,
        mixed $at,
        string $status,
        string $relatedEvent,
        array $suggested,
        array $links,
        mixed $raw,
    ): array {
        $carbon = $at instanceof Carbon ? $at->copy() : Carbon::parse($at);
        $local = $carbon->timezone(self::TZ);

        $priorityLabel = match ($priority) {
            'critical' => 'Crítica',
            'warning' => 'Advertencia',
            default => 'Información',
        };

        $statusLabel = match ($status) {
            'nueva' => 'Nueva',
            'vista' => 'Vista',
            'en_proceso' => 'En proceso',
            'resuelta' => 'Resuelta',
            default => $status,
        };

        return [
            'id' => $id,
            'priority' => $priority,
            'priority_label' => $priorityLabel,
            'type' => $type,
            'title' => $title,
            'description' => $description,
            'patient' => $patient,
            'patient_email' => $patientEmail,
            'contact_id' => $contactId,
            'origin' => $origin,
            'occurred_at' => $local->toIso8601String(),
            'occurred_at_sort' => $local->timestamp,
            'date' => $local->format('d/m/Y'),
            'time' => $local->format('H:i'),
            'status' => $status,
            'status_label' => $statusLabel,
            'related_event' => $relatedEvent,
            'suggested_actions' => $suggested,
            'quick_links' => $links,
            'raw' => $raw,
        ];
    }

    /**
     * @param  list<string>  $seen
     */
    private function statusFor(string $id, array $seen, string $default): string
    {
        if ($default === 'resuelta' || $default === 'en_proceso') {
            return $default;
        }

        return in_array($id, $seen, true) ? 'vista' : $default;
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
            // Cap para no crecer sin límite en sesión.
            $ids = array_slice($ids, -200);
            Session::put(self::SESSION_SEEN, $ids);
        }
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
            return self::UNAVAILABLE;
        }

        $line = trim(explode("\n", $error)[0] ?? '');
        $line = preg_replace('/\s+/', ' ', $line) ?? $line;
        $line = preg_replace('/(\/[\w.\-]+)+/', '[path]', $line) ?? $line;

        return mb_strlen($line) > 140 ? mb_substr($line, 0, 137).'…' : $line;
    }

    /**
     * @param  array<string, mixed>  $item
     * @return list<array{label: string, href: string}>
     */
    private function defaultLinks(array $item): array
    {
        return [
            ['label' => 'Event Center', 'href' => route('admin.activecampaign.events')],
            ['label' => 'Health Center', 'href' => route('admin.activecampaign.health')],
        ];
    }
}
