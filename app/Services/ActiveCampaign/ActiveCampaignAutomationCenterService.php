<?php

namespace App\Services\ActiveCampaign;

use App\Models\ActiveCampaignDispatch;
use App\Support\ActiveCampaign\ActiveCampaignDashboardFilter;
use Carbon\Carbon;

/**
 * Automation Center — consola de automatizaciones internas Famedic.
 * No crea motor nuevo ni fuente de eventos: cataloga pipelines reales
 * (scheduler + dispatches) y prepara triggers alineados a Timeline / Event Center.
 */
class ActiveCampaignAutomationCenterService
{
    private const TZ = 'America/Monterrey';

    private const UNAVAILABLE = 'No disponible';

    private const UPCOMING = 'Próximamente';

    private ActiveCampaignDashboardService $dashboard;

    public function __construct(ActiveCampaignDashboardService $dashboard)
    {
        $this->dashboard = $dashboard;
    }

    /**
     * @return array<string, mixed>
     */
    public function buildDashboard(ActiveCampaignDashboardFilter $filter): array
    {
        $automations = $this->catalog();
        $overview = $this->dashboard->buildOverview($filter);
        $healthById = collect($overview['health'])->keyBy('id');

        $active = collect($automations)->where('status', 'active')->count();
        $paused = collect($automations)->where('status', 'paused')->count();
        $planned = collect($automations)->where('status', 'planned')->count();

        return [
            'metrics' => [
                [
                    'id' => 'active',
                    'label' => 'Automatizaciones activas',
                    'value' => (string) $active,
                    'hint' => 'Pipelines con flag/config habilitada',
                    'tone' => 'sky',
                    'truth' => 'disponible',
                ],
                [
                    'id' => 'paused',
                    'label' => 'Automatizaciones pausadas',
                    'value' => (string) $paused,
                    'hint' => 'Deshabilitadas por configuración',
                    'tone' => 'amber',
                    'truth' => 'disponible',
                ],
                [
                    'id' => 'planned',
                    'label' => 'En diseño',
                    'value' => (string) $planned,
                    'hint' => 'Triggers Timeline preparados · acciones Próximamente',
                    'tone' => 'zinc',
                    'truth' => 'proximamente',
                ],
                [
                    'id' => 'errors',
                    'label' => 'Errores',
                    'value' => (string) ($healthById->get('errors')['value'] ?? '0'),
                    'hint' => 'Dispatches failed del periodo (Dashboard)',
                    'tone' => 'red',
                    'truth' => 'disponible',
                ],
                [
                    'id' => 'avg_time',
                    'label' => 'Tiempo promedio',
                    'value' => self::UNAVAILABLE,
                    'hint' => 'Sin telemetría de duración de jobs',
                    'tone' => 'zinc',
                    'truth' => 'no_disponible',
                ],
            ],
            'recent_runs_preview' => $this->recentDispatchRuns(6),
            'catalog_preview' => array_slice($automations, 0, 6),
            'meta' => [
                'generated_at' => $overview['meta']['generated_at'] ?? now(self::TZ)->format('d/m/Y H:i'),
                'source_of_truth' => 'config/schedule + ActiveCampaignDispatch + DashboardService + catálogo Timeline',
            ],
            'links' => [
                'list' => route('admin.activecampaign.automations.list'),
                'builder' => route('admin.activecampaign.automations.builder'),
            ],
        ];
    }

    /**
     * @return array{items: list<array<string, mixed>>, meta: array<string, mixed>}
     */
    public function buildList(): array
    {
        return [
            'items' => $this->catalog(),
            'meta' => [
                'generated_at' => now(self::TZ)->format('d/m/Y H:i'),
                'total' => count($this->catalog()),
            ],
            'links' => [
                'dashboard' => route('admin.activecampaign.automations'),
                'builder' => route('admin.activecampaign.automations.builder'),
            ],
        ];
    }

    /**
     * @return array<string, mixed>|null
     */
    public function buildDetail(string $key): ?array
    {
        $automation = collect($this->catalog())->firstWhere('id', $key);
        if (! $automation) {
            return null;
        }

        return [
            'automation' => $automation,
            'info' => [
                'name' => $automation['name'],
                'description' => $automation['description'],
                'trigger' => $automation['trigger_label'],
                'trigger_type' => $automation['trigger_type'],
                'status' => $automation['status_label'],
                'schedule' => $automation['next_run'] ?? self::UNAVAILABLE,
                'source' => $automation['source'],
                'truth' => $automation['truth'],
            ],
            'conditions' => $automation['conditions'],
            'actions' => $automation['actions'],
            'links' => [
                'list' => route('admin.activecampaign.automations.list'),
                'dashboard' => route('admin.activecampaign.automations'),
                'builder' => route('admin.activecampaign.automations.builder', [
                    'preset' => $automation['id'],
                ]),
            ],
        ];
    }

