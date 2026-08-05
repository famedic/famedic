<?php

namespace App\Services\Workspace;

use App\Models\ActiveCampaignDispatch;
use App\Services\ActiveCampaign\ActiveCampaignDashboardService;
use App\Support\ActiveCampaign\ActiveCampaignDashboardFilter;
use App\Support\Workspace\ActiveCampaignHubCatalog;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;

/**
 * Producto ActiveCampaign Hub — landing de navegación y monitoreo.
 * Reutiliza ActiveCampaignDashboardService; no duplica agregaciones de negocio.
 */
class ActiveCampaignHubService
{
    public function __construct(
        private ActiveCampaignDashboardService $dashboard,
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function build(Request $request): array
    {
        $filter = ActiveCampaignDashboardFilter::fromRequest($request);
        $overview = $this->dashboard->buildOverview($filter);

        $healthById = collect($overview['health'] ?? [])->keyBy('id');
        $businessById = collect($overview['business'] ?? [])->keyBy('id');

        $configured = filled(config('services.activecampaign.endpoint'))
            && filled(config('services.activecampaign.token'));
        $enabled = (bool) config('services.activecampaign.enabled', true);
        $connected = $configured && $enabled;

        $statusCard = $healthById->get('integration');
        $lastSyncCard = $healthById->get('last_sync');
        $errorsCard = $healthById->get('errors');
        $backlogCard = $healthById->get('backlog');
        $patientsCard = $healthById->get('patients');
        $creditsCard = $healthById->get('credits');

        $errorCount = (int) preg_replace('/\D+/', '', (string) ($errorsCard['value'] ?? '0'));
        $pendingCount = (int) preg_replace('/\D+/', '', (string) ($backlogCard['value'] ?? '0'));

        $connectionTone = ! $connected
            ? 'amber'
            : ($errorCount > 0 ? 'amber' : 'green');

        $header = [
            'api_status' => $statusCard['value'] ?? ($connected ? 'Operativo' : 'Revisar'),
            'api_status_tone' => $statusCard['tone'] ?? $connectionTone,
            'connection_label' => $connected ? 'Conectado' : 'Desconectado',
            'connection_tone' => $connectionTone,
            'last_sync' => $lastSyncCard['value'] ?? 'No disponible',
            'workspace' => (string) config('services.activecampaign.account_name', 'Famedic · ActiveCampaign'),
            'api_version' => (string) config('services.activecampaign.api_version', 'v3'),
        ];

        $kpis = [
            $this->kpi('contacts', 'Contactos sincronizados', $patientsCard['value'] ?? '—', $patientsCard['hint'] ?? null),
            $this->kpi('lists', 'Listas', '—', 'Inventario AC · ver Contactos / Tags'),
            $this->kpi('tags', 'Tags', '—', 'Ver Tags Manager'),
            $this->kpi('fields', 'Campos personalizados', '—', 'Ver Custom Fields Manager'),
            $this->kpi('campaigns', 'Campañas activas', $businessById->get('promo')['value_formatted'] ?? '—', 'Promos sincronizadas (proxy)'),
            $this->kpi('automations', 'Automatizaciones', '—', 'Ver Automation Center'),
            $this->kpi('events', 'Eventos enviados', $this->activityCountLabel($overview), 'Actividad reciente del periodo'),
            $this->kpi('orders', 'Pedidos sincronizados', $businessById->get('lab')['value_formatted'] ?? '—', 'Proxy laboratorio / ecommerce'),
            $this->kpi('errors', 'Errores', $errorsCard['value'] ?? '0', $errorsCard['hint'] ?? null),
            $this->kpi('last-sync', 'Tiempo desde la última sincronización', $lastSyncCard['value'] ?? '—', null),
        ];

        $tab = $request->string('tab')->toString() ?: 'overview';
        $validTabs = collect(ActiveCampaignHubCatalog::tabs())->pluck('id')->all();
        if (! in_array($tab, $validTabs, true)) {
            $tab = 'overview';
        }

        $dispatchStats = $this->dispatchStatsByType();

        return [
            'header' => $header,
            'kpis' => $kpis,
            'tabs' => ActiveCampaignHubCatalog::tabs(),
            'filters' => ['tab' => $tab],
            'modules' => $this->resolveModules(ActiveCampaignHubCatalog::overviewModules()),
            'tab_shortcuts' => $this->resolveShortcuts($tab),
            'famedic_integrations' => $this->resolveFamedicIntegrations(),
            'event_catalog' => $this->buildEventCatalog($dispatchStats),
            'integration_status' => $this->buildIntegrationStatus($header, $healthById, $businessById, $errorCount, $pendingCount),
            'timeline' => $this->buildTimeline($overview),
            'quick_actions' => $this->resolveQuickActions(),
            'copilot' => $this->buildCopilot($header, $errorCount, $pendingCount, $patientsCard, $businessById),
            'future_channels' => ActiveCampaignHubCatalog::futureChannels(),
            'links' => [
                'sync' => $this->safeRoute('admin.activecampaign.health'),
                'settings' => $this->safeRoute('admin.activecampaign.settings'),
                'logs' => $this->safeRoute('admin.activecampaign.logs'),
                'dashboard' => $this->safeRoute('admin.activecampaign.dashboard'),
                'workspace' => route('admin.workspace.index'),
                'customer_engagement' => $this->safeRoute('admin.workspace.show', ['workspace' => 'customer-engagement']),
                'integrations_hub' => $this->safeRoute('admin.activecampaign.integrations'),
            ],
            'meta' => array_merge($overview['meta'] ?? [], [
                'generated_at' => now('America/Monterrey')->format('d/m/Y H:i'),
                'credits_hint' => $creditsCard['value'] ?? null,
            ]),
        ];
    }

    /**
     * @param  list<array<string, mixed>>  $modules
     * @return list<array<string, mixed>>
     */
    private function resolveModules(array $modules): array
    {
        return collect($modules)
            ->map(function (array $module) {
                $href = $this->safeRoute($module['href_route'] ?? null);

                return [
                    'id' => $module['id'],
                    'emoji' => $module['emoji'],
                    'title' => $module['title'],
                    'description' => $module['description'],
                    'items' => $module['items'] ?? [],
                    'href' => $href,
                    'status' => $href ? 'active' : 'coming_soon',
                ];
            })
            ->values()
            ->all();
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function resolveShortcuts(string $tab): array
    {
        $map = ActiveCampaignHubCatalog::tabShortcuts();
        $items = $map[$tab] ?? [];

        return collect($items)
            ->map(function (array $item) {
                $href = $this->safeRoute($item['route'] ?? null);

                return [
                    'id' => $item['id'],
                    'label' => $item['label'],
                    'href' => $href,
                    'status' => $href ? 'active' : 'coming_soon',
                ];
            })
            ->filter(fn (array $i) => $i['href'])
            ->values()
            ->all();
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function resolveFamedicIntegrations(): array
    {
        return collect(ActiveCampaignHubCatalog::famedicIntegrations())
            ->map(function (array $item) {
                $href = $this->safeRoute($item['href_route'] ?? null);

                return [
                    'id' => $item['id'],
                    'label' => $item['label'],
                    'signal' => $item['signal'] ?? null,
                    'href' => $href,
                    'status' => $href ? 'active' : 'coming_soon',
                ];
            })
            ->values()
            ->all();
    }

    /**
     * @param  array<string, array{count: int, errors: int, last_at: ?string}>  $stats
     * @return list<array<string, mixed>>
     */
    private function buildEventCatalog(array $stats): array
    {
        $logsHref = $this->safeRoute('admin.activecampaign.logs');

        return collect(ActiveCampaignHubCatalog::eventCatalog())
            ->map(function (array $event) use ($stats, $logsHref) {
                $key = $event['key'];
                $row = $stats[$key] ?? null;
                $source = $event['source'];

                if ($source === 'dispatch') {
                    $count = $row['count'] ?? 0;
                    $errors = $row['errors'] ?? 0;
                    $last = $row['last_at'] ?? null;
                    $tone = $errors > 0 ? 'red' : ($count > 0 ? 'green' : 'amber');
                    $status = $errors > 0 ? 'Con errores' : ($count > 0 ? 'Activo' : 'Sin envíos');
                } else {
                    $count = null;
                    $errors = null;
                    $last = null;
                    $tone = 'green';
                    $status = 'Instrumentado';
                }

                return [
                    'key' => $key,
                    'label' => $event['label'],
                    'source' => $source,
                    'status' => $status,
                    'tone' => $tone,
                    'count' => $count,
                    'errors' => $errors,
                    'last_at' => $last,
                    'logs_href' => $logsHref,
                ];
            })
            ->values()
            ->all();
    }

    /**
     * @param  \Illuminate\Support\Collection<string, array<string, mixed>>  $healthById
     * @param  \Illuminate\Support\Collection<string, array<string, mixed>>  $businessById
     * @return list<array<string, mixed>>
     */
    private function buildIntegrationStatus(
        array $header,
        $healthById,
        $businessById,
        int $errorCount,
        int $pendingCount,
    ): array {
        $connected = ($header['connection_tone'] ?? '') === 'green';

        return collect(ActiveCampaignHubCatalog::integrationDomains())
            ->map(function (array $domain) use ($healthById, $businessById, $connected, $errorCount, $pendingCount) {
                $href = $this->safeRoute($domain['href_route'] ?? null);
                $quantity = '—';
                $lastRun = '—';
                $errors = '—';
                $tone = $connected ? 'green' : 'amber';
                $status = $connected ? 'Operativo' : 'Revisar';

                if (($domain['flag'] ?? null) === 'roadmap') {
                    return [
                        'id' => $domain['id'],
                        'label' => $domain['label'],
                        'status' => 'Roadmap',
                        'tone' => 'amber',
                        'last_run' => '—',
                        'quantity' => '—',
                        'errors' => '—',
                        'href' => $href,
                    ];
                }

                if (! empty($domain['health_id'])) {
                    $card = $healthById->get($domain['health_id']);
                    $quantity = (string) ($card['value'] ?? '—');
                    if ($domain['health_id'] === 'patients' || $domain['health_id'] === 'credits') {
                        $lastRun = (string) ($healthById->get('last_sync')['value'] ?? '—');
                    }
                }

                if (! empty($domain['business_id'])) {
                    $kpi = $businessById->get($domain['business_id']);
                    $quantity = (string) ($kpi['value_formatted'] ?? '—');
                }

                if ($domain['id'] === 'events') {
                    $errors = (string) $errorCount;
                    $tone = $errorCount > 0 ? 'red' : $tone;
                    $status = $errorCount > 0 ? 'Con errores' : $status;
                }

                if ($domain['id'] === 'health') {
                    $quantity = (string) $pendingCount;
                    $errors = (string) $errorCount;
                    $lastRun = (string) ($healthById->get('last_sync')['value'] ?? '—');
                    if ($errorCount > 0) {
                        $tone = 'red';
                        $status = 'Atención';
                    } elseif ($pendingCount > 0) {
                        $tone = 'amber';
                        $status = 'Pendientes';
                    }
                }

                if ($domain['id'] === 'webhooks') {
                    $tone = 'amber';
                    $status = 'Roadmap';
                }

                return [
                    'id' => $domain['id'],
                    'label' => $domain['label'],
                    'status' => $status,
                    'tone' => $tone,
                    'last_run' => $lastRun,
                    'quantity' => $quantity,
                    'errors' => $errors,
                    'href' => $href,
                ];
            })
            ->values()
            ->all();
    }

    /**
     * @param  array<string, mixed>  $overview
     * @return list<array<string, mixed>>
     */
    private function buildTimeline(array $overview): array
    {
        $items = [];

        foreach (($overview['tables']['recent_activity'] ?? []) as $row) {
            $items[] = [
                'type' => 'event',
                'label' => $row['event_type'] ?? 'Evento',
                'detail' => $row['email'] ?? $row['status'] ?? null,
                'at' => $row['when'] ?? $row['synced_at'] ?? null,
            ];
        }

        foreach (($overview['tables']['recent_errors'] ?? []) as $row) {
            $items[] = [
                'type' => 'error',
                'label' => $row['event_type'] ?? 'Error de sync',
                'detail' => $row['last_error'] ?? $row['email'] ?? null,
                'at' => $row['when'] ?? null,
            ];
        }

        foreach (($overview['tables']['in_flight'] ?? []) as $row) {
            $items[] = [
                'type' => 'sync',
                'label' => $row['event_type'] ?? 'Sync pendiente',
                'detail' => trim(($row['status'] ?? 'pending').' · '.($row['email'] ?? '')),
                'at' => $row['when'] ?? null,
            ];
        }

        return array_slice($items, 0, 16);
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function resolveQuickActions(): array
    {
        return collect(ActiveCampaignHubCatalog::quickActions())
            ->map(function (array $action) {
                $href = $this->safeRoute($action['route'] ?? null);

                return [
                    'id' => $action['id'],
                    'label' => $action['label'],
                    'href' => $href,
                ];
            })
            ->filter(fn (array $a) => $a['href'])
            ->values()
            ->all();
    }

    /**
     * @param  \Illuminate\Support\Collection<string, array<string, mixed>>  $businessById
     * @return array<string, mixed>
     */
    private function buildCopilot(
        array $header,
        int $errorCount,
        int $pendingCount,
        ?array $patientsCard,
        $businessById,
    ): array {
        $findings = [
            'Estado API: '.$header['api_status'].' · '.$header['connection_label'].'.',
            'Última sincronización: '.$header['last_sync'].'.',
        ];

        if ($errorCount > 0) {
            $findings[] = "Hay {$errorCount} errores de dispatch en el periodo.";
        } else {
            $findings[] = 'No hay errores de dispatch destacados en el periodo.';
        }

        if ($pendingCount > 0) {
            $findings[] = "Hay {$pendingCount} dispatches pendientes o en proceso.";
        }

        $abandoned = $businessById->get('abandoned');
        if ($abandoned && ($abandoned['value_formatted'] ?? '0') !== '0') {
            $findings[] = 'Señal de carritos abandonados activa: '.($abandoned['value_formatted'] ?? '—').'.';
        }

        $contacts = (string) ($patientsCard['value'] ?? '—');
        if ($contacts !== '—' && $contacts !== '0') {
            $findings[] = "Contactos / pacientes del periodo: {$contacts}.";
        }

        $recommendations = [
            $errorCount > 0
                ? 'Revisar Logs Center y reintentar dispatches fallidos.'
                : 'Mantener monitoreo en Health Center durante el turno.',
            'Validar Tags Manager y Custom Fields antes de nuevas campañas.',
            'Revisar Automation Center para flujos de carrito abandonado y recuperación.',
            'Cuando actives WhatsApp / SMS / Push, aparecerán en Roadmap sin cambiar el Sidebar.',
        ];

        return [
            'headline' => 'ActiveCampaign Copilot',
            'findings' => $findings,
            'recommendations' => $recommendations,
            'actions' => array_values(array_filter([
                [
                    'id' => 'segment',
                    'label' => 'Crear Segmento',
                    'href' => $this->safeRoute('admin.activecampaign.tags'),
                ],
                [
                    'id' => 'campaign',
                    'label' => 'Crear Campaña',
                    'href' => $this->safeRoute('admin.activecampaign.analytics'),
                ],
                [
                    'id' => 'optimize-tags',
                    'label' => 'Optimizar Tags',
                    'href' => $this->safeRoute('admin.activecampaign.tags'),
                ],
            ], fn (array $a) => filled($a['href']))),
        ];
    }

    /**
     * @return array<string, array{count: int, errors: int, last_at: ?string}>
     */
    private function dispatchStatsByType(): array
    {
        try {
            if (! Schema::hasTable('activecampaign_dispatches')) {
                return [];
            }

            $rows = ActiveCampaignDispatch::query()
                ->selectRaw('event_type, COUNT(*) as total, SUM(CASE WHEN status = ? THEN 1 ELSE 0 END) as errors, MAX(COALESCE(synced_at, updated_at, created_at)) as last_at', [
                    ActiveCampaignDispatch::STATUS_FAILED,
                ])
                ->groupBy('event_type')
                ->get();

            $out = [];
            foreach ($rows as $row) {
                $out[(string) $row->event_type] = [
                    'count' => (int) $row->total,
                    'errors' => (int) $row->errors,
                    'last_at' => $row->last_at
                        ? \Carbon\Carbon::parse($row->last_at)->timezone('America/Monterrey')->diffForHumans()
                        : null,
                ];
            }

            return $out;
        } catch (\Throwable) {
            return [];
        }
    }

    /**
     * @param  array<string, mixed>  $overview
     */
    private function activityCountLabel(array $overview): string
    {
        $n = count($overview['tables']['recent_activity'] ?? [])
            + count($overview['tables']['recent_errors'] ?? []);

        return number_format($n);
    }

    private function kpi(string $id, string $label, mixed $value, ?string $hint): array
    {
        return [
            'id' => $id,
            'label' => $label,
            'value_formatted' => $value === null || $value === '' ? '—' : (string) $value,
            'hint' => $hint,
        ];
    }

    private function safeRoute(?string $name, array $params = []): ?string
    {
        if (! $name || ! Route::has($name)) {
            return null;
        }

        try {
            return $params === [] ? route($name) : route($name, $params);
        } catch (\Throwable) {
            return null;
        }
    }
}
