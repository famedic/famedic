<?php

namespace App\Services\ActiveCampaign;

use App\Models\ActiveCampaignDispatch;
use App\Models\Contact;
use App\Support\ActiveCampaign\ActiveCampaignDashboardFilter;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;

/**
 * Logs Center — consola de investigación de incidentes (capa de composición).
 * No lee laravel.log ni crea tablas: consolida ActiveCampaignDispatch + señales Health/Automation.
 */
class ActiveCampaignLogsCenterService
{
    private const TZ = 'America/Monterrey';

    private ActiveCampaignDashboardService $dashboard;

    public function __construct(ActiveCampaignDashboardService $dashboard)
    {
        $this->dashboard = $dashboard;
    }

    /**
     * @return array<string, mixed>
     */
    public function buildInbox(Request $request): array
    {
        $filter = ActiveCampaignDashboardFilter::fromRequest($request);
        $filters = $this->resolveFilters($request);

        if ($filter->bustCache) {
            Cache::forget($this->cacheKey($filter, 'raw-logs'));
            Cache::forget($this->cacheKey($filter, 'executive'));
        }

        $overview = $this->dashboard->buildOverview($filter);
        $rawKey = $this->cacheKey($filter, 'raw-logs');

        $rawItems = Cache::remember($rawKey, now()->addMinutes(5), function () use ($filter, $overview) {
            return $this->collectLogs($filter, $overview)->all();
        });

        $items = collect($rawItems);
        $filtered = $this->applyFilters($items, $filters);

        return [
            'filters' => $filters,
            'filterOptions' => $this->filterOptions(),
            'summary' => $this->buildSummary($items, $filter),
            'logs' => $filtered->map(fn (array $l) => $this->listItem($l))->values()->all(),
            'actions' => $this->quickActions(),
            'meta' => [
                ...($overview['meta'] ?? []),
                'purpose' => 'Investigar incidentes con información ya existente (dispatches, Health, Automation).',
                'source_of_truth' => 'ActiveCampaignDispatch · Dashboard/Health · Automation config · mapa Timeline/Event/Alerts',
                'total' => $filtered->count(),
                'note' => 'No es laravel.log. Payload sanitizado. Stack resumido desde last_error.',
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function buildExecutive(Request $request): array
    {
        $filter = ActiveCampaignDashboardFilter::fromRequest($request);
        $overview = $this->dashboard->buildOverview($filter);
        $rawKey = $this->cacheKey($filter, 'raw-logs');

        $items = collect(Cache::remember($rawKey, now()->addMinutes(5), function () use ($filter, $overview) {
            return $this->collectLogs($filter, $overview)->all();
        }));

        $errors = $items->where('level', 'error');
        $warnings = $items->where('level', 'warning');

        $byModule = $errors->groupBy('module')->map(fn ($g, $k) => [
            'label' => (string) $k,
            'value' => $g->count(),
        ])->sortByDesc('value')->values()->all();

        $topErrors = $errors->groupBy('event')->map(fn ($g, $k) => [
            'label' => (string) $k,
            'value' => $g->count(),
        ])->sortByDesc('value')->take(8)->values()->all();

        $topWarnings = $warnings->groupBy('event')->map(fn ($g, $k) => [
            'label' => (string) $k,
            'value' => $g->count(),
        ])->sortByDesc('value')->take(8)->values()->all();

        $trend = $items
            ->groupBy('date')
            ->map(fn ($g, $date) => [
                'label' => (string) $date,
                'errors' => $g->where('level', 'error')->count(),
                'warnings' => $g->where('level', 'warning')->count(),
                'info' => $g->where('level', 'info')->count(),
            ])
            ->sortKeys()
            ->values()
            ->all();

        return [
            'errors_by_module' => $byModule,
            'trend' => $trend,
            'top_errors' => $topErrors,
            'top_warnings' => $topWarnings,
            'gaps' => $this->gaps(),
            'truth' => 'disponible',
            'note' => 'Tendencia y tops derivados del stock de logs compuestos del periodo (cache 5 min).',
        ];
    }

    /**
     * @return array<string, mixed>|null
     */
    public function buildDetail(string $logId, Request $request): ?array
    {
        if ($logId === '') {
            return null;
        }

        $filter = ActiveCampaignDashboardFilter::fromRequest($request);
        $overview = $this->dashboard->buildOverview($filter);
        $rawKey = $this->cacheKey($filter, 'raw-logs');
        $items = collect(Cache::remember($rawKey, now()->addMinutes(5), function () use ($filter, $overview) {
            return $this->collectLogs($filter, $overview)->all();
        }));

        $item = $items->firstWhere('id', $logId);
        if (! $item) {
            return null;
        }

        // Enriquecer detalle desde dispatch si aplica.
        if (($item['source'] ?? '') === 'dispatch' && isset($item['dispatch_id'])) {
            $row = ActiveCampaignDispatch::query()->find($item['dispatch_id']);
            if ($row) {
                $item['payload_sanitized'] = $this->sanitizePayload($row->payload);
                $item['stack_summary'] = $this->stackSummary($row->last_error);
                $item['context'] = [
                    'attempts' => $row->attempts,
                    'entity_type' => $row->entity_type,
                    'entity_id' => $row->entity_id,
                    'idempotency_key' => $row->idempotency_key,
                    'synced_at' => $row->synced_at?->timezone(self::TZ)->format('d/m/Y H:i') ?? '—',
                ];
            }
        }

        return [
            ...$item,
            'detail' => $item['description'],
            'context' => $item['context'] ?? [],
            'payload_sanitized' => $item['payload_sanitized'] ?? null,
            'stack_summary' => $item['stack_summary'] ?? ($item['description'] ?? 'No disponible'),
            'related_event' => $item['event'] ?? 'No disponible',
            'related_timeline' => $item['timeline_note'] ?? 'Mapa Timeline: el evento puede aparecer en Journey/Vista 360 del paciente si aplica.',
            'quick_links' => $item['quick_links'] ?? $this->defaultLinks(),
            'truth' => $item['truth'] ?? 'disponible',
        ];
    }

    private function cacheKey(ActiveCampaignDashboardFilter $filter, string $suffix): string
    {
        return 'mi-logs:v1:'.sha1(json_encode($filter->toArray()).'|'.$suffix);
    }

    /**
     * @return array<string, mixed>
     */
    private function resolveFilters(Request $request): array
    {
        $dash = ActiveCampaignDashboardFilter::fromRequest($request)->toArray();

        return [
            'level' => trim((string) $request->input('level', '')),
            'module' => trim((string) $request->input('module', '')),
            'origin' => trim((string) $request->input('origin', '')),
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
            'levels' => [
                ['value' => '', 'label' => 'Todos'],
                ['value' => 'error', 'label' => 'Error'],
                ['value' => 'warning', 'label' => 'Warning'],
                ['value' => 'info', 'label' => 'Información'],
            ],
            'modules' => [
                ['value' => '', 'label' => 'Todos'],
                ['value' => 'Event Center', 'label' => 'Event Center'],
                ['value' => 'Automation Center', 'label' => 'Automation Center'],
                ['value' => 'Health Center', 'label' => 'Health Center'],
                ['value' => 'Alerts Center', 'label' => 'Alerts Center'],
                ['value' => 'Timeline', 'label' => 'Timeline'],
            ],
            'origins' => [
                ['value' => '', 'label' => 'Todos'],
                ['value' => 'ActiveCampaignDispatch', 'label' => 'ActiveCampaignDispatch'],
                ['value' => 'Dashboard', 'label' => 'Dashboard'],
                ['value' => 'Automation config', 'label' => 'Automation config'],
            ],
        ];
    }

    /**
     * @param  \Illuminate\Support\Collection<int, array<string, mixed>>  $items
     * @return list<array<string, mixed>>
     */
    private function buildSummary($items, ActiveCampaignDashboardFilter $filter): array
    {
        $col = collect($items);
        $hourAgo = now(self::TZ)->subHour();
        $lastHour = $col->filter(function (array $l) use ($hourAgo) {
            try {
                return Carbon::parse($l['occurred_at'])->greaterThanOrEqualTo($hourAgo);
            } catch (\Throwable) {
                return false;
            }
        })->count();

        $periodHint = $filter->start->timezone(self::TZ)->format('d/m').'–'.$filter->end->timezone(self::TZ)->format('d/m');

        return [
            [
                'id' => 'total',
                'label' => 'Total logs',
                'value' => (string) $col->count(),
                'tone' => 'sky',
                'hint' => 'Compuestos del periodo '.$periodHint,
                'truth' => 'disponible',
            ],
            [
                'id' => 'errors',
                'label' => 'Errores',
                'value' => (string) $col->where('level', 'error')->count(),
                'tone' => 'red',
                'hint' => 'Dispatches failed + señales críticas',
                'truth' => 'disponible',
            ],
            [
                'id' => 'warnings',
                'label' => 'Warnings',
                'value' => (string) $col->where('level', 'warning')->count(),
                'tone' => 'amber',
                'hint' => 'In-flight, skipped, backlog, flags',
                'truth' => 'disponible',
            ],
            [
                'id' => 'info',
                'label' => 'Información',
                'value' => (string) $col->where('level', 'info')->count(),
                'tone' => 'sky',
                'hint' => 'Synced / contexto operativo',
                'truth' => 'disponible',
            ],
            [
                'id' => 'last_hour',
                'label' => 'Última hora',
                'value' => (string) $lastHour,
                'tone' => 'zinc',
                'hint' => 'Logs con timestamp en la última hora (TZ Monterrey)',
                'truth' => 'disponible',
            ],
        ];
    }

    /**
     * @param  array<string, mixed>  $overview
     * @return \Illuminate\Support\Collection<int, array<string, mixed>>
     */
    private function collectLogs(ActiveCampaignDashboardFilter $filter, array $overview)
    {
        $items = collect();

        $items = $items->merge($this->fromDispatches($filter));
        $items = $items->merge($this->fromHealthSignals($overview));
        $items = $items->merge($this->fromAutomationFlags());

        return $items->sortByDesc(fn (array $l) => $l['occurred_at_sort'] ?? 0)->values();
    }

    /**
     * @return \Illuminate\Support\Collection<int, array<string, mixed>>
     */
    private function fromDispatches(ActiveCampaignDashboardFilter $filter)
    {
        $startS = $filter->start->toDateTimeString();
        $endS = $filter->end->toDateTimeString();

        return ActiveCampaignDispatch::query()
            ->where(function ($q) use ($startS, $endS) {
                $q->whereBetween('updated_at', [$startS, $endS])
                    ->orWhereBetween('created_at', [$startS, $endS]);
            })
            ->orderByDesc('updated_at')
            ->limit(120)
            ->get([
                'id', 'event_type', 'email', 'status', 'attempts', 'last_error',
                'payload', 'updated_at', 'created_at', 'synced_at', 'customer_id',
                'entity_type', 'entity_id',
            ])
            ->map(function (ActiveCampaignDispatch $row) {
                $level = match ($row->status) {
                    ActiveCampaignDispatch::STATUS_FAILED => 'error',
                    ActiveCampaignDispatch::STATUS_SKIPPED,
                    ActiveCampaignDispatch::STATUS_PENDING,
                    ActiveCampaignDispatch::STATUS_PROCESSING => 'warning',
                    default => 'info',
                };

                $at = $row->updated_at ?? $row->created_at;
                $id = 'log-dispatch-'.$row->id;
                $eventType = (string) $row->event_type;
                $module = $this->moduleForEvent($eventType);

                return $this->log(
                    id: $id,
                    level: $level,
                    origin: 'ActiveCampaignDispatch',
                    module: $module,
                    event: $eventType,
                    status: (string) $row->status,
                    description: $level === 'error'
                        ? $this->shortError($row->last_error)
                        : 'Dispatch '.$row->status.' · intentos '.((int) $row->attempts),
                    patient: $row->email ?: '—',
                    patientEmail: $row->email,
                    contactId: $this->contactIdForCustomer($row->customer_id),
                    at: $at,
                    source: 'dispatch',
                    dispatchId: $row->id,
                    timelineNote: 'Tipo de dominio alineado con Event Center / Timeline cuando el evento es de paciente.',
                    links: [
                        ['label' => 'Event Center', 'href' => route('admin.activecampaign.events')],
                        ['label' => 'Alerts Center', 'href' => route('admin.activecampaign.alerts')],
                        ['label' => 'Automation', 'href' => route('admin.activecampaign.automations')],
                    ],
                    truth: 'disponible',
                    stackSummary: $level === 'error' ? $this->stackSummary($row->last_error) : null,
                    payloadSanitized: null, // diferido al detalle
                );
            });
    }

    /**
     * @param  array<string, mixed>  $overview
     * @return \Illuminate\Support\Collection<int, array<string, mixed>>
     */
    private function fromHealthSignals(array $overview)
    {
        $items = collect();
        $health = collect($overview['health'] ?? [])->keyBy('id');

        $backlog = (int) preg_replace('/[^\d]/', '', (string) ($health->get('backlog')['value'] ?? '0'));
        if ($backlog > 0) {
            $items->push($this->log(
                id: 'log-health-backlog',
                level: $backlog >= 50 ? 'error' : 'warning',
                origin: 'Dashboard',
                module: 'Health Center',
                event: 'backlog',
                status: 'open',
                description: "Cola local: {$backlog} dispatches pending/processing.",
                patient: '—',
                patientEmail: null,
                contactId: null,
                at: now(self::TZ),
                source: 'health',
                dispatchId: null,
                timelineNote: 'Señal operativa agregada (no es evento Timeline por paciente).',
                links: [
                    ['label' => 'Health Center', 'href' => route('admin.activecampaign.health')],
                    ['label' => 'Alerts Center', 'href' => route('admin.activecampaign.alerts')],
                ],
                truth: 'disponible',
                stackSummary: null,
                payloadSanitized: ['backlog' => $backlog],
            ));
        }

        $integration = (string) ($health->get('integration')['value'] ?? '');
        if ($integration !== '' && ! in_array($integration, ['Operativo'], true)) {
            $items->push($this->log(
                id: 'log-health-integration',
                level: 'error',
                origin: 'Dashboard',
                module: 'Health Center',
                event: 'integration_status',
                status: $integration,
                description: 'Integración ActiveCampaign: '.$integration,
                patient: '—',
                patientEmail: null,
                contactId: null,
                at: now(self::TZ),
                source: 'health',
                dispatchId: null,
                timelineNote: 'Señal de configuración local.',
                links: [
                    ['label' => 'Integrations Hub', 'href' => route('admin.activecampaign.integrations')],
                    ['label' => 'Health Center', 'href' => route('admin.activecampaign.health')],
                ],
                truth: 'disponible',
                stackSummary: null,
                payloadSanitized: ['integration' => $integration],
            ));
        }

        $errorsCard = (int) preg_replace('/[^\d]/', '', (string) ($health->get('errors')['value'] ?? '0'));
        if ($errorsCard > 0) {
            $items->push($this->log(
                id: 'log-alerts-errors-signal',
                level: 'warning',
                origin: 'Dashboard',
                module: 'Alerts Center',
                event: 'period_failed_dispatches',
                status: 'open',
                description: "Señal Alerts: {$errorsCard} dispatches failed en el periodo (detalle en listado Event Center).",
                patient: '—',
                patientEmail: null,
                contactId: null,
                at: now(self::TZ),
                source: 'alerts_signal',
                dispatchId: null,
                timelineNote: 'Agregado Health/Dashboard; no es evento Timeline por paciente.',
                links: [
                    ['label' => 'Alerts Center', 'href' => route('admin.activecampaign.alerts')],
                    ['label' => 'Event Center', 'href' => route('admin.activecampaign.events')],
                ],
                truth: 'proxy',
                stackSummary: null,
                payloadSanitized: ['failed_in_period' => $errorsCard],
            ));
        }

        return $items;
    }

    /**
     * @return \Illuminate\Support\Collection<int, array<string, mixed>>
     */
    private function fromAutomationFlags()
    {
        $items = collect();

        if (! config('services.activecampaign.tag_abandoned_carts_enabled', true)) {
            $items->push($this->log(
                id: 'log-auto-paused-abandoned',
                level: 'warning',
                origin: 'Automation config',
                module: 'Automation Center',
                event: 'abandoned_carts_paused',
                status: 'paused',
                description: 'Flag tag_abandoned_carts_enabled desactivado.',
                patient: '—',
                patientEmail: null,
                contactId: null,
                at: now(self::TZ),
                source: 'automation',
                dispatchId: null,
                timelineNote: 'Configuración; no genera eventos Timeline.',
                links: [
                    ['label' => 'Automation Center', 'href' => route('admin.activecampaign.automations')],
                ],
                truth: 'disponible',
                stackSummary: null,
                payloadSanitized: ['flag' => 'tag_abandoned_carts_enabled', 'value' => false],
            ));
        }

        return $items;
    }

    /**
     * @param  \Illuminate\Support\Collection<int, array<string, mixed>>  $items
     * @param  array<string, mixed>  $filters
     * @return \Illuminate\Support\Collection<int, array<string, mixed>>
     */
    private function applyFilters($items, array $filters)
    {
        return $items->filter(function (array $l) use ($filters) {
            if ($filters['level'] !== '' && ($l['level'] ?? '') !== $filters['level']) {
                return false;
            }
            if ($filters['module'] !== '' && ($l['module'] ?? '') !== $filters['module']) {
                return false;
            }
            if ($filters['origin'] !== '' && ($l['origin'] ?? '') !== $filters['origin']) {
                return false;
            }
            if ($filters['patient'] !== '') {
                $hay = strtolower(($l['patient'] ?? '').' '.($l['patient_email'] ?? ''));
                if (! str_contains($hay, strtolower($filters['patient']))) {
                    return false;
                }
            }

            return true;
        });
    }

    /**
     * @param  array<string, mixed>  $l
     * @return array<string, mixed>
     */
    private function listItem(array $l): array
    {
        return [
            'id' => $l['id'],
            'date' => $l['date'],
            'time' => $l['time'],
            'level' => $l['level'],
            'level_label' => $l['level_label'],
            'origin' => $l['origin'],
            'module' => $l['module'],
            'event' => $l['event'],
            'status' => $l['status'],
            'status_label' => $l['status_label'],
            'patient' => $l['patient'],
            'truth' => $l['truth'],
        ];
    }

    /**
     * @param  list<array{label: string, href: string}>  $links
     * @param  array<string, mixed>|null  $payloadSanitized
     * @return array<string, mixed>
     */
    private function log(
        string $id,
        string $level,
        string $origin,
        string $module,
        string $event,
        string $status,
        string $description,
        string $patient,
        ?string $patientEmail,
        ?int $contactId,
        mixed $at,
        string $source,
        ?int $dispatchId,
        string $timelineNote,
        array $links,
        string $truth,
        ?string $stackSummary,
        ?array $payloadSanitized,
    ): array {
        $carbon = $at instanceof Carbon ? $at->copy() : Carbon::parse($at);
        $local = $carbon->timezone(self::TZ);

        $levelLabel = match ($level) {
            'error' => 'Error',
            'warning' => 'Warning',
            default => 'Información',
        };

        return [
            'id' => $id,
            'level' => $level,
            'level_label' => $levelLabel,
            'origin' => $origin,
            'module' => $module,
            'event' => $event,
            'status' => $status,
            'status_label' => ucfirst($status),
            'description' => $description,
            'patient' => $patient,
            'patient_email' => $patientEmail,
            'contact_id' => $contactId,
            'occurred_at' => $local->toIso8601String(),
            'occurred_at_sort' => $local->timestamp,
            'date' => $local->format('d/m/Y'),
            'time' => $local->format('H:i'),
            'source' => $source,
            'dispatch_id' => $dispatchId,
            'timeline_note' => $timelineNote,
            'quick_links' => $links,
            'truth' => $truth,
            'stack_summary' => $stackSummary,
            'payload_sanitized' => $payloadSanitized,
        ];
    }

    /**
     * @param  mixed  $payload
     * @return array<string, mixed>|null
     */
    private function sanitizePayload(mixed $payload): ?array
    {
        if (! is_array($payload) || $payload === []) {
            return null;
        }

        $sensitive = ['password', 'token', 'secret', 'authorization', 'api_key', 'pdf_base64'];
        $clean = [];

        foreach ($payload as $key => $value) {
            $k = strtolower((string) $key);
            if (collect($sensitive)->contains(fn ($s) => str_contains($k, $s))) {
                $clean[$key] = '[redacted]';
                continue;
            }
            if (is_string($value)) {
                $value = preg_replace('/(\/[\w.\-]+)+/', '[path]', $value) ?? $value;
                if (mb_strlen($value) > 200) {
                    $value = mb_substr($value, 0, 197).'…';
                }
                $clean[$key] = $value;
            } elseif (is_scalar($value) || $value === null) {
                $clean[$key] = $value;
            } elseif (is_array($value)) {
                $clean[$key] = array_slice($this->sanitizePayload($value) ?? [], 0, 20);
            } else {
                $clean[$key] = '[object]';
            }
        }

        return array_slice($clean, 0, 40);
    }

    private function stackSummary(?string $error): string
    {
        if ($error === null || trim($error) === '') {
            return 'No disponible';
        }

        $lines = preg_split('/\r\n|\r|\n/', $error) ?: [];
        $lines = array_values(array_filter(array_map('trim', $lines)));
        $lines = array_slice($lines, 0, 6);
        $text = implode("\n", $lines);
        $text = preg_replace('/(\/[\w.\-]+)+/', '[path]', $text) ?? $text;

        return mb_strlen($text) > 800 ? mb_substr($text, 0, 797).'…' : $text;
    }

    private function shortError(?string $error): string
    {
        if ($error === null || trim($error) === '') {
            return 'No disponible';
        }
        $line = trim(explode("\n", $error)[0] ?? '');
        $line = preg_replace('/\s+/', ' ', $line) ?? $line;
        $line = preg_replace('/(\/[\w.\-]+)+/', '[path]', $line) ?? $line;

        return mb_strlen($line) > 160 ? mb_substr($line, 0, 157).'…' : $line;
    }

    private function contactIdForCustomer(?int $customerId): ?int
    {
        if (! $customerId) {
            return null;
        }
        $id = Contact::query()->where('customer_id', $customerId)->value('id');

        return $id ? (int) $id : null;
    }

    private function moduleForEvent(string $eventType): string
    {
        $timelineish = [
            'contact_', 'patient_', 'credit_', 'lab_', 'membership_', 'cart_', 'abandoned_',
        ];

        foreach ($timelineish as $prefix) {
            if (str_starts_with($eventType, $prefix)) {
                return 'Timeline';
            }
        }

        if (str_contains($eventType, 'automation') || str_contains($eventType, 'workflow')) {
            return 'Automation Center';
        }

        return 'Event Center';
    }

    /**
     * @return list<array{label: string, href: string}>
     */
    private function defaultLinks(): array
    {
        return [
            ['label' => 'Event Center', 'href' => route('admin.activecampaign.events')],
            ['label' => 'Health Center', 'href' => route('admin.activecampaign.health')],
            ['label' => 'Alerts Center', 'href' => route('admin.activecampaign.alerts')],
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function quickActions(): array
    {
        return [
            ['id' => 'events', 'label' => 'Event Center', 'href' => route('admin.activecampaign.events'), 'enabled' => true],
            ['id' => 'health', 'label' => 'Health Center', 'href' => route('admin.activecampaign.health'), 'enabled' => true],
            ['id' => 'alerts', 'label' => 'Alerts Center', 'href' => route('admin.activecampaign.alerts'), 'enabled' => true],
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
                'label' => 'laravel.log / Monolog unificado',
                'reason' => 'Esta consola no lee el filesystem de logs de Laravel.',
                'truth' => 'proximamente',
            ],
            [
                'label' => 'Tracing distribuido / request_id',
                'reason' => 'Sin correlación HTTP↔dispatch en MI.',
                'truth' => 'instrumentacion',
            ],
            [
                'label' => 'Retención histórica larga',
                'reason' => 'Limitado al periodo (máx. 90d) y top 120 dispatches por carga.',
                'truth' => 'proxy',
            ],
        ];
    }
}
