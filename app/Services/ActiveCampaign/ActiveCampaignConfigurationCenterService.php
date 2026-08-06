<?php

namespace App\Services\ActiveCampaign;

use App\Support\ActiveCampaign\ActiveCampaignDashboardFilter;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;

/**
 * Configuration Center — consola de visualización/análisis de configuración (capa de composición).
 * No edita .env ni archivos de config. Sanitiza secretos. Consolida config() + señales Health/Integrations/Automation.
 */
class ActiveCampaignConfigurationCenterService
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
            Cache::forget($this->cacheKey('raw-config'));
            Cache::forget($this->cacheKey('executive'));
        }

        $overview = $this->dashboard->buildOverview($filter);
        $rawKey = $this->cacheKey('raw-config');

        $rawItems = Cache::remember($rawKey, now()->addMinutes(5), function () {
            return $this->collectConfigs()->all();
        });

        $items = collect($rawItems);
        $filtered = $this->applyFilters($items, $filters);

        return [
            'filters' => $filters,
            'filterOptions' => $this->filterOptions(),
            'summary' => $this->buildSummary($items),
            'configs' => $filtered->map(fn (array $c) => $this->listItem($c))->values()->all(),
            'actions' => $this->quickActions(),
            'meta' => [
                ...($overview['meta'] ?? []),
                'purpose' => 'Visualizar, analizar y gobernar la configuración de Marketing Intelligence — sin editar .env.',
                'source_of_truth' => 'config() · services.php · feature flags · mapas Integrations/Health/Automation/Analytics',
                'total' => $filtered->count(),
                'note' => 'Solo lectura. Tokens y secretos siempre sanitizados. Cambios vía release/.env.',
                'environment' => app()->environment(),
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function buildExecutive(Request $request): array
    {
        $rawKey = $this->cacheKey('raw-config');
        $items = collect(Cache::remember($rawKey, now()->addMinutes(5), function () {
            return $this->collectConfigs()->all();
        }));

        $byCategory = $items->groupBy('category')->map(fn ($g, $k) => [
            'label' => (string) $k,
            'value' => $g->count(),
        ])->sortByDesc('value')->values()->all();

        $featureFlags = $items->where('category', 'Feature Flags')->map(fn (array $c) => [
            'label' => Str::limit((string) $c['name'], 28),
            'value' => ($c['raw_bool'] ?? false) ? 1 : 0,
        ])->values()->all();

        $integrations = $items->where('category', 'Integraciones')->map(fn (array $c) => [
            'label' => Str::limit((string) $c['name'], 28),
            'value' => ($c['status'] ?? '') === 'ok' ? 1 : 0,
        ])->values()->all();

        $critical = $items->where('critical', true)->map(fn (array $c) => [
            'label' => Str::limit((string) $c['name'], 28),
            'value' => ($c['status'] ?? '') === 'ok' ? 1 : 0,
        ])->values()->all();

        return [
            'by_category' => $byCategory,
            'feature_flags' => $featureFlags,
            'integrations' => $integrations,
            'critical' => $critical,
            'gaps' => $this->gaps(),
            'truth' => 'disponible',
            'note' => 'Agregados del inventario de configuración en memoria (cache 5 min). Sin escritura.',
        ];
    }

    /**
     * @return array<string, mixed>|null
     */
    public function buildDetail(string $configId, Request $request): ?array
    {
        if ($configId === '') {
            return null;
        }

        $rawKey = $this->cacheKey('raw-config');
        $items = collect(Cache::remember($rawKey, now()->addMinutes(5), function () {
            return $this->collectConfigs()->all();
        }));

        $item = $items->firstWhere('id', $configId);
        if (! $item) {
            return null;
        }

        return [
            ...$item,
            'description_full' => $item['description'],
            'value_display' => $item['value'],
            'dependencies' => $item['dependencies'] ?? [],
            'related_modules' => $item['related_modules'] ?? [],
            'impact' => $item['impact'] ?? 'Impacto no documentado.',
            'documentation' => $item['documentation'] ?? 'Documentado en config/services.php y runbooks MI.',
            'quick_links' => $item['quick_links'] ?? $this->defaultLinks(),
            'truth' => $item['truth'] ?? 'disponible',
        ];
    }

    private function cacheKey(string $suffix): string
    {
        return 'mi-config:v1:'.sha1(app()->environment().'|'.$suffix);
    }

    /**
     * @return array<string, mixed>
     */
    private function resolveFilters(Request $request): array
    {
        return [
            'category' => trim((string) $request->input('category', '')),
            'environment' => trim((string) $request->input('environment', '')),
            'status' => trim((string) $request->input('status', '')),
            'origin' => trim((string) $request->input('origin', '')),
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
                ['value' => 'Integraciones', 'label' => 'Integraciones'],
                ['value' => 'Feature Flags', 'label' => 'Feature Flags'],
                ['value' => 'Automation', 'label' => 'Automation'],
                ['value' => 'Tags', 'label' => 'Tags'],
                ['value' => 'Custom Fields', 'label' => 'Custom Fields'],
                ['value' => 'Analytics', 'label' => 'Analytics'],
                ['value' => 'Plataforma', 'label' => 'Plataforma'],
            ],
            'environments' => collect([
                ['value' => '', 'label' => 'Todos'],
                ['value' => 'local', 'label' => 'local'],
                ['value' => 'staging', 'label' => 'staging'],
                ['value' => 'production', 'label' => 'production'],
                ['value' => 'testing', 'label' => 'testing'],
                ['value' => app()->environment(), 'label' => app()->environment().' (actual)'],
            ])->unique('value')->values()->all(),
            'statuses' => [
                ['value' => '', 'label' => 'Todos'],
                ['value' => 'ok', 'label' => 'OK'],
                ['value' => 'pending', 'label' => 'Pendiente'],
                ['value' => 'critical', 'label' => 'Crítico'],
                ['value' => 'disabled', 'label' => 'Desactivado'],
            ],
            'origins' => [
                ['value' => '', 'label' => 'Todos'],
                ['value' => 'config/services.php', 'label' => 'config/services.php'],
                ['value' => 'config/app.php', 'label' => 'config/app.php'],
                ['value' => 'config/mail.php', 'label' => 'config/mail.php'],
                ['value' => 'runtime', 'label' => 'runtime'],
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
        $critical = $col->where('critical', true);
        $flags = $col->where('category', 'Feature Flags');
        $integrations = $col->where('category', 'Integraciones');
        $pending = $col->whereIn('status', ['pending', 'critical']);
        $okRatio = $col->count() > 0
            ? (int) round(100 * $col->where('status', 'ok')->count() / $col->count())
            : 0;

        $generalTone = $pending->where('status', 'critical')->isNotEmpty()
            ? 'red'
            : ($pending->isNotEmpty() ? 'amber' : 'default');

        return [
            [
                'id' => 'total',
                'label' => 'Configuraciones totales',
                'value' => (string) $col->count(),
                'tone' => 'sky',
                'hint' => 'Inventario gobernado de MI',
                'truth' => 'disponible',
            ],
            [
                'id' => 'critical',
                'label' => 'Configuraciones críticas',
                'value' => (string) $critical->count(),
                'tone' => 'amber',
                'hint' => 'Requieren atención operativa',
                'truth' => 'disponible',
            ],
            [
                'id' => 'flags',
                'label' => 'Feature Flags',
                'value' => (string) $flags->count(),
                'tone' => 'sky',
                'hint' => 'Toggles booleanos de AC/MI',
                'truth' => 'disponible',
            ],
            [
                'id' => 'integrations',
                'label' => 'Integraciones configuradas',
                'value' => (string) $integrations->where('status', 'ok')->count(),
                'tone' => 'default',
                'hint' => 'Credenciales presentes + estado OK',
                'truth' => 'disponible',
            ],
            [
                'id' => 'pending',
                'label' => 'Variables pendientes',
                'value' => (string) $pending->count(),
                'tone' => 'amber',
                'hint' => 'Vacías, parciales o críticas',
                'truth' => 'disponible',
            ],
            [
                'id' => 'general',
                'label' => 'Estado general',
                'value' => $okRatio.'%',
                'tone' => $generalTone,
                'hint' => '% de configs en estado OK',
                'truth' => 'proxy',
            ],
        ];
    }

    /**
     * @return \Illuminate\Support\Collection<int, array<string, mixed>>
     */
    private function collectConfigs()
    {
        $env = app()->environment();
        $mtime = $this->servicesMtime();
        $items = collect();

        foreach ($this->registry() as $def) {
            $items->push($this->hydrate($def, $env, $mtime));
        }

        return $items->sortBy(fn (array $c) => [$c['category'], mb_strtolower($c['name'])])->values();
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function registry(): array
    {
        $ac = 'services.activecampaign.';

        return [
            // Integraciones
            $this->def('ac.endpoint', 'ActiveCampaign API Endpoint', 'Integraciones', $ac.'endpoint', true, false,
                'URL base de la API ActiveCampaign.',
                ['services.activecampaign.token', 'services.activecampaign.enabled'],
                ['Integrations Hub', 'Health Center', 'Event Center'],
                'Sin endpoint la integración no puede operar.',
                'config/services.php → activecampaign.endpoint'),
            $this->def('ac.token', 'ActiveCampaign API Token', 'Integraciones', $ac.'token', true, true,
                'Token de autenticación de ActiveCampaign.',
                ['services.activecampaign.endpoint'],
                ['Integrations Hub', 'Health Center'],
                'Credencial crítica; siempre sanitizada en UI.',
                'config/services.php → activecampaign.token'),
            $this->def('ac.account_id', 'ActiveCampaign Account ID', 'Integraciones', $ac.'account_id', false, false,
                'Identificador de cuenta AC (event tracking).',
                ['services.activecampaign.event_key'],
                ['Event Center', 'Analytics'],
                'Usado en tracking de eventos de sitio.',
                'config/services.php → activecampaign.account_id'),
            $this->def('ac.event_key', 'ActiveCampaign Event Key', 'Integraciones', $ac.'event_key', false, true,
                'Clave de eventos de sitio ActiveCampaign.',
                ['services.activecampaign.account_id'],
                ['Event Center', 'Analytics'],
                'Secreto de tracking; sanitizado.',
                'config/services.php → activecampaign.event_key'),
            $this->def('mail.default', 'Mailer por defecto', 'Integraciones', 'mail.default', false, false,
                'Canal de correo de la aplicación (proxy Mailgun/SMTP).',
                ['mail.mailers'],
                ['Notification Center', 'Integrations Hub'],
                'Afecta notificaciones operativas.',
                'config/mail.php'),

            // Feature flags
            $this->def('ac.enabled', 'ActiveCampaign Enabled', 'Feature Flags', $ac.'enabled', true, false,
                'Master switch de la integración ActiveCampaign.',
                ['services.activecampaign.endpoint', 'services.activecampaign.token'],
                ['Health Center', 'Integrations Hub', 'Automation Center'],
                'Desactivado: detiene sync y jobs AC.',
                'ACTIVE_CAMPAIGN_ENABLED', true),
            $this->def('ac.coupons_enabled', 'Coupons Enabled', 'Feature Flags', $ac.'coupons_enabled', true, false,
                'Habilita pipeline de cupones/créditos/promos.',
                ['services.activecampaign.enabled'],
                ['Automation Center', 'Ecommerce Intelligence', 'Analytics'],
                'Desactivado: no despacha credit_*/promo_*.',
                'ACTIVE_CAMPAIGN_COUPONS_ENABLED', true),
            $this->def('ac.coupons_expiring_enabled', 'Coupons Expiring Enabled', 'Feature Flags', $ac.'coupons_expiring_enabled', false, false,
                'Scheduler de cupones por vencer.',
                ['services.activecampaign.coupons_enabled', 'services.activecampaign.coupons_expiring_days'],
                ['Automation Center', 'Alerts Center'],
                'Flag off: comando expiring pausado.',
                'ACTIVE_CAMPAIGN_COUPONS_EXPIRING_ENABLED', true),
            $this->def('ac.tag_abandoned_carts_enabled', 'Tag Abandoned Carts Enabled', 'Feature Flags', $ac.'tag_abandoned_carts_enabled', false, false,
                'Scheduler de etiquetado de carritos abandonados.',
                ['services.activecampaign.cart_abandoned_minutes'],
                ['Automation Center', 'Ecommerce Intelligence', 'Funnels Intelligence'],
                'Flag off: no etiqueta abandonos.',
                'ACTIVECAMPAIGN_TAG_ABANDONED_CARTS_ENABLED', true),

            // Automation
            $this->def('ac.coupons_expiring_days', 'Coupons Expiring Days', 'Automation', $ac.'coupons_expiring_days', false, false,
                'Ventana en días para detectar cupones por vencer.',
                ['services.activecampaign.coupons_expiring_enabled'],
                ['Automation Center'],
                'Cambia el alcance del scheduler diario.',
                'ACTIVE_CAMPAIGN_COUPONS_EXPIRING_DAYS'),
            $this->def('ac.cart_abandoned_minutes', 'Cart Abandoned Minutes', 'Automation', $ac.'cart_abandoned_minutes', false, false,
                'Minutos de inactividad para considerar carrito abandonado.',
                ['services.activecampaign.tag_abandoned_carts_enabled'],
                ['Automation Center', 'Funnels Intelligence'],
                'Umbral del comando tag-abandoned-carts.',
                'ACTIVE_CAMPAIGN_CART_ABANDONED_MINUTES'),
            $this->def('ac.list_new_users', 'List New Users', 'Automation', $ac.'list_new_users', false, false,
                'Lista AC para usuarios nuevos.',
                ['services.activecampaign.enabled'],
                ['CRM', 'Automation Center'],
                'Afecta suscripción de contactos nuevos.',
                'ACTIVE_CAMPAIGN_LIST_NEW_USERS'),

            // Tags (sample of critical ones + summary)
            $this->def('ac.tag_registro_nuevo', 'Tag Registro Nuevo (ID)', 'Tags', $ac.'tag_registro_nuevo', false, false,
                'ID legacy del tag de onboarding.',
                [],
                ['Tags Manager', 'CRM'],
                'Usado en sync de contacto nuevo.',
                'ACTIVE_CAMPAIGN_TAG_REGISTRO_NUEVO'),
            $this->def('ac.tag_pharmacy', 'Tag Pharmacy Purchase Completed', 'Tags', $ac.'tag_pharmacy_purchase_completed', false, false,
                'ID tag compra farmacia.',
                [],
                ['Tags Manager', 'Ecommerce Intelligence'],
                'Disparado en compra farmacia.',
                'ACTIVE_CAMPAIGN_TAG_PHARMACY_PURCHASE_COMPLETED'),
            $this->def('ac.tag_lab_purchase', 'Tag Laboratory Purchase Completed', 'Tags', $ac.'tag_laboratory_purchase_completed', false, false,
                'ID tag compra laboratorio.',
                [],
                ['Tags Manager', 'Laboratory Intelligence'],
                'Disparado en compra lab.',
                'ACTIVE_CAMPAIGN_TAG_LABORATORY_PURCHASE_COMPLETED'),
            $this->def('ac.tag_lab_sample', 'Tag Lab Sample Collected', 'Tags', $ac.'tag_lab_sample_collected', false, false,
                'ID tag toma de muestra.',
                [],
                ['Tags Manager', 'Laboratory Intelligence'],
                'Job de notificación de muestra.',
                'ACTIVE_CAMPAIGN_TAG_LAB_SAMPLE_COLLECTED'),
            $this->def('ac.tag_lab_results', 'Tag Lab Results Available', 'Tags', $ac.'tag_lab_results_available', false, false,
                'ID tag resultados disponibles.',
                [],
                ['Tags Manager', 'Laboratory Intelligence'],
                'Job de resultados lab.',
                'ACTIVE_CAMPAIGN_TAG_LAB_RESULTS_AVAILABLE'),
            $this->def('ac.tags.credit.available', 'Tag FM-Credito-Disponible', 'Tags', $ac.'tags.credit.available', true, false,
                'Nombre del tag de crédito disponible.',
                ['services.activecampaign.coupons_enabled'],
                ['Tags Manager', 'Automation Center'],
                'Crítico para ciclo de créditos.',
                'ACTIVE_CAMPAIGN_TAG_CREDIT_AVAILABLE'),
            $this->def('ac.tags.promo.validated', 'Tag FM-Promo-Validada', 'Tags', $ac.'tags.promo.validated', false, false,
                'Nombre del tag de promo validada.',
                ['services.activecampaign.coupons_enabled'],
                ['Tags Manager', 'Ecommerce Intelligence'],
                'Ciclo de promociones.',
                'ACTIVE_CAMPAIGN_TAG_PROMO_VALIDATED'),

            // Custom fields (sample)
            $this->def('ac.fields.fm_user_id', 'Field FM_USER_ID', 'Custom Fields', $ac.'fields.fm_user_id', true, false,
                'ID del custom field de identidad de usuario.',
                ['services.activecampaign.fields.fm_customer_id'],
                ['Custom Fields Manager', 'CRM'],
                'Obligatorio para sync de cupones.',
                'ACTIVECAMPAIGN_FIELD_FM_USER_ID'),
            $this->def('ac.fields.fm_customer_id', 'Field FM_CUSTOMER_ID', 'Custom Fields', $ac.'fields.fm_customer_id', true, false,
                'ID del custom field de customer.',
                ['services.activecampaign.fields.fm_user_id'],
                ['Custom Fields Manager', 'CRM'],
                'Obligatorio para sync de cupones.',
                'ACTIVECAMPAIGN_FIELD_FM_CUSTOMER_ID'),
            $this->def('ac.fields.fm_credito_estado', 'Field FM_CREDITO_ESTADO', 'Custom Fields', $ac.'fields.fm_credito_estado', false, false,
                'Estado del crédito en AC.',
                [],
                ['Custom Fields Manager', 'Membership Intelligence'],
                'Actualizado en credit_* dispatches.',
                'ACTIVECAMPAIGN_FIELD_FM_CREDITO_ESTADO'),
            $this->def('ac.fields.fm_promo_estado', 'Field FM_PROMO_ESTADO', 'Custom Fields', $ac.'fields.fm_promo_estado', false, false,
                'Estado de promoción en AC.',
                [],
                ['Custom Fields Manager', 'Ecommerce Intelligence'],
                'Actualizado en promo_* dispatches.',
                'ACTIVECAMPAIGN_FIELD_FM_PROMO_ESTADO'),

            // Analytics / platform
            $this->def('app.env', 'Application Environment', 'Plataforma', 'app.env', true, false,
                'Ambiente runtime de Laravel.',
                [],
                ['Health Center', 'QA vs Prod'],
                'Define comportamiento de debugging y comparadores.',
                'APP_ENV', false, 'config/app.php'),
            $this->def('app.debug', 'Application Debug', 'Analytics', 'app.debug', true, false,
                'Modo debug de la aplicación.',
                ['app.env'],
                ['Health Center', 'Logs Center'],
                'Crítico en producción: no debe estar activo.',
                'APP_DEBUG', true, 'config/app.php'),
            $this->def('app.url', 'Application URL', 'Plataforma', 'app.url', false, false,
                'URL base de la aplicación.',
                [],
                ['Integrations Hub', 'Notification Center'],
                'Usada en links y callbacks.',
                'APP_URL', false, 'config/app.php'),
            $this->def('app.timezone', 'Application Timezone', 'Plataforma', 'app.timezone', false, false,
                'Zona horaria de la app (además de TZ MI Monterrey en consolas).',
                [],
                ['Analytics', 'Dashboard'],
                'Afecta timestamps de Laravel.',
                'APP_TIMEZONE', false, 'config/app.php'),
        ];
    }

    /**
     * @param  list<string>  $dependencies
     * @param  list<string>  $modules
     * @return array<string, mixed>
     */
    private function def(
        string $id,
        string $name,
        string $category,
        string $configKey,
        bool $critical,
        bool $sensitive,
        string $description,
        array $dependencies,
        array $modules,
        string $impact,
        string $documentation,
        bool $isFlag = false,
        string $origin = 'config/services.php',
    ): array {
        return [
            'id' => 'cfg-'.$id,
            'name' => $name,
            'category' => $category,
            'config_key' => $configKey,
            'critical' => $critical,
            'sensitive' => $sensitive,
            'description' => $description,
            'dependencies' => $dependencies,
            'related_modules' => $modules,
            'impact' => $impact,
            'documentation' => $documentation,
            'is_flag' => $isFlag,
            'origin' => $origin,
        ];
    }

    /**
     * @param  array<string, mixed>  $def
     * @return array<string, mixed>
     */
    private function hydrate(array $def, string $env, ?string $mtime): array
    {
        $raw = config($def['config_key']);
        $sensitive = (bool) $def['sensitive'];
        $isFlag = (bool) ($def['is_flag'] ?? false);
        $critical = (bool) $def['critical'];

        $filled = $this->isFilled($raw);
        $rawBool = null;
        if ($isFlag || is_bool($raw)) {
            $rawBool = (bool) $raw;
        }

        $value = $this->formatValue($raw, $sensitive, $isFlag);
        $status = $this->resolveStatus($raw, $filled, $critical, $isFlag, $def['config_key'], $env);

        return [
            'id' => $def['id'],
            'name' => $def['name'],
            'category' => $def['category'],
            'config_key' => $def['config_key'],
            'value' => $value,
            'raw_bool' => $rawBool,
            'sensitive' => $sensitive,
            'critical' => $critical,
            'origin' => $def['origin'],
            'environment' => $env,
            'status' => $status,
            'status_label' => match ($status) {
                'ok' => 'OK',
                'pending' => 'Pendiente',
                'critical' => 'Crítico',
                'disabled' => 'Desactivado',
                default => ucfirst($status),
            },
            'last_updated' => $mtime ?? 'No disponible',
            'last_updated_truth' => $mtime ? 'proxy' : 'instrumentacion',
            'description' => $def['description'],
            'dependencies' => array_map(fn (string $d) => [
                'key' => $d,
                'filled' => $this->isFilled(config($d)),
                'truth' => 'disponible',
            ], $def['dependencies']),
            'related_modules' => $def['related_modules'],
            'impact' => $def['impact'],
            'documentation' => $def['documentation'],
            'quick_links' => $this->linksForCategory($def['category']),
            'truth' => 'disponible',
        ];
    }

    private function resolveStatus(mixed $raw, bool $filled, bool $critical, bool $isFlag, string $configKey, string $env): string
    {
        if ($isFlag || is_bool($raw)) {
            if ($configKey === 'app.debug' && $env === 'production' && (bool) $raw) {
                return 'critical';
            }
            if ($configKey === 'services.activecampaign.enabled' && ! (bool) $raw) {
                return 'disabled';
            }
            if ($configKey === 'services.activecampaign.coupons_enabled') {
                $master = (bool) config('services.activecampaign.enabled', true);
                if (! $master) {
                    return 'disabled';
                }

                return (bool) $raw ? 'ok' : 'disabled';
            }

            return (bool) $raw ? 'ok' : 'disabled';
        }

        if ($critical && ! $filled) {
            // Master enabled but missing credentials → critical
            if (str_starts_with($configKey, 'services.activecampaign.')
                && (bool) config('services.activecampaign.enabled', true)
                && in_array($configKey, ['services.activecampaign.endpoint', 'services.activecampaign.token'], true)
            ) {
                return 'critical';
            }

            return 'pending';
        }

        if (! $filled) {
            return 'pending';
        }

        return 'ok';
    }

    private function formatValue(mixed $raw, bool $sensitive, bool $isFlag): string
    {
        if ($isFlag || is_bool($raw)) {
            return ((bool) $raw) ? 'true' : 'false';
        }

        if ($raw === null || $raw === '') {
            return '— (vacío)';
        }

        if ($sensitive) {
            return $this->isFilled($raw) ? '•••••••• (configurado)' : '— (vacío)';
        }

        if (is_array($raw)) {
            return '[array · '.count($raw).' keys]';
        }

        if (is_numeric($raw)) {
            return (string) $raw;
        }

        $str = (string) $raw;
        if (mb_strlen($str) > 80) {
            return mb_substr($str, 0, 77).'…';
        }

        return $str;
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

    private function servicesMtime(): ?string
    {
        $path = config_path('services.php');
        if (! is_file($path)) {
            return null;
        }
        $ts = @filemtime($path);
        if (! $ts) {
            return null;
        }

        return \Carbon\Carbon::createFromTimestamp($ts)->timezone(self::TZ)->format('d/m/Y H:i');
    }

    /**
     * @return list<array{label: string, href: string}>
     */
    private function linksForCategory(string $category): array
    {
        $base = $this->defaultLinks();

        return match ($category) {
            'Tags' => array_merge($base, [['label' => 'Tags Manager', 'href' => route('admin.activecampaign.tags')]]),
            'Custom Fields' => array_merge($base, [['label' => 'Custom Fields Manager', 'href' => route('admin.activecampaign.fields')]]),
            'Automation' => array_merge($base, [['label' => 'Automation Center', 'href' => route('admin.activecampaign.automations')]]),
            'Feature Flags' => array_merge($base, [['label' => 'Automation Center', 'href' => route('admin.activecampaign.automations')]]),
            'Analytics' => array_merge($base, [['label' => 'Analytics', 'href' => route('admin.activecampaign.analytics')]]),
            default => $base,
        };
    }

    /**
     * @param  \Illuminate\Support\Collection<int, array<string, mixed>>  $items
     * @param  array<string, mixed>  $filters
     * @return \Illuminate\Support\Collection<int, array<string, mixed>>
     */
    private function applyFilters($items, array $filters)
    {
        return $items->filter(function (array $c) use ($filters) {
            if ($filters['category'] !== '' && ($c['category'] ?? '') !== $filters['category']) {
                return false;
            }
            if ($filters['environment'] !== '' && ($c['environment'] ?? '') !== $filters['environment']) {
                return false;
            }
            if ($filters['status'] !== '' && ($c['status'] ?? '') !== $filters['status']) {
                return false;
            }
            if ($filters['origin'] !== '' && ($c['origin'] ?? '') !== $filters['origin']) {
                return false;
            }

            return true;
        });
    }

    /**
     * @param  array<string, mixed>  $c
     * @return array<string, mixed>
     */
    private function listItem(array $c): array
    {
        return [
            'id' => $c['id'],
            'name' => $c['name'],
            'category' => $c['category'],
            'value' => $c['value'],
            'origin' => $c['origin'],
            'environment' => $c['environment'],
            'status' => $c['status'],
            'status_label' => $c['status_label'],
            'last_updated' => $c['last_updated'],
            'last_updated_truth' => $c['last_updated_truth'],
            'critical' => $c['critical'],
            'sensitive' => $c['sensitive'],
            'truth' => $c['truth'],
        ];
    }

    /**
     * @return list<array{label: string, href: string}>
     */
    private function defaultLinks(): array
    {
        return [
            ['label' => 'Health Center', 'href' => route('admin.activecampaign.health')],
            ['label' => 'Integrations Hub', 'href' => route('admin.activecampaign.integrations')],
            ['label' => 'QA vs Prod', 'href' => route('admin.activecampaign.qa-compare')],
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function quickActions(): array
    {
        return [
            ['id' => 'health', 'label' => 'Health Center', 'href' => route('admin.activecampaign.health'), 'enabled' => true],
            ['id' => 'integrations', 'label' => 'Integrations Hub', 'href' => route('admin.activecampaign.integrations'), 'enabled' => true],
            ['id' => 'automations', 'label' => 'Automation Center', 'href' => route('admin.activecampaign.automations'), 'enabled' => true],
            ['id' => 'tags', 'label' => 'Tags Manager', 'href' => route('admin.activecampaign.tags'), 'enabled' => true],
            ['id' => 'fields', 'label' => 'Custom Fields Manager', 'href' => route('admin.activecampaign.fields'), 'enabled' => true],
            ['id' => 'qa', 'label' => 'QA vs Prod', 'href' => route('admin.activecampaign.qa-compare'), 'enabled' => true],
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function gaps(): array
    {
        return [
            [
                'label' => 'Edición in-app de configuración',
                'reason' => 'Los cambios productivos se hacen vía release/.env; la UI es solo lectura en v1.',
                'truth' => 'proximamente',
            ],
            [
                'label' => 'Historial de cambios de config',
                'reason' => 'Sin auditoría versionada de claves; mtime de services.php es proxy.',
                'truth' => 'instrumentacion',
            ],
            [
                'label' => 'Pennant / feature flags distribuidos',
                'reason' => 'Hoy los toggles viven en config booleans, no en un Feature Flag service.',
                'truth' => 'proxy',
            ],
        ];
    }
}
