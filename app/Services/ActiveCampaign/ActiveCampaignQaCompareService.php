<?php

namespace App\Services\ActiveCampaign;

use App\Support\ActiveCampaign\ActiveCampaignDashboardFilter;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;

/**
 * QA vs Production — consola de comparación entre ambientes (capa de composición).
 * No compara servidores reales ni edita config. Solo presencia/ausencia (secretos nunca se revelan).
 * El lado remoto no instrumentado se marca como requiere instrumentación / próximamente.
 */
class ActiveCampaignQaCompareService
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
            Cache::forget($this->cacheKey('raw-diff'));
            Cache::forget($this->cacheKey('executive'));
        }

        $overview = $this->dashboard->buildOverview($filter);
        $rawKey = $this->cacheKey('raw-diff');

        $bundle = Cache::remember($rawKey, now()->addMinutes(5), function () use ($overview) {
            return $this->buildBundle($overview);
        });

        $items = collect($bundle['rows']);
        $filtered = $this->applyFilters($items, $filters);

        return [
            'filters' => $filters,
            'filterOptions' => $this->filterOptions(),
            'summary' => $bundle['summary'],
            'rows' => $filtered->map(fn (array $r) => $this->listItem($r))->values()->all(),
            'actions' => $this->quickActions(),
            'meta' => [
                ...($overview['meta'] ?? []),
                'purpose' => 'Detectar drifts de configuración entre QA y Producción — sin comparar servidores reales.',
                'source_of_truth' => 'config() ambiente actual · mapas Configuration/Integrations/Health/Automation · remoto no instrumentado',
                'total' => $filtered->count(),
                'note' => 'Solo Presente/Ausente (flags: true/false). Tokens nunca se muestran. Remoto = requiere instrumentación.',
                'current_environment' => $bundle['current_environment'],
                'qa_role' => $bundle['qa_role'],
                'prod_role' => $bundle['prod_role'],
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
        $rawKey = $this->cacheKey('raw-diff');

        $bundle = Cache::remember($rawKey, now()->addMinutes(5), function () use ($overview) {
            return $this->buildBundle($overview);
        });

        $items = collect($bundle['rows']);

        return [
            'equal' => [
                ['label' => 'Iguales', 'value' => $items->where('compare_status', 'equal')->count()],
                ['label' => 'Distintas', 'value' => $items->where('compare_status', 'different')->count()],
                ['label' => 'Pendientes', 'value' => $items->where('compare_status', 'pending')->count()],
            ],
            'different' => $items
                ->where('compare_status', 'different')
                ->take(12)
                ->map(fn (array $r) => [
                    'label' => \Illuminate\Support\Str::limit((string) $r['name'], 28),
                    'value' => 1,
                ])
                ->values()
                ->all(),
            'integrations' => $items
                ->where('category', 'Integraciones')
                ->groupBy('compare_status')
                ->map(fn ($g, $k) => [
                    'label' => $this->statusLabel((string) $k),
                    'value' => $g->count(),
                ])
                ->values()
                ->all(),
            'feature_flags' => $items
                ->where('category', 'Feature Flags')
                ->map(fn (array $r) => [
                    'label' => \Illuminate\Support\Str::limit((string) $r['name'], 24),
                    'value' => ($r['compare_status'] === 'equal') ? 1 : 0,
                ])
                ->values()
                ->all(),
            'gaps' => $this->gaps(),
            'truth' => 'proxy',
            'note' => 'Agregados del inventario comparado (cache 5 min). Remoto no instrumentado → pendientes.',
        ];
    }

    /**
     * @return array<string, mixed>|null
     */
    public function buildDetail(string $rowId, Request $request): ?array
    {
        if ($rowId === '') {
            return null;
        }

        $filter = ActiveCampaignDashboardFilter::fromRequest($request);
        $overview = $this->dashboard->buildOverview($filter);
        $rawKey = $this->cacheKey('raw-diff');

        $bundle = Cache::remember($rawKey, now()->addMinutes(5), function () use ($overview) {
            return $this->buildBundle($overview);
        });

        $item = collect($bundle['rows'])->firstWhere('id', $rowId);
        if (! $item) {
            return null;
        }

        return [
            ...$item,
            'qa_config' => [
                'label' => 'Configuración QA',
                'value' => $item['qa_value'],
                'truth' => $item['qa_truth'],
                'note' => $item['qa_note'],
            ],
            'prod_config' => [
                'label' => 'Configuración Producción',
                'value' => $item['prod_value'],
                'truth' => $item['prod_truth'],
                'note' => $item['prod_note'],
            ],
            'impact' => $item['impact'],
            'affected_modules' => $item['affected_modules'],
            'recommendations' => $item['recommendations'],
            'quick_links' => $item['quick_links'] ?? $this->defaultLinks(),
            'truth' => $item['truth'],
        ];
    }

    private function cacheKey(string $suffix): string
    {
        return 'mi-qa-compare:v1:'.sha1(app()->environment().'|'.$suffix);
    }

    /**
     * @return array<string, mixed>
     */
    private function resolveFilters(Request $request): array
    {
        return [
            'category' => trim((string) $request->input('category', '')),
            'status' => trim((string) $request->input('status', '')),
            'environment' => trim((string) $request->input('environment', '')),
            'criticality' => trim((string) $request->input('criticality', '')),
        ];
    }

    /**
     * @return array<string, list<array{value: string, label: string}>>
     */
    private function filterOptions(): array
    {
        return [
            'categories' => [
                ['value' => '', 'label' => 'Todas'],
                ['value' => 'General', 'label' => 'General'],
                ['value' => 'Integraciones', 'label' => 'Integraciones'],
                ['value' => 'Automation', 'label' => 'Automation'],
                ['value' => 'Analytics', 'label' => 'Analytics'],
                ['value' => 'Feature Flags', 'label' => 'Feature Flags'],
                ['value' => 'Health', 'label' => 'Health'],
                ['value' => 'Jobs', 'label' => 'Jobs'],
                ['value' => 'Queues', 'label' => 'Queues'],
            ],
            'statuses' => [
                ['value' => '', 'label' => 'Todos'],
                ['value' => 'equal', 'label' => 'Iguales'],
                ['value' => 'different', 'label' => 'Distintas'],
                ['value' => 'pending', 'label' => 'Pendientes'],
            ],
            'environments' => [
                ['value' => '', 'label' => 'Todos'],
                ['value' => 'qa', 'label' => 'Con señal QA'],
                ['value' => 'production', 'label' => 'Con señal Producción'],
            ],
            'criticalities' => [
                ['value' => '', 'label' => 'Todas'],
                ['value' => 'critical', 'label' => 'Críticas'],
                ['value' => 'normal', 'label' => 'Normales'],
            ],
        ];
    }

    /**
     * @param  array<string, mixed>  $overview
     * @return array{rows: list<array<string, mixed>>, summary: list<array<string, mixed>>, current_environment: string, qa_role: string, prod_role: string}
     */
    private function buildBundle(array $overview): array
    {
        $env = app()->environment();
        $currentIsProd = $env === 'production';
        $currentIsQa = in_array($env, ['local', 'staging', 'testing', 'development'], true);

        $qaRole = $currentIsQa
            ? 'Ambiente actual ('.$env.')'
            : 'Remoto no instrumentado';
        $prodRole = $currentIsProd
            ? 'Ambiente actual (production)'
            : 'Remoto no instrumentado';

        $rows = [];
        foreach ($this->registry($overview) as $def) {
            $rows[] = $this->hydrate($def, $currentIsQa, $currentIsProd, $env);
        }

        $col = collect($rows);
        $diff = $col->where('compare_status', 'different');
        $pending = $col->where('compare_status', 'pending');

        $qaKnown = $col->where('qa_truth', 'disponible');
        $prodKnown = $col->where('prod_truth', 'disponible');

        $qaHealth = $this->sideHealth($qaKnown);
        $prodHealth = $this->sideHealth($prodKnown);

        $summary = [
            [
                'id' => 'qa',
                'label' => 'Estado QA',
                'value' => $qaHealth['label'],
                'tone' => $qaHealth['tone'],
                'hint' => $qaRole,
                'truth' => $currentIsQa ? 'disponible' : 'instrumentacion',
            ],
            [
                'id' => 'prod',
                'label' => 'Estado Producción',
                'value' => $prodHealth['label'],
                'tone' => $prodHealth['tone'],
                'hint' => $prodRole,
                'truth' => $currentIsProd ? 'disponible' : 'instrumentacion',
            ],
            [
                'id' => 'diffs',
                'label' => 'Diferencias encontradas',
                'value' => (string) $diff->count(),
                'tone' => $diff->isNotEmpty() ? 'amber' : 'default',
                'hint' => 'Ambos lados conocidos y distintos',
                'truth' => 'proxy',
            ],
            [
                'id' => 'integrations',
                'label' => 'Integraciones distintas',
                'value' => (string) $diff->where('category', 'Integraciones')->count(),
                'tone' => 'sky',
                'hint' => 'Solo drifts confirmados',
                'truth' => 'proxy',
            ],
            [
                'id' => 'flags',
                'label' => 'Feature Flags distintas',
                'value' => (string) $diff->where('category', 'Feature Flags')->count(),
                'tone' => 'sky',
                'hint' => 'Toggles con señal en ambos lados',
                'truth' => 'proxy',
            ],
            [
                'id' => 'critical',
                'label' => 'Configuraciones críticas',
                'value' => (string) $col->where('critical', true)->filter(fn (array $r) => in_array($r['compare_status'], ['different', 'pending'], true))->count(),
                'tone' => 'amber',
                'hint' => 'Críticas distintas o pendientes de remoto',
                'truth' => 'proxy',
            ],
        ];

        return [
            'rows' => $rows,
            'summary' => $summary,
            'current_environment' => $env,
            'qa_role' => $qaRole,
            'prod_role' => $prodRole,
            'pending_count' => $pending->count(),
        ];
    }

    /**
     * @param  \Illuminate\Support\Collection<int, array<string, mixed>>  $known
     * @return array{label: string, tone: string}
     */
    private function sideHealth($known): array
    {
        if ($known->isEmpty()) {
            return ['label' => 'N/D', 'tone' => 'zinc'];
        }

        // Health: any critical key absent on current side
        $absentCritical = $known->where('critical', true)->filter(fn (array $r) => ($r['current_presence'] ?? true) === false);
        if ($absentCritical->isNotEmpty()) {
            return ['label' => 'Crítico', 'tone' => 'red'];
        }

        $master = config('services.activecampaign.enabled');
        if (is_bool($master) && ! $master) {
            return ['label' => 'Parcial', 'tone' => 'amber'];
        }

        return ['label' => 'OK', 'tone' => 'default'];
    }

    /**
     * @param  array<string, mixed>  $overview
     * @return list<array<string, mixed>>
     */
    private function registry(array $overview): array
    {
        $ac = 'services.activecampaign.';
        $health = collect($overview['health'] ?? [])->keyBy('id');

        return [
            // General
            $this->def('app.env', 'Application Environment', 'General', 'app.env', true, false,
                'Ambiente Laravel actual.',
                ['Health Center', 'Configuration Center'],
                'Define el rol del ambiente en el comparador.',
                ['Confirmar APP_ENV en cada deploy.', 'Instrumentar snapshot remoto para cerrar el lado opuesto.']),
            $this->def('app.debug', 'Application Debug', 'General', 'app.debug', true, true,
                'Modo debug.',
                ['Logs Center', 'Health Center'],
                'Debug activo en producción es riesgo de seguridad.',
                ['Mantener false en production.', 'Validar en release checklist.']),
            $this->def('app.url', 'Application URL', 'General', 'app.url', false, false,
                'URL base de la app.',
                ['Integrations Hub', 'Notification Center'],
                'URLs incorrectas rompen callbacks y links.',
                ['Verificar APP_URL por ambiente.']),

            // Integraciones
            $this->def('ac.endpoint', 'ActiveCampaign Endpoint', 'Integraciones', $ac.'endpoint', true, false,
                'URL API ActiveCampaign.',
                ['Integrations Hub', 'Health Center', 'Event Center'],
                'Sin endpoint la integración no opera.',
                ['Asegurar credenciales por ambiente.', 'Comparar presencia QA vs Prod vía snapshot.']),
            $this->def('ac.token', 'ActiveCampaign Token', 'Integraciones', $ac.'token', true, false,
                'Token API (solo presencia).',
                ['Integrations Hub', 'Health Center'],
                'Secreto crítico; nunca se muestra.',
                ['Rotar tokens por ambiente.', 'Nunca copiar secretos en tickets.']),
            $this->def('ac.account_id', 'ActiveCampaign Account ID', 'Integraciones', $ac.'account_id', false, false,
                'Account ID para event tracking.',
                ['Event Center', 'Analytics'],
                'Drift puede silenciar eventos de sitio.',
                ['Validar account_id en QA y Prod.']),
            $this->def('ac.event_key', 'ActiveCampaign Event Key', 'Integraciones', $ac.'event_key', false, false,
                'Event key (solo presencia).',
                ['Event Center', 'Analytics'],
                'Secreto de tracking.',
                ['Confirmar presencia sin exponer valor.']),
            $this->def('mail.default', 'Mailer default', 'Integraciones', 'mail.default', false, false,
                'Canal de correo por defecto.',
                ['Notification Center', 'Integrations Hub'],
                'Afecta notificaciones operativas.',
                ['QA suele usar log; Prod mailgun/smtp.']),

            // Feature flags
            $this->def('ac.enabled', 'AC Enabled', 'Feature Flags', $ac.'enabled', true, true,
                'Master switch ActiveCampaign.',
                ['Automation Center', 'Health Center', 'Integrations Hub'],
                'Desactivado detiene sync.',
                ['Alinear flags entre QA y Prod según plan de release.']),
            $this->def('ac.coupons_enabled', 'Coupons Enabled', 'Feature Flags', $ac.'coupons_enabled', true, true,
                'Pipeline cupones/créditos.',
                ['Automation Center', 'Ecommerce Intelligence'],
                'Desactivado: no hay credit_*/promo_*.',
                ['Verificar flag antes de pruebas de cupones.']),
            $this->def('ac.coupons_expiring_enabled', 'Coupons Expiring Enabled', 'Feature Flags', $ac.'coupons_expiring_enabled', false, true,
                'Scheduler cupones por vencer.',
                ['Automation Center', 'Alerts Center'],
                'Drift de scheduler entre ambientes.',
                ['Documentar si Prod debe tenerlo ON.']),
            $this->def('ac.tag_abandoned_carts_enabled', 'Abandoned Carts Tag Enabled', 'Feature Flags', $ac.'tag_abandoned_carts_enabled', false, true,
                'Tag de carritos abandonados.',
                ['Automation Center', 'Funnels Intelligence'],
                'Afecta recuperación de carritos.',
                ['Validar schedule en ambos ambientes.']),

            // Automation
            $this->def('ac.coupons_expiring_days', 'Coupons Expiring Days', 'Automation', $ac.'coupons_expiring_days', false, false,
                'Ventana de días para expiring.',
                ['Automation Center'],
                'Umbral distinto cambia volumen de tags.',
                ['Alinear ventana QA/Prod.']),
            $this->def('ac.cart_abandoned_minutes', 'Cart Abandoned Minutes', 'Automation', $ac.'cart_abandoned_minutes', false, false,
                'Minutos de inactividad de carrito.',
                ['Automation Center', 'Funnels Intelligence'],
                'Umbral distinto cambia etiquetado.',
                ['Documentar valor canónico.']),
            $this->def('ac.list_new_users', 'List New Users', 'Automation', $ac.'list_new_users', false, false,
                'Lista AC usuarios nuevos.',
                ['CRM', 'Automation Center'],
                'Lista incorrecta desvía contactos.',
                ['Confirmar ID de lista por ambiente.']),

            // Analytics
            $this->def('app.timezone', 'App Timezone', 'Analytics', 'app.timezone', false, false,
                'Timezone Laravel.',
                ['Analytics', 'Dashboard'],
                'Desalineación de series temporales.',
                ['Preferir America/Monterrey en MI.']),

            // Health (signals from current dashboard + remote instrumentacion)
            $this->signal(
                'health.integration',
                'Health · Estado integración',
                'Health',
                true,
                (string) ($health->get('integration')['value'] ?? ''),
                ['Health Center', 'Integrations Hub'],
                'Drift de salud operativa entre ambientes.',
                ['Revisar Health Center en cada ambiente.', 'Instrumentar export de scorecard remoto.']
            ),
            $this->signal(
                'health.backlog',
                'Health · Backlog dispatches',
                'Health',
                true,
                (string) ($health->get('backlog')['value'] ?? ''),
                ['Health Center', 'Logs Center', 'Alerts Center'],
                'Backlog alto indica cola degradada.',
                ['Comparar backlog QA vs Prod con telemetría.', 'Requiere instrumentación remota.']
            ),
            $this->signal(
                'health.errors',
                'Health · Errores periodo',
                'Health',
                false,
                (string) ($health->get('errors')['value'] ?? ''),
                ['Health Center', 'Alerts Center'],
                'Volumen de errores distinto entre ambientes.',
                ['Usar Alerts/Logs Center para investigación.']
            ),

            // Jobs
            $this->def('ac.coupons_expiring_enabled.jobs', 'Job · Sync expiring coupons flag', 'Jobs', $ac.'coupons_expiring_enabled', false, true,
                'Flag que habilita el job/comando de cupones por vencer.',
                ['Automation Center'],
                'Job puede estar activo solo en un ambiente.',
                ['Verificar schedule:list en ambos ambientes.', 'Remoto requiere instrumentación.']),
            $this->def('ac.tag_abandoned_carts_enabled.jobs', 'Job · Tag abandoned carts flag', 'Jobs', $ac.'tag_abandoned_carts_enabled', false, true,
                'Flag del comando tag-abandoned-carts.',
                ['Automation Center'],
                'Scheduler desalineado genera drifts de tags.',
                ['Comparar Kernel/schedule entre ambientes.']),
            $this->upcoming('jobs.horizon', 'Jobs · Horizon / workers', 'Jobs',
                'Estado de workers/Horizon no expuesto en MI.',
                ['Automation Center', 'Health Center'],
                'Sin visibilidad de procesos remotos.',
                ['Exponer heartbeat de queue workers.', 'Próximamente vía Integrations/Health.']),

            // Queues
            $this->def('queue.default', 'Queue connection default', 'Queues', 'queue.default', false, false,
                'Conexión de cola por defecto.',
                ['Health Center', 'Logs Center'],
                'Cola distinta cambia latencia de sync.',
                ['Documentar driver por ambiente.']),
            $this->upcoming('queues.failed', 'Queues · Failed jobs count', 'Queues',
                'Conteo de failed jobs no instrumentado en MI.',
                ['Logs Center', 'Alerts Center'],
                'Sin comparativo de fallos de cola.',
                ['Requiere instrumentación (Horizon/metrics).']),
            $this->upcoming('queues.depth', 'Queues · Depth / lag', 'Queues',
                'Profundidad de cola remota no disponible.',
                ['Health Center'],
                'No se puede detectar lag cross-env hoy.',
                ['Instrumentar métricas de cola.', 'Próximamente.']),
        ];
    }

    /**
     * @param  list<string>  $modules
     * @param  list<string>  $recommendations
     * @return array<string, mixed>
     */
    private function def(
        string $id,
        string $name,
        string $category,
        string $configKey,
        bool $critical,
        bool $isFlag,
        string $description,
        array $modules,
        string $impact,
        array $recommendations,
    ): array {
        return [
            'kind' => 'config',
            'id' => 'cmp-'.$id,
            'name' => $name,
            'category' => $category,
            'config_key' => $configKey,
            'critical' => $critical,
            'is_flag' => $isFlag,
            'description' => $description,
            'affected_modules' => $modules,
            'impact' => $impact,
            'recommendations' => $recommendations,
        ];
    }

    /**
     * @param  list<string>  $modules
     * @param  list<string>  $recommendations
     * @return array<string, mixed>
     */
    private function signal(
        string $id,
        string $name,
        string $category,
        bool $critical,
        string $currentValue,
        array $modules,
        string $impact,
        array $recommendations,
    ): array {
        return [
            'kind' => 'signal',
            'id' => 'cmp-'.$id,
            'name' => $name,
            'category' => $category,
            'config_key' => null,
            'critical' => $critical,
            'is_flag' => false,
            'signal_value' => $currentValue,
            'description' => 'Señal operativa del Health/Dashboard en el ambiente actual.',
            'affected_modules' => $modules,
            'impact' => $impact,
            'recommendations' => $recommendations,
        ];
    }

    /**
     * @param  list<string>  $modules
     * @param  list<string>  $recommendations
     * @return array<string, mixed>
     */
    private function upcoming(
        string $id,
        string $name,
        string $category,
        string $description,
        array $modules,
        string $impact,
        array $recommendations,
    ): array {
        return [
            'kind' => 'upcoming',
            'id' => 'cmp-'.$id,
            'name' => $name,
            'category' => $category,
            'config_key' => null,
            'critical' => false,
            'is_flag' => false,
            'description' => $description,
            'affected_modules' => $modules,
            'impact' => $impact,
            'recommendations' => $recommendations,
        ];
    }

    /**
     * @param  array<string, mixed>  $def
     * @return array<string, mixed>
     */
    private function hydrate(array $def, bool $currentIsQa, bool $currentIsProd, string $env): array
    {
        if (($def['kind'] ?? '') === 'upcoming') {
            return $this->finalize(
                def: $def,
                qaValue: 'No disponible',
                qaTruth: 'proximamente',
                qaNote: 'Capacidad aún no instrumentada.',
                prodValue: 'No disponible',
                prodTruth: 'proximamente',
                prodNote: 'Capacidad aún no instrumentada.',
                compareStatus: 'pending',
                currentPresence: null,
                currentFlag: null,
            );
        }

        if (($def['kind'] ?? '') === 'signal') {
            $present = trim((string) ($def['signal_value'] ?? '')) !== ''
                && trim((string) $def['signal_value']) !== 'No disponible';
            $display = $present ? 'Presente' : 'Ausente';
            // For signals we still only show Presente/Ausente (not the numeric value) per rules
            $fingerprint = $present ? 'present' : 'absent';

            return $this->sidesFromCurrent(
                def: $def,
                currentIsQa: $currentIsQa,
                currentIsProd: $currentIsProd,
                env: $env,
                fingerprint: $fingerprint,
                display: $display,
                isFlag: false,
                flagValue: null,
                currentPresence: $present,
            );
        }

        $raw = config($def['config_key']);
        $isFlag = (bool) ($def['is_flag'] ?? false);

        if ($isFlag || is_bool($raw)) {
            $flag = (bool) $raw;
            $display = $flag ? 'true' : 'false';
            $fingerprint = $flag ? 'true' : 'false';

            return $this->sidesFromCurrent(
                def: $def,
                currentIsQa: $currentIsQa,
                currentIsProd: $currentIsProd,
                env: $env,
                fingerprint: $fingerprint,
                display: $display,
                isFlag: true,
                flagValue: $flag,
                currentPresence: true,
            );
        }

        $present = $this->isFilled($raw);
        $display = $present ? 'Presente' : 'Ausente';
        $fingerprint = $present ? 'present' : 'absent';

        return $this->sidesFromCurrent(
            def: $def,
            currentIsQa: $currentIsQa,
            currentIsProd: $currentIsProd,
            env: $env,
            fingerprint: $fingerprint,
            display: $display,
            isFlag: false,
            flagValue: null,
            currentPresence: $present,
        );
    }

    /**
     * @param  array<string, mixed>  $def
     * @return array<string, mixed>
     */
    private function sidesFromCurrent(
        array $def,
        bool $currentIsQa,
        bool $currentIsProd,
        string $env,
        string $fingerprint,
        string $display,
        bool $isFlag,
        ?bool $flagValue,
        ?bool $currentPresence,
    ): array {
        $remoteValue = 'No disponible';
        $remoteTruth = 'instrumentacion';
        $remoteNote = 'Comparación remota no instrumentada (sin snapshot de servidor).';

        if ($currentIsQa && ! $currentIsProd) {
            $qaValue = $display;
            $qaTruth = 'disponible';
            $qaNote = 'Leído del ambiente actual vía config()/señales.';
            $qaFp = $fingerprint;
            $prodValue = $remoteValue;
            $prodTruth = $remoteTruth;
            $prodNote = $remoteNote;
            $prodFp = null;
        } elseif ($currentIsProd) {
            $prodValue = $display;
            $prodTruth = 'disponible';
            $prodNote = 'Leído del ambiente actual vía config()/señales.';
            $prodFp = $fingerprint;
            $qaValue = $remoteValue;
            $qaTruth = $remoteTruth;
            $qaNote = $remoteNote;
            $qaFp = null;
        } else {
            // Ambientes atípicos: tratar como lado actual en columna QA
            $qaValue = $display;
            $qaTruth = 'disponible';
            $qaNote = 'Leído del ambiente actual ('.$env.') vía config()/señales.';
            $qaFp = $fingerprint;
            $prodValue = $remoteValue;
            $prodTruth = $remoteTruth;
            $prodNote = $remoteNote;
            $prodFp = null;
        }

        if ($qaFp !== null && $prodFp !== null) {
            $status = $qaFp === $prodFp ? 'equal' : 'different';
        } else {
            $status = 'pending';
        }

        return $this->finalize(
            def: $def,
            qaValue: $qaValue,
            qaTruth: $qaTruth,
            qaNote: $qaNote,
            prodValue: $prodValue,
            prodTruth: $prodTruth,
            prodNote: $prodNote,
            compareStatus: $status,
            currentPresence: $currentPresence,
            currentFlag: $flagValue,
        );
    }

    /**
     * @param  array<string, mixed>  $def
     * @return array<string, mixed>
     */
    private function finalize(
        array $def,
        string $qaValue,
        string $qaTruth,
        string $qaNote,
        string $prodValue,
        string $prodTruth,
        string $prodNote,
        string $compareStatus,
        ?bool $currentPresence,
        ?bool $currentFlag,
    ): array {
        return [
            'id' => $def['id'],
            'name' => $def['name'],
            'category' => $def['category'],
            'config_key' => $def['config_key'] ?? null,
            'critical' => (bool) ($def['critical'] ?? false),
            'criticality' => ($def['critical'] ?? false) ? 'critical' : 'normal',
            'is_flag' => (bool) ($def['is_flag'] ?? false),
            'description' => $def['description'] ?? '',
            'qa_value' => $qaValue,
            'qa_truth' => $qaTruth,
            'qa_note' => $qaNote,
            'prod_value' => $prodValue,
            'prod_truth' => $prodTruth,
            'prod_note' => $prodNote,
            'compare_status' => $compareStatus,
            'compare_status_label' => $this->statusLabel($compareStatus),
            'impact' => $def['impact'] ?? '',
            'affected_modules' => $def['affected_modules'] ?? [],
            'recommendations' => $def['recommendations'] ?? [],
            'current_presence' => $currentPresence,
            'current_flag' => $currentFlag,
            'quick_links' => $this->defaultLinks(),
            'truth' => $compareStatus === 'pending' ? 'instrumentacion' : 'proxy',
        ];
    }

    private function statusLabel(string $status): string
    {
        return match ($status) {
            'equal' => 'Iguales',
            'different' => 'Distintas',
            'pending' => 'Pendiente',
            default => ucfirst($status),
        };
    }

    private function isFilled(mixed $raw): bool
    {
        if ($raw === null) {
            return false;
        }
        if (is_bool($raw)) {
            return true;
        }
        if (is_array($raw)) {
            return $raw !== [];
        }
        if (is_string($raw)) {
            return trim($raw) !== '';
        }

        return true;
    }

    /**
     * @param  \Illuminate\Support\Collection<int, array<string, mixed>>  $items
     * @param  array<string, mixed>  $filters
     * @return \Illuminate\Support\Collection<int, array<string, mixed>>
     */
    private function applyFilters($items, array $filters)
    {
        return $items->filter(function (array $r) use ($filters) {
            if ($filters['category'] !== '' && ($r['category'] ?? '') !== $filters['category']) {
                return false;
            }
            if ($filters['status'] !== '' && ($r['compare_status'] ?? '') !== $filters['status']) {
                return false;
            }
            if ($filters['criticality'] !== '' && ($r['criticality'] ?? '') !== $filters['criticality']) {
                return false;
            }
            if ($filters['environment'] === 'qa' && ($r['qa_truth'] ?? '') !== 'disponible') {
                return false;
            }
            if ($filters['environment'] === 'production' && ($r['prod_truth'] ?? '') !== 'disponible') {
                return false;
            }

            return true;
        });
    }

    /**
     * @param  array<string, mixed>  $r
     * @return array<string, mixed>
     */
    private function listItem(array $r): array
    {
        return [
            'id' => $r['id'],
            'name' => $r['name'],
            'category' => $r['category'],
            'qa_value' => $r['qa_value'],
            'qa_truth' => $r['qa_truth'],
            'prod_value' => $r['prod_value'],
            'prod_truth' => $r['prod_truth'],
            'compare_status' => $r['compare_status'],
            'compare_status_label' => $r['compare_status_label'],
            'critical' => $r['critical'],
            'criticality' => $r['criticality'],
            'truth' => $r['truth'],
        ];
    }

    /**
     * @return list<array{label: string, href: string}>
     */
    private function defaultLinks(): array
    {
        return [
            ['label' => 'Configuration Center', 'href' => route('admin.activecampaign.settings')],
            ['label' => 'Health Center', 'href' => route('admin.activecampaign.health')],
            ['label' => 'Integrations Hub', 'href' => route('admin.activecampaign.integrations')],
            ['label' => 'Automation Center', 'href' => route('admin.activecampaign.automations')],
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function quickActions(): array
    {
        return [
            ['id' => 'settings', 'label' => 'Configuration Center', 'href' => route('admin.activecampaign.settings'), 'enabled' => true],
            ['id' => 'health', 'label' => 'Health Center', 'href' => route('admin.activecampaign.health'), 'enabled' => true],
            ['id' => 'integrations', 'label' => 'Integrations Hub', 'href' => route('admin.activecampaign.integrations'), 'enabled' => true],
            ['id' => 'automations', 'label' => 'Automation Center', 'href' => route('admin.activecampaign.automations'), 'enabled' => true],
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function gaps(): array
    {
        return [
            [
                'label' => 'Snapshot remoto QA↔Prod',
                'reason' => 'No hay agente ni export seguro entre ambientes; el lado opuesto queda sin señal.',
                'truth' => 'instrumentacion',
            ],
            [
                'label' => 'Comparación de servidores / deploy',
                'reason' => 'Fuera de alcance: esta consola no inspecciona hosts ni pipelines.',
                'truth' => 'proximamente',
            ],
            [
                'label' => 'Jobs / Queues en vivo',
                'reason' => 'Horizon, failed jobs y lag de cola no estánen aún a MI.',
                'truth' => 'instrumentacion',
            ],
        ];
    }
}