    /**
     * Historial + logs diferidos (dispatches locales cuando aplica).
     *
     * @return array{history: list<array<string, mixed>>, logs: list<array<string, mixed>>}
     */
    public function buildDetailDeferred(string $key): array
    {
        $automation = collect($this->catalog())->firstWhere('id', $key);
        if (! $automation || ($automation['truth'] ?? '') !== 'disponible') {
            return [
                'history' => [],
                'logs' => [
                    [
                        'level' => 'info',
                        'message' => 'Sin logs locales: automatización planificada o sin instrumentación de ejecución.',
                        'when' => self::UNAVAILABLE,
                    ],
                ],
            ];
        }

        $eventTypes = $automation['dispatch_event_prefixes'] ?? [];
        $history = $this->recentDispatchRuns(12, $eventTypes);

        $logs = collect($history)->map(function (array $run) {
            return [
                'level' => $run['status'] === 'failed' ? 'error' : 'info',
                'message' => $run['label'].' · '.$run['status_label'].' · '.$run['email'],
                'when' => $run['when'],
            ];
        })->all();

        if ($logs === []) {
            $logs[] = [
                'level' => 'info',
                'message' => 'No hay ejecuciones recientes en activecampaign_dispatches para esta automatización.',
                'when' => self::UNAVAILABLE,
            ];
        }

        return [
            'history' => $history,
            'logs' => $logs,
        ];
    }

    /**
     * Builder visual (estructura; sin persistencia).
     *
     * @return array<string, mixed>
     */
    public function buildBuilder(?string $preset = null): array
    {
        $presetAutomation = $preset
            ? collect($this->catalog())->firstWhere('id', $preset)
            : null;

        return [
            'events' => $this->triggerCatalog(),
            'condition_templates' => [
                ['id' => 'always', 'label' => 'Siempre', 'truth' => 'disponible'],
                ['id' => 'has_email', 'label' => 'Paciente con correo', 'truth' => 'proximamente'],
                ['id' => 'membership_active', 'label' => 'Membresía activa', 'truth' => 'proximamente'],
                ['id' => 'first_purchase', 'label' => 'Primera compra', 'truth' => 'proximamente'],
            ],
            'action_templates' => $this->actionCatalog(),
            'preset' => $presetAutomation ? [
                'id' => $presetAutomation['id'],
                'name' => $presetAutomation['name'],
                'event' => $presetAutomation['trigger_type'],
                'conditions' => collect($presetAutomation['conditions'])->pluck('id')->all(),
                'actions' => collect($presetAutomation['actions'])->pluck('id')->all(),
            ] : null,
            'save' => [
                'enabled' => false,
                'hint' => 'Persistencia del builder: '.self::UPCOMING,
            ],
            'links' => [
                'dashboard' => route('admin.activecampaign.automations'),
                'list' => route('admin.activecampaign.automations.list'),
            ],
            'meta' => [
                'note' => 'Estructura visual Evento → Condiciones → Acciones. Sin drag & drop ni guardado en v1.1.',
            ],
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function catalog(): array
    {
        $abandonedEnabled = (bool) config('services.activecampaign.tag_abandoned_carts_enabled', true);
        $expiringEnabled = (bool) config('services.activecampaign.coupons_expiring_enabled', false);
        $couponsEnabled = (bool) config('services.activecampaign.enabled', true)
            && (bool) config('services.activecampaign.coupons_enabled', true);

        return [
            $this->automation(
                id: 'abandoned_carts',
                name: 'Tag carritos abandonados',
                description: 'Comando programado que etiqueta carritos inactivos hacia ActiveCampaign.',
                triggerType: 'cart_abandoned',
                triggerLabel: 'Inactividad de carrito (scheduler)',
                status: $abandonedEnabled ? 'active' : 'paused',
                lastRun: self::UNAVAILABLE,
                nextRun: $abandonedEnabled ? 'Cada 15 minutos (schedule)' : 'Pausada',
                source: 'console: activecampaign:tag-abandoned-carts',
                truth: 'disponible',
                conditions: [
                    ['id' => 'inactive_minutes', 'label' => 'Minutos de inactividad (config)', 'truth' => 'disponible'],
                ],
                actions: [
                    ['id' => 'add_tag', 'label' => 'Agregar tag AC', 'truth' => 'disponible', 'status' => 'instrumentado'],
                    ['id' => 'send_email', 'label' => 'Enviar correo', 'truth' => 'proximamente', 'status' => self::UPCOMING],
                ],
                dispatchPrefixes: [],
            ),
            $this->automation(
                id: 'expiring_coupons',
                name: 'Cupones por vencer',
                description: 'Sincroniza cupones próximos a expirar con ActiveCampaign.',
                triggerType: 'coupon_expiring',
                triggerLabel: 'Scheduler diario 08:00',
                status: $expiringEnabled ? 'active' : 'paused',
                lastRun: self::UNAVAILABLE,
                nextRun: $expiringEnabled ? 'Diario 08:00 (America/server)' : 'Pausada (flag off)',
                source: 'console: activecampaign:sync-expiring-coupons',
                truth: 'disponible',
                conditions: [
                    ['id' => 'expiring_days', 'label' => 'Ventana de días (config)', 'truth' => 'disponible'],
                ],
                actions: [
                    ['id' => 'add_tag', 'label' => 'Agregar tag / evento AC', 'truth' => 'disponible', 'status' => 'instrumentado'],
                    ['id' => 'send_whatsapp', 'label' => 'Enviar WhatsApp', 'truth' => 'proximamente', 'status' => self::UPCOMING],
                ],
                dispatchPrefixes: ['credit_expiring'],
            ),
            $this->automation(
                id: 'ac_dispatch_pipeline',
                name: 'Pipeline de dispatches locales',
                description: 'Cola local ActiveCampaignDispatch (créditos, promos, beneficiarios).',
                triggerType: 'activecampaign_dispatch',
                triggerLabel: 'Eventos credit_* / promo_* / pending_beneficiary_*',
                status: $couponsEnabled ? 'active' : 'paused',
                lastRun: $this->latestDispatchWhen(),
                nextRun: 'Bajo demanda (jobs/cola)',
                source: 'ActiveCampaignDispatch + workers',
                truth: 'disponible',
                conditions: [
                    ['id' => 'idempotency', 'label' => 'Idempotency key única', 'truth' => 'disponible'],
                    ['id' => 'coupons_enabled', 'label' => 'Flag coupons_enabled', 'truth' => 'disponible'],
                ],
                actions: [
                    ['id' => 'webhook', 'label' => 'Dispatch API ActiveCampaign', 'truth' => 'disponible', 'status' => 'instrumentado'],
                    ['id' => 'update_journey', 'label' => 'Actualizar Journey', 'truth' => 'proximamente', 'status' => self::UPCOMING],
                ],
                dispatchPrefixes: ['credit_', 'promo_', 'pending_beneficiary_'],
            ),
            $this->planned(
                'lab_purchase_flow',
                'Post-compra laboratorio',
                'laboratory_purchase',
                'Compra laboratorio (Timeline)',
                ['Enviar correo', 'Agregar tag', 'Actualizar Journey']
            ),
            $this->planned(
                'pharmacy_purchase_flow',
                'Post-compra farmacia',
                'pharmacy_purchase',
                'Compra farmacia (Timeline)',
                ['Enviar correo', 'Crear cupón', 'WhatsApp']
            ),
            $this->planned(
                'registration_onboarding',
                'Onboarding por registro',
                'registration',
                'Registro (Timeline)',
                ['Enviar correo', 'Agregar tag', 'Crear tarea']
            ),
            $this->planned(
                'invoice_flow',
                'Notificación de factura',
                'invoice',
                'Factura (Timeline)',
                ['Enviar correo', 'Webhook']
            ),
            $this->planned(
                'membership_flow',
                'Ciclo de membresía',
                'membership',
                'Membresía (Timeline)',
                ['Enviar correo', 'Actualizar Journey', 'Crear cupón']
            ),
            $this->planned(
                'lab_results_flow',
                'Resultados de laboratorio listos',
                'laboratory_results',
                'Resultados (Timeline)',
                ['Enviar correo', 'WhatsApp', 'Crear tarea']
            ),
            $this->planned(
                'beneficiary_flow',
                'Beneficiario agregado',
                'beneficiary_added',
                'Beneficiarios (Timeline)',
                ['Enviar correo', 'Agregar tag']
            ),
            $this->planned(
                'dispatch_ops_alert',
                'Alerta operativa por dispatch fallido',
                'activecampaign_dispatch',
                'Dispatch fallido (Event Center)',
                ['Crear tarea', 'Webhook', 'Enviar correo']
            ),
        ];
    }

    /**
     * @param  list<array{id: string, label: string, truth: string}>  $conditions
     * @param  list<array{id: string, label: string, truth: string, status: string}>  $actions
     * @param  list<string>  $dispatchPrefixes
     * @return array<string, mixed>
     */
    private function automation(
        string $id,
        string $name,
        string $description,
        string $triggerType,
        string $triggerLabel,
        string $status,
        string $lastRun,
        string $nextRun,
        string $source,
        string $truth,
        array $conditions,
        array $actions,
        array $dispatchPrefixes,
    ): array {
        $statusLabel = match ($status) {
            'active' => 'Activa',
            'paused' => 'Pausada',
            'planned' => self::UPCOMING,
            default => $status,
        };

        return [
            'id' => $id,
            'name' => $name,
            'description' => $description,
            'trigger_type' => $triggerType,
            'trigger_label' => $triggerLabel,
            'status' => $status,
            'status_label' => $statusLabel,
            'last_run' => $lastRun,
            'next_run' => $nextRun,
            'source' => $source,
            'truth' => $truth,
            'conditions' => $conditions,
            'actions' => $actions,
            'dispatch_event_prefixes' => $dispatchPrefixes,
            'detail_url' => route('admin.activecampaign.automations.show', ['automation' => $id]),
        ];
    }

    /**
     * @param  list<string>  $actionLabels
     * @return array<string, mixed>
     */
    private function planned(
        string $id,
        string $name,
        string $triggerType,
        string $triggerLabel,
        array $actionLabels,
    ): array {
        $actions = collect($actionLabels)->map(function (string $label, int $i) {
            $slug = match (true) {
                str_contains(mb_strtolower($label), 'correo') => 'send_email',
                str_contains(mb_strtolower($label), 'whatsapp') => 'send_whatsapp',
                str_contains(mb_strtolower($label), 'cupón') => 'create_coupon',
                str_contains(mb_strtolower($label), 'tag') => 'add_tag',
                str_contains(mb_strtolower($label), 'journey') => 'update_journey',
                str_contains(mb_strtolower($label), 'tarea') => 'create_task',
                str_contains(mb_strtolower($label), 'webhook') => 'webhook',
                default => 'action_'.$i,
            };

            return [
                'id' => $slug,
                'label' => $label,
                'truth' => 'proximamente',
                'status' => self::UPCOMING,
            ];
        })->all();

        return $this->automation(
            id: $id,
            name: $name,
            description: 'Automatización preparada sobre triggers del Timeline / Event Center. Acciones aún no ejecutables.',
            triggerType: $triggerType,
            triggerLabel: $triggerLabel,
            status: 'planned',
            lastRun: self::UPCOMING,
            nextRun: self::UPCOMING,
            source: 'Catálogo Automation Center (sin motor de ejecución)',
            truth: 'proximamente',
            conditions: [
                ['id' => 'tba', 'label' => 'Condiciones: '.self::UPCOMING, 'truth' => 'proximamente'],
            ],
            actions: $actions,
            dispatchPrefixes: [],
        );
    }

    /**
     * @return list<array{id: string, label: string, family: string, truth: string}>
     */
    private function triggerCatalog(): array
    {
        return [
            ['id' => 'laboratory_purchase', 'label' => 'Compra laboratorio', 'family' => 'Timeline', 'truth' => 'disponible'],
            ['id' => 'pharmacy_purchase', 'label' => 'Compra farmacia', 'family' => 'Timeline', 'truth' => 'disponible'],
            ['id' => 'registration', 'label' => 'Registro', 'family' => 'Timeline', 'truth' => 'disponible'],
            ['id' => 'invoice', 'label' => 'Factura', 'family' => 'Timeline', 'truth' => 'disponible'],
            ['id' => 'membership', 'label' => 'Membresía', 'family' => 'Timeline', 'truth' => 'disponible'],
            ['id' => 'laboratory_results', 'label' => 'Resultados', 'family' => 'Timeline', 'truth' => 'disponible'],
            ['id' => 'beneficiary_added', 'label' => 'Beneficiarios', 'family' => 'Timeline', 'truth' => 'disponible'],
            ['id' => 'activecampaign_dispatch', 'label' => 'Dispatch', 'family' => 'Event Center', 'truth' => 'disponible'],
            ['id' => 'cart_abandoned', 'label' => 'Carrito abandonado', 'family' => 'Scheduler', 'truth' => 'disponible'],
            ['id' => 'coupon_expiring', 'label' => 'Cupón por vencer', 'family' => 'Scheduler', 'truth' => 'disponible'],
        ];
    }

    /**
     * @return list<array{id: string, label: string, truth: string, channel: string}>
     */
    private function actionCatalog(): array
    {
        return [
            ['id' => 'send_email', 'label' => 'Enviar correo', 'truth' => 'proximamente', 'channel' => 'Mailgun / Integrations Hub'],
            ['id' => 'send_whatsapp', 'label' => 'Enviar WhatsApp', 'truth' => 'proximamente', 'channel' => 'WhatsApp / Integrations Hub'],
            ['id' => 'create_coupon', 'label' => 'Crear cupón', 'truth' => 'proximamente', 'channel' => 'Famedic Coupons'],
            ['id' => 'add_tag', 'label' => 'Agregar tag', 'truth' => 'disponible', 'channel' => 'ActiveCampaign'],
            ['id' => 'update_journey', 'label' => 'Actualizar Journey', 'truth' => 'proximamente', 'channel' => 'Customer Journey'],
            ['id' => 'create_task', 'label' => 'Crear tarea', 'truth' => 'proximamente', 'channel' => 'Ops'],
            ['id' => 'webhook', 'label' => 'Webhook', 'truth' => 'disponible', 'channel' => 'ActiveCampaign Dispatch'],
        ];
    }

    /**
     * @param  list<string>  $prefixes
     * @return list<array<string, mixed>>
     */
    private function recentDispatchRuns(int $limit, array $prefixes = []): array
    {
        $query = ActiveCampaignDispatch::query()->orderByDesc('id')->limit($limit);

        if ($prefixes !== []) {
            $query->where(function ($q) use ($prefixes) {
                foreach ($prefixes as $prefix) {
                    $q->orWhere('event_type', 'like', $prefix.'%');
                }
            });
        }

        return $query
            ->get(['id', 'event_type', 'email', 'status', 'created_at', 'synced_at', 'updated_at', 'last_error'])
            ->map(function (ActiveCampaignDispatch $row) {
                $when = $row->synced_at ?? $row->updated_at ?? $row->created_at;
                $statusLabel = match ($row->status) {
                    ActiveCampaignDispatch::STATUS_SYNCED => 'Sincronizado',
                    ActiveCampaignDispatch::STATUS_FAILED => 'Error',
                    ActiveCampaignDispatch::STATUS_PENDING => 'Pendiente',
                    ActiveCampaignDispatch::STATUS_PROCESSING => 'Procesando',
                    ActiveCampaignDispatch::STATUS_SKIPPED => 'Omitido',
                    default => (string) $row->status,
                };

                return [
                    'id' => 'run-'.$row->id,
                    'label' => (string) $row->event_type,
                    'email' => $row->email ?: '—',
                    'status' => (string) $row->status,
                    'status_label' => $statusLabel,
                    'when' => $when
                        ? Carbon::parse($when)->timezone(self::TZ)->format('d/m/Y H:i')
                        : self::UNAVAILABLE,
                    'error' => $row->status === ActiveCampaignDispatch::STATUS_FAILED
                        ? $this->shortError($row->last_error)
                        : null,
                ];
            })
            ->all();
    }

    private function latestDispatchWhen(): string
    {
        $at = ActiveCampaignDispatch::query()->max('synced_at')
            ?: ActiveCampaignDispatch::query()->max('created_at');

        if (! $at) {
            return self::UNAVAILABLE;
        }

        return Carbon::parse($at)->timezone(self::TZ)->format('d/m/Y H:i');
    }

    private function shortError(?string $error): string
    {
        if ($error === null || trim($error) === '') {
            return self::UNAVAILABLE;
        }

        $line = trim(explode("\n", $error)[0] ?? '');
        $line = preg_replace('/\s+/', ' ', $line) ?? $line;
        $line = preg_replace('/(\/[\w.\-]+)+/', '[path]', $line) ?? $line;

        return mb_strlen($line) > 100 ? mb_substr($line, 0, 97).'…' : $line;
    }
}
