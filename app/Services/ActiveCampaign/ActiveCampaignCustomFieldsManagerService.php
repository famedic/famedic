<?php

namespace App\Services\ActiveCampaign;

use App\Models\ActiveCampaignDispatch;
use App\Support\ActiveCampaign\ActiveCampaignDashboardFilter;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;

/**
 * Custom Fields Manager — consola de campos personalizados (capa de composición).
 * No crea tablas/modelos: consolida ActiveCampaignService::getFields() + config + dispatches + mapas Journey/Automation/Analytics.
 */
class ActiveCampaignCustomFieldsManagerService
{
    private const TZ = 'America/Monterrey';

    /**
     * Claves config → título esperado en AC / perstag.
     *
     * @var array<string, array{title: string, family: string, required: bool, type_hint: string}>
     */
    private const CONFIG_META = [
        'fm_user_id' => ['title' => 'FM_USER_ID', 'family' => 'identity', 'required' => true, 'type_hint' => 'text'],
        'fm_customer_id' => ['title' => 'FM_CUSTOMER_ID', 'family' => 'identity', 'required' => true, 'type_hint' => 'text'],
        'fm_credito_estado' => ['title' => 'FM_CREDITO_ESTADO', 'family' => 'credit', 'required' => false, 'type_hint' => 'text'],
        'fm_credito_monto' => ['title' => 'FM_CREDITO_MONTO', 'family' => 'credit', 'required' => false, 'type_hint' => 'number'],
        'fm_credito_restante' => ['title' => 'FM_CREDITO_RESTANTE', 'family' => 'credit', 'required' => false, 'type_hint' => 'number'],
        'fm_credito_expira_at' => ['title' => 'FM_CREDITO_EXPIRA_AT', 'family' => 'credit', 'required' => false, 'type_hint' => 'date'],
        'fm_credito_compra_minima' => ['title' => 'FM_CREDITO_COMPRA_MINIMA', 'family' => 'credit', 'required' => false, 'type_hint' => 'number'],
        'fm_credito_campania' => ['title' => 'FM_CREDITO_CAMPANIA', 'family' => 'credit', 'required' => false, 'type_hint' => 'text'],
        'fm_credito_tipo' => ['title' => 'FM_CREDITO_TIPO', 'family' => 'credit', 'required' => false, 'type_hint' => 'text'],
        'fm_credito_ultimo_uso_at' => ['title' => 'FM_CREDITO_ULTIMO_USO_AT', 'family' => 'credit', 'required' => false, 'type_hint' => 'datetime'],
        'fm_saldo_total' => ['title' => 'FM_SALDO_TOTAL', 'family' => 'balance', 'required' => false, 'type_hint' => 'number'],
        'fm_saldo_aplicable' => ['title' => 'FM_SALDO_APLICABLE', 'family' => 'balance', 'required' => false, 'type_hint' => 'number'],
        'fm_saldo_condicionado' => ['title' => 'FM_SALDO_CONDICIONADO', 'family' => 'balance', 'required' => false, 'type_hint' => 'number'],
        'fm_promo_ultimo_codigo' => ['title' => 'FM_PROMO_ULTIMO_CODIGO', 'family' => 'promo', 'required' => false, 'type_hint' => 'text'],
        'fm_promo_estado' => ['title' => 'FM_PROMO_ESTADO', 'family' => 'promo', 'required' => false, 'type_hint' => 'text'],
        'fm_ultima_compra_lab_at' => ['title' => 'FM_ULTIMA_COMPRA_LAB_AT', 'family' => 'lab', 'required' => false, 'type_hint' => 'datetime'],
    ];

    /**
     * event_type → claves de campo tocadas (proxy de uso).
     *
     * @var array<string, list<string>>
     */
    private const EVENT_FIELDS = [
        'credit_assigned' => ['fm_user_id', 'fm_customer_id', 'fm_credito_estado', 'fm_credito_monto', 'fm_credito_restante', 'fm_credito_expira_at', 'fm_credito_compra_minima', 'fm_credito_campania', 'fm_credito_tipo'],
        'credit_redeemed' => ['fm_user_id', 'fm_customer_id', 'fm_credito_estado', 'fm_credito_restante', 'fm_credito_ultimo_uso_at', 'fm_saldo_total', 'fm_saldo_aplicable', 'fm_saldo_condicionado'],
        'credit_restored' => ['fm_user_id', 'fm_customer_id', 'fm_credito_estado', 'fm_credito_restante', 'fm_saldo_total', 'fm_saldo_aplicable'],
        'credit_revoked' => ['fm_credito_estado', 'fm_credito_campania'],
        'credit_expiring' => ['fm_credito_estado', 'fm_credito_expira_at', 'fm_credito_restante'],
        'promo_validated' => ['fm_user_id', 'fm_customer_id', 'fm_promo_ultimo_codigo', 'fm_promo_estado', 'fm_credito_compra_minima', 'fm_credito_expira_at'],
        'promo_used' => ['fm_user_id', 'fm_customer_id', 'fm_promo_ultimo_codigo', 'fm_promo_estado', 'fm_ultima_compra_lab_at'],
        'promo_released' => ['fm_user_id', 'fm_customer_id', 'fm_promo_ultimo_codigo', 'fm_promo_estado'],
        'pending_beneficiary_created' => ['fm_credito_estado', 'fm_credito_monto', 'fm_credito_expira_at', 'fm_credito_compra_minima', 'fm_credito_campania', 'fm_credito_tipo'],
        'pending_beneficiary_registered' => ['fm_user_id', 'fm_customer_id', 'fm_credito_estado', 'fm_credito_monto', 'fm_credito_restante', 'fm_saldo_total', 'fm_saldo_aplicable', 'fm_saldo_condicionado'],
    ];

    /**
     * @var array<string, string>
     */
    private const JOURNEY_BY_FAMILY = [
        'identity' => 'Journey identidad / CRM',
        'credit' => 'Journey créditos / cupones',
        'balance' => 'Journey saldo / beneficios',
        'promo' => 'Journey promociones',
        'lab' => 'Journey laboratorio',
        'other' => 'Journey Marketing Intelligence',
    ];

    private ActiveCampaignService $activeCampaign;

    private ActiveCampaignDashboardService $dashboard;

    public function __construct(
        ActiveCampaignService $activeCampaign,
        ActiveCampaignDashboardService $dashboard,
    ) {
        $this->activeCampaign = $activeCampaign;
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
            Cache::forget($this->cacheKey($filter, 'raw-fields'));
            Cache::forget($this->cacheKey($filter, 'executive'));
        }

        $overview = $this->dashboard->buildOverview($filter);
        $rawKey = $this->cacheKey($filter, 'raw-fields');

        $rawItems = Cache::remember($rawKey, now()->addMinutes(5), function () use ($filter) {
            return $this->collectFields($filter)->all();
        });

        $items = collect($rawItems);
        $filtered = $this->applyFilters($items, $filters);

        return [
            'filters' => $filters,
            'filterOptions' => $this->filterOptions($items),
            'summary' => $this->buildSummary($items),
            'fields' => $filtered->map(fn (array $f) => $this->listItem($f))->values()->all(),
            'actions' => $this->quickActions(),
            'meta' => [
                ...($overview['meta'] ?? []),
                'purpose' => 'Visualizar, analizar y administrar los campos personalizados del ecosistema Famedic × ActiveCampaign.',
                'source_of_truth' => 'ActiveCampaignService::getFields() · config services.activecampaign.fields · ActiveCampaignDispatch · mapas Journey/Automation/Analytics',
                'total' => $filtered->count(),
                'note' => 'No es solo el listado AC. Uso/sincronización son proxies locales vía dispatches y config.',
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function buildExecutive(Request $request): array
    {
        $filter = ActiveCampaignDashboardFilter::fromRequest($request);
        $rawKey = $this->cacheKey($filter, 'raw-fields');

        $items = collect(Cache::remember($rawKey, now()->addMinutes(5), function () use ($filter) {
            return $this->collectFields($filter)->all();
        }));

        $mostUsed = $items
            ->filter(fn (array $f) => ($f['usage_count'] ?? 0) > 0)
            ->sortByDesc('usage_count')
            ->take(10)
            ->map(fn (array $f) => [
                'label' => Str::limit((string) $f['name'], 28),
                'value' => (int) $f['usage_count'],
            ])
            ->values()
            ->all();

        $byType = $items->groupBy('type')->map(fn ($g, $k) => [
            'label' => (string) ($k !== '' ? $k : 'desconocido'),
            'value' => $g->count(),
        ])->sortByDesc('value')->values()->all();

        $unused = $items
            ->where('usage', 'unused')
            ->take(12)
            ->map(fn (array $f) => [
                'label' => Str::limit((string) $f['name'], 28),
                'value' => 1,
            ])
            ->values()
            ->all();

        return [
            'most_used' => $mostUsed,
            'by_type' => $byType,
            'unused' => $unused,
            'trend' => $this->usageTrend($filter),
            'gaps' => $this->gaps(),
            'truth' => 'disponible',
            'note' => 'Uso y tendencia derivados de dispatches del periodo + catálogo getFields()/config (cache 5 min).',
        ];
    }

    /**
     * @return array<string, mixed>|null
     */
    public function buildDetail(string $fieldId, Request $request): ?array
    {
        if ($fieldId === '') {
            return null;
        }

        $filter = ActiveCampaignDashboardFilter::fromRequest($request);
        $rawKey = $this->cacheKey($filter, 'raw-fields');
        $items = collect(Cache::remember($rawKey, now()->addMinutes(5), function () use ($filter) {
            return $this->collectFields($filter)->all();
        }));

        $item = $items->firstWhere('id', $fieldId);
        if (! $item) {
            return null;
        }

        $related = $items
            ->filter(fn (array $f) => ($f['family'] ?? '') === ($item['family'] ?? '') && $f['id'] !== $item['id'])
            ->take(8)
            ->map(fn (array $f) => [
                'id' => $f['id'],
                'name' => $f['name'],
                'type' => $f['type'],
            ])
            ->values()
            ->all();

        return [
            ...$item,
            'description_full' => $item['description'] ?: 'Sin descripción en ActiveCampaign ni en catálogo Famedic.',
            'validations' => $item['validations'] ?? [],
            'sync_detail' => $item['sync_detail'] ?? [],
            'related_fields' => $related,
            'related_journey' => $item['related_journey'] ?? 'No mapeado',
            'related_automation' => $item['related_automation'] ?? [],
            'related_analytics' => $item['related_analytics'] ?? [],
            'usage_detail' => $item['usage_label'] ?? 'Sin uso',
            'quick_links' => $item['quick_links'] ?? $this->defaultLinks(),
            'truth' => $item['truth'] ?? 'disponible',
        ];
    }

    private function cacheKey(ActiveCampaignDashboardFilter $filter, string $suffix): string
    {
        return 'mi-fields:v1:'.sha1(json_encode($filter->toArray()).'|'.$suffix);
    }

    /**
     * @return array<string, mixed>
     */
    private function resolveFilters(Request $request): array
    {
        $dash = ActiveCampaignDashboardFilter::fromRequest($request)->toArray();

        return [
            'type' => trim((string) $request->input('type', '')),
            'status' => trim((string) $request->input('status', '')),
            'sync' => trim((string) $request->input('sync', '')),
            'usage' => trim((string) $request->input('usage', '')),
            'origin' => trim((string) $request->input('origin', '')),
            'start_date' => $dash['start_date'],
            'end_date' => $dash['end_date'],
        ];
    }

    /**
     * @param  \Illuminate\Support\Collection<int, array<string, mixed>>  $items
     * @return array<string, list<array{value: string, label: string}>>
     */
    private function filterOptions($items): array
    {
        $types = $items->pluck('type')->filter()->unique()->sort()->values()
            ->map(fn ($t) => ['value' => (string) $t, 'label' => (string) $t])
            ->all();

        return [
            'types' => [
                ['value' => '', 'label' => 'Todos'],
                ...$types,
            ],
            'statuses' => [
                ['value' => '', 'label' => 'Todos'],
                ['value' => 'active', 'label' => 'Activo'],
                ['value' => 'unused', 'label' => 'Sin uso'],
                ['value' => 'missing', 'label' => 'Falta en AC'],
            ],
            'syncs' => [
                ['value' => '', 'label' => 'Todos'],
                ['value' => 'synced', 'label' => 'Sincronizado'],
                ['value' => 'not_synced', 'label' => 'No sincronizado'],
            ],
            'usages' => [
                ['value' => '', 'label' => 'Todos'],
                ['value' => 'used', 'label' => 'Con uso'],
                ['value' => 'unused', 'label' => 'Sin uso'],
            ],
            'origins' => [
                ['value' => '', 'label' => 'Todos'],
                ['value' => 'ActiveCampaign', 'label' => 'ActiveCampaign'],
                ['value' => 'Config Famedic', 'label' => 'Config Famedic'],
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

        return [
            [
                'id' => 'total',
                'label' => 'Campos totales',
                'value' => (string) $col->count(),
                'tone' => 'sky',
                'hint' => 'AC getFields() + catálogo config',
                'truth' => 'disponible',
            ],
            [
                'id' => 'active',
                'label' => 'Campos activos',
                'value' => (string) $col->filter(fn (array $f) => ($f['in_ac'] ?? false) || ($f['status'] ?? '') === 'active')->count(),
                'tone' => 'default',
                'hint' => 'Presentes en AC o con uso en el periodo',
                'truth' => 'disponible',
            ],
            [
                'id' => 'required',
                'label' => 'Campos obligatorios',
                'value' => (string) $col->where('required', true)->count(),
                'tone' => 'amber',
                'hint' => 'isrequired AC o identidad Famedic',
                'truth' => 'proxy',
            ],
            [
                'id' => 'optional',
                'label' => 'Campos opcionales',
                'value' => (string) $col->where('required', false)->count(),
                'tone' => 'zinc',
                'hint' => 'No marcados como obligatorios',
                'truth' => 'proxy',
            ],
            [
                'id' => 'synced',
                'label' => 'Campos sincronizados',
                'value' => (string) $col->where('synced', true)->count(),
                'tone' => 'sky',
                'hint' => 'Config con ID resuelto y/o presente en AC',
                'truth' => 'disponible',
            ],
            [
                'id' => 'unused',
                'label' => 'Campos sin uso',
                'value' => (string) $col->where('usage', 'unused')->count(),
                'tone' => 'amber',
                'hint' => 'Sin dispatches mapeados en el periodo',
                'truth' => 'proxy',
            ],
        ];
    }

    /**
     * @return \Illuminate\Support\Collection<int, array<string, mixed>>
     */
    private function collectFields(ActiveCampaignDashboardFilter $filter)
    {
        $usage = $this->usageByFieldKey($filter);
        $byKey = [];
        $byAcId = [];
        $byTitle = [];

        $upsert = function (array $partial) use (&$byKey, &$byAcId, &$byTitle) {
            $key = $partial['config_key'] ?? null;
            $acId = isset($partial['ac_id']) ? (string) $partial['ac_id'] : null;
            $titleNorm = $this->normalizeName($partial['name'] ?? $partial['title'] ?? '');

            $existing = null;
            if ($key && isset($byKey[$key])) {
                $existing = $byKey[$key];
            } elseif ($acId && isset($byAcId[$acId])) {
                $existing = $byAcId[$acId];
            } elseif ($titleNorm !== '' && isset($byTitle[$titleNorm])) {
                $existing = $byTitle[$titleNorm];
            }

            $merged = $existing
                ? $this->mergeField($existing, $partial)
                : $this->makeField($partial);

            if ($merged['config_key']) {
                $byKey[$merged['config_key']] = $merged;
            }
            if ($merged['ac_id']) {
                $byAcId[(string) $merged['ac_id']] = $merged;
            }
            $nn = $this->normalizeName($merged['name']);
            if ($nn !== '') {
                $byTitle[$nn] = $merged;
            }
            $pers = $this->normalizeName((string) ($merged['perstag'] ?? ''));
            if ($pers !== '') {
                $byTitle[$pers] = $merged;
            }
        };

        foreach ($this->configFields() as $cfg) {
            $upsert($cfg);
        }

        foreach ($this->fetchAcFields() as $ac) {
            $upsert($ac);
        }

        return collect([...array_values($byKey), ...array_values($byAcId), ...array_values($byTitle)])
            ->unique('id')
            ->map(fn (array $f) => $this->finalizeField($f, $usage))
            ->sortBy(fn (array $f) => mb_strtolower($f['name']))
            ->values();
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function fetchAcFields(): array
    {
        try {
            $raw = $this->activeCampaign->getFields();
        } catch (\Throwable) {
            return [];
        }

        $list = [];
        if (isset($raw['fields']) && is_array($raw['fields'])) {
            $list = $raw['fields'];
        } elseif (array_is_list($raw)) {
            $list = $raw;
        }

        $out = [];
        foreach ($list as $row) {
            if (! is_array($row)) {
                continue;
            }
            $title = (string) ($row['title'] ?? $row['name'] ?? '');
            if ($title === '') {
                continue;
            }

            $acId = isset($row['id']) ? (int) $row['id'] : null;
            $type = (string) ($row['type'] ?? 'text');
            $required = $this->parseRequired($row);
            $perstag = (string) ($row['perstag'] ?? '');
            $desc = (string) ($row['descript'] ?? $row['description'] ?? '');

            $out[] = [
                'name' => $title,
                'title' => $title,
                'description' => $desc !== '' ? $desc : 'Campo personalizado ActiveCampaign',
                'type' => $type,
                'ac_id' => $acId,
                'perstag' => $perstag,
                'required' => $required,
                'origin' => 'ActiveCampaign',
                'truth' => 'disponible',
                'source' => 'ac_api',
                'in_ac' => true,
            ];
        }

        return $out;
    }

    /**
     * @param  array<string, mixed>  $row
     */
    private function parseRequired(array $row): bool
    {
        foreach (['isrequired', 'is_required', 'required'] as $k) {
            if (! array_key_exists($k, $row)) {
                continue;
            }
            $v = $row[$k];
            if (is_bool($v)) {
                return $v;
            }
            if (is_numeric($v)) {
                return (int) $v === 1;
            }
            if (is_string($v)) {
                return in_array(strtolower($v), ['1', 'true', 'yes'], true);
            }
        }

        return false;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function configFields(): array
    {
        $cfg = config('services.activecampaign.fields', []);
        if (! is_array($cfg)) {
            return [];
        }

        $out = [];
        foreach ($cfg as $key => $value) {
            $key = (string) $key;
            $meta = self::CONFIG_META[$key] ?? [
                'title' => strtoupper($key),
                'family' => 'other',
                'required' => false,
                'type_hint' => 'text',
            ];

            $configuredId = null;
            if (is_numeric($value) && (int) $value > 0) {
                $configuredId = (int) $value;
            } elseif (is_string($value) && ctype_digit(trim($value))) {
                $configuredId = (int) trim($value);
            }

            $out[] = [
                'name' => $meta['title'],
                'title' => $meta['title'],
                'description' => 'Campo de catálogo Famedic (services.activecampaign.fields.'.$key.')',
                'type' => $meta['type_hint'],
                'config_key' => $key,
                'ac_id' => $configuredId,
                'perstag' => $meta['title'],
                'family' => $meta['family'],
                'required' => $meta['required'],
                'origin' => 'Config Famedic',
                'truth' => $configuredId ? 'disponible' : 'proxy',
                'source' => 'config',
                'configured_id' => $configuredId,
                'config_value_present' => filled($value),
            ];
        }

        return $out;
    }

    /**
     * @param  array<string, mixed>  $partial
     * @return array<string, mixed>
     */
    private function makeField(array $partial): array
    {
        $name = (string) ($partial['name'] ?? $partial['title'] ?? 'Sin nombre');
        $configKey = $partial['config_key'] ?? null;
        $acId = $partial['ac_id'] ?? null;
        $id = $configKey
            ? 'field-cfg-'.$configKey
            : ($acId ? 'field-ac-'.$acId : 'field-name-'.md5($this->normalizeName($name)));

        $family = $partial['family'] ?? $this->familyFromName($name);

        return [
            'id' => $id,
            'name' => $name,
            'description' => (string) ($partial['description'] ?? ''),
            'type' => (string) ($partial['type'] ?? 'text'),
            'config_key' => $configKey,
            'ac_id' => $acId,
            'perstag' => (string) ($partial['perstag'] ?? ''),
            'family' => $family,
            'required' => (bool) ($partial['required'] ?? false),
            'origin' => $partial['origin'] ?? 'ActiveCampaign',
            'truth' => $partial['truth'] ?? 'disponible',
            'source' => $partial['source'] ?? 'unknown',
            'in_ac' => (bool) ($partial['in_ac'] ?? (($partial['source'] ?? '') === 'ac_api')),
            'configured_id' => $partial['configured_id'] ?? null,
            'config_value_present' => (bool) ($partial['config_value_present'] ?? false),
        ];
    }

    /**
     * @param  array<string, mixed>  $existing
     * @param  array<string, mixed>  $partial
     * @return array<string, mixed>
     */
    private function mergeField(array $existing, array $partial): array
    {
        $base = $this->makeField($partial);
        $merged = [
            ...$existing,
            'name' => $existing['name'] ?: $base['name'],
            'description' => $existing['description'] !== '' ? $existing['description'] : $base['description'],
            'type' => ($partial['source'] ?? '') === 'ac_api' ? $base['type'] : ($existing['type'] ?: $base['type']),
            'config_key' => $existing['config_key'] ?: $base['config_key'],
            'ac_id' => $existing['ac_id'] ?: $base['ac_id'],
            'perstag' => $existing['perstag'] !== '' ? $existing['perstag'] : $base['perstag'],
            'family' => $existing['family'] ?: $base['family'],
            'required' => ($existing['required'] ?? false) || ($base['required'] ?? false),
            'truth' => ($existing['truth'] === 'disponible' || $base['truth'] === 'disponible')
                ? 'disponible'
                : ($existing['truth'] ?? $base['truth']),
            'in_ac' => ($existing['in_ac'] ?? false) || ($base['in_ac'] ?? false),
            'configured_id' => $existing['configured_id'] ?: $base['configured_id'],
            'config_value_present' => ($existing['config_value_present'] ?? false) || ($base['config_value_present'] ?? false),
        ];

        if (($partial['source'] ?? '') === 'ac_api') {
            $merged['in_ac'] = true;
            $merged['name'] = $partial['name'] ?? $merged['name'];
            if (($partial['description'] ?? '') !== '') {
                $merged['description'] = $partial['description'];
            }
            if ($merged['config_key']) {
                $merged['origin'] = 'Config Famedic';
            }
        }

        if (($partial['source'] ?? '') === 'config') {
            $merged['origin'] = $merged['in_ac'] ? 'Config Famedic' : 'Config Famedic';
            if ($partial['required'] ?? false) {
                $merged['required'] = true;
            }
        }

        if ($merged['config_key']) {
            $merged['id'] = 'field-cfg-'.$merged['config_key'];
        } elseif ($merged['ac_id']) {
            $merged['id'] = 'field-ac-'.$merged['ac_id'];
        }

        return $merged;
    }

    /**
     * @param  array<string, mixed>  $field
     * @param  array<string, array{count: int, last_at: ?string}>  $usage
     * @return array<string, mixed>
     */
    private function finalizeField(array $field, array $usage): array
    {
        $key = $field['config_key'] ?? null;
        $stats = $key && isset($usage[$key])
            ? $usage[$key]
            : ['count' => 0, 'last_at' => null];

        $usageCount = (int) $stats['count'];
        $hasUsage = $usageCount > 0;
        $inAc = (bool) ($field['in_ac'] ?? false);
        $configured = filled($key);
        $configuredId = $field['configured_id'] ?? null;

        $synced = false;
        if ($configured && $configuredId && $inAc) {
            $synced = (int) ($field['ac_id'] ?? 0) > 0;
        } elseif ($configured && $inAc && ! $configuredId) {
            $synced = true;
        }

        if ($configured && ! $inAc) {
            $status = 'missing';
        } elseif ($hasUsage) {
            $status = 'active';
        } else {
            $status = 'unused';
        }

        $family = $field['family'] ?? $this->familyFromName($field['name']);
        $lastUse = $stats['last_at']
            ? Carbon::parse($stats['last_at'])->timezone(self::TZ)->format('d/m/Y H:i')
            : 'No disponible';

        $validations = $this->validationsFor($field['type'] ?? 'text', $key);

        return [
            ...$field,
            'family' => $family,
            'required' => (bool) ($field['required'] ?? false),
            'required_label' => ($field['required'] ?? false) ? 'Sí' : 'No',
            'synced' => $synced,
            'synced_label' => $synced ? 'Sí' : 'No',
            'sync' => $synced ? 'synced' : 'not_synced',
            'contacts' => '—',
            'contacts_truth' => 'instrumentacion',
            'last_use' => $lastUse,
            'last_use_truth' => $stats['last_at'] ? 'proxy' : 'instrumentacion',
            'status' => $status,
            'status_label' => match ($status) {
                'active' => 'Activo',
                'missing' => 'Falta en AC',
                default => 'Sin uso',
            },
            'usage' => $hasUsage ? 'used' : 'unused',
            'usage_count' => $usageCount,
            'usage_label' => $hasUsage ? number_format($usageCount).' eventos' : 'Sin uso',
            'validations' => $validations,
            'sync_detail' => [
                'config_key' => $key ?: '—',
                'configured_id' => $configuredId ?: '—',
                'ac_id' => $field['ac_id'] ?: '—',
                'perstag' => $field['perstag'] ?: '—',
                'synced' => $synced ? 'Sincronizado' : 'No sincronizado',
                'truth' => $synced ? 'disponible' : 'proxy',
            ],
            'related_journey' => self::JOURNEY_BY_FAMILY[$family] ?? self::JOURNEY_BY_FAMILY['other'],
            'related_automation' => $this->automationsForFamily($family),
            'related_analytics' => $this->analyticsForFamily($family),
            'quick_links' => [
                ['label' => 'Automation Center', 'href' => route('admin.activecampaign.automations')],
                ['label' => 'Customer Journey', 'href' => route('admin.activecampaign.customer-journey')],
                ['label' => 'Analytics', 'href' => route('admin.activecampaign.analytics')],
                ['label' => 'Contactos', 'href' => route('admin.activecampaign.contacts')],
                ['label' => 'QA vs Prod', 'href' => route('admin.activecampaign.qa-compare')],
                ['label' => 'Tags Manager', 'href' => route('admin.activecampaign.tags')],
            ],
            'truth' => $field['truth'] ?? ($inAc ? 'disponible' : 'proxy'),
        ];
    }

    /**
     * @return list<array{label: string, truth: string}>
     */
    private function validationsFor(string $type, ?string $key): array
    {
        $rules = [
            ['label' => 'Tipo AC: '.$type, 'truth' => 'disponible'],
        ];

        if (($key && str_contains($key, 'monto')) || ($key && str_contains($key, 'saldo')) || ($key && str_contains($key, 'compra_minima'))) {
            $rules[] = ['label' => 'Formato monetario (centavos → decimal) vía formatCentsFieldValue', 'truth' => 'disponible'];
        }
        if ($key && (str_contains($key, '_at') || str_contains($key, 'expira'))) {
            $rules[] = ['label' => 'Formato fecha/hora vía formatDate(Field)Value', 'truth' => 'disponible'];
        }
        if (in_array($key, ['fm_user_id', 'fm_customer_id'], true)) {
            $rules[] = ['label' => 'Identidad requerida para sync de cupones', 'truth' => 'proxy'];
        }

        $rules[] = ['label' => 'Validación server-side AC (required/options)', 'truth' => 'instrumentacion'];

        return $rules;
    }

    /**
     * @return array<string, array{count: int, last_at: ?string}>
     */
    private function usageByFieldKey(ActiveCampaignDashboardFilter $filter): array
    {
        $startS = $filter->start->toDateTimeString();
        $endS = $filter->end->toDateTimeString();
        $eventTypes = array_keys(self::EVENT_FIELDS);

        $rows = ActiveCampaignDispatch::query()
            ->toBase()
            ->selectRaw('event_type, COUNT(*) as c, MAX(updated_at) as last_at')
            ->where(function ($q) use ($startS, $endS) {
                $q->whereBetween('updated_at', [$startS, $endS])
                    ->orWhereBetween('synced_at', [$startS, $endS]);
            })
            ->where(function ($q) use ($eventTypes) {
                $q->whereIn('event_type', $eventTypes)
                    ->orWhere('event_type', 'like', 'credit_%')
                    ->orWhere('event_type', 'like', 'promo_%')
                    ->orWhere('event_type', 'like', 'pending_beneficiary_%');
            })
            ->groupBy('event_type')
            ->get();

        $usage = [];
        foreach ($rows as $row) {
            $type = (string) $row->event_type;
            $keys = self::EVENT_FIELDS[$type] ?? null;
            if (! $keys) {
                if (str_starts_with($type, 'credit_')) {
                    $keys = self::EVENT_FIELDS['credit_assigned'];
                } elseif (str_starts_with($type, 'promo_')) {
                    $keys = self::EVENT_FIELDS['promo_validated'];
                } elseif (str_contains($type, 'beneficiary')) {
                    $keys = self::EVENT_FIELDS['pending_beneficiary_created'];
                } else {
                    continue;
                }
            }

            foreach ($keys as $fieldKey) {
                if (! isset($usage[$fieldKey])) {
                    $usage[$fieldKey] = ['count' => 0, 'last_at' => null];
                }
                $usage[$fieldKey]['count'] += (int) $row->c;
                $last = $row->last_at ? (string) $row->last_at : null;
                if ($last && ($usage[$fieldKey]['last_at'] === null || $last > $usage[$fieldKey]['last_at'])) {
                    $usage[$fieldKey]['last_at'] = $last;
                }
            }
        }

        return $usage;
    }

    /**
     * @return list<array{label: string, value: int}>
     */
    private function usageTrend(ActiveCampaignDashboardFilter $filter): array
    {
        $startS = $filter->start->toDateTimeString();
        $endS = $filter->end->toDateTimeString();

        $rows = ActiveCampaignDispatch::query()
            ->toBase()
            ->selectRaw('DATE(COALESCE(synced_at, updated_at)) as d, COUNT(*) as c')
            ->where(function ($q) use ($startS, $endS) {
                $q->whereBetween('updated_at', [$startS, $endS])
                    ->orWhereBetween('synced_at', [$startS, $endS]);
            })
            ->where(function ($q) {
                $q->whereIn('event_type', array_keys(self::EVENT_FIELDS))
                    ->orWhere('event_type', 'like', 'credit_%')
                    ->orWhere('event_type', 'like', 'promo_%')
                    ->orWhere('event_type', 'like', 'pending_beneficiary_%');
            })
            ->groupBy('d')
            ->orderBy('d')
            ->get();

        return $rows->map(fn ($r) => [
            'label' => Carbon::parse($r->d)->timezone(self::TZ)->format('d/m'),
            'value' => (int) $r->c,
        ])->all();
    }

    /**
     * @param  \Illuminate\Support\Collection<int, array<string, mixed>>  $items
     * @param  array<string, mixed>  $filters
     * @return \Illuminate\Support\Collection<int, array<string, mixed>>
     */
    private function applyFilters($items, array $filters)
    {
        return $items->filter(function (array $f) use ($filters) {
            if ($filters['type'] !== '' && ($f['type'] ?? '') !== $filters['type']) {
                return false;
            }
            if ($filters['status'] !== '' && ($f['status'] ?? '') !== $filters['status']) {
                return false;
            }
            if ($filters['sync'] !== '' && ($f['sync'] ?? '') !== $filters['sync']) {
                return false;
            }
            if ($filters['usage'] !== '' && ($f['usage'] ?? '') !== $filters['usage']) {
                return false;
            }
            if ($filters['origin'] !== '' && ($f['origin'] ?? '') !== $filters['origin']) {
                return false;
            }

            return true;
        });
    }

    /**
     * @param  array<string, mixed>  $f
     * @return array<string, mixed>
     */
    private function listItem(array $f): array
    {
        return [
            'id' => $f['id'],
            'name' => $f['name'],
            'type' => $f['type'],
            'required' => $f['required'],
            'required_label' => $f['required_label'],
            'synced' => $f['synced'],
            'synced_label' => $f['synced_label'],
            'contacts' => $f['contacts'],
            'contacts_truth' => $f['contacts_truth'],
            'origin' => $f['origin'],
            'last_use' => $f['last_use'],
            'last_use_truth' => $f['last_use_truth'],
            'status' => $f['status'],
            'status_label' => $f['status_label'],
            'usage' => $f['usage'],
            'truth' => $f['truth'],
        ];
    }

    /**
     * @return list<array{label: string, truth: string}>
     */
    private function automationsForFamily(string $family): array
    {
        return match ($family) {
            'credit', 'balance' => [
                ['label' => 'Pipeline dispatches credit_*', 'truth' => 'disponible'],
                ['label' => 'Cupones por vencer', 'truth' => 'disponible'],
            ],
            'promo' => [
                ['label' => 'Pipeline dispatches promo_*', 'truth' => 'disponible'],
            ],
            'identity' => [
                ['label' => 'Sync contacto / applyCouponFieldUpdates', 'truth' => 'disponible'],
            ],
            'lab' => [
                ['label' => 'Promo usada → fm_ultima_compra_lab_at', 'truth' => 'disponible'],
            ],
            default => [
                ['label' => 'Sin automatización Famedic mapeada', 'truth' => 'proxy'],
            ],
        };
    }

    /**
     * @return list<array{label: string, truth: string}>
     */
    private function analyticsForFamily(string $family): array
    {
        return match ($family) {
            'credit', 'balance', 'promo' => [
                ['label' => 'Analytics cupones / créditos (proxy vía dispatches)', 'truth' => 'proxy'],
            ],
            'lab' => [
                ['label' => 'Laboratory Intelligence / Analytics lab', 'truth' => 'proxy'],
            ],
            'identity' => [
                ['label' => 'CRM / Contactos (identidad)', 'truth' => 'proxy'],
            ],
            default => [
                ['label' => 'Analytics (sin serie dedicada al campo)', 'truth' => 'instrumentacion'],
            ],
        };
    }

    private function familyFromName(string $name): string
    {
        $n = mb_strtolower($name);
        if (str_contains($n, 'user') || str_contains($n, 'customer') || str_contains($n, 'identidad')) {
            return 'identity';
        }
        if (str_contains($n, 'credito') || str_contains($n, 'credit')) {
            return 'credit';
        }
        if (str_contains($n, 'saldo') || str_contains($n, 'balance')) {
            return 'balance';
        }
        if (str_contains($n, 'promo')) {
            return 'promo';
        }
        if (str_contains($n, 'lab')) {
            return 'lab';
        }

        return 'other';
    }

    private function normalizeName(string $name): string
    {
        return mb_strtolower(trim(preg_replace('/\s+/', ' ', $name) ?? $name));
    }

    /**
     * @return list<array{label: string, href: string}>
     */
    private function defaultLinks(): array
    {
        return [
            ['label' => 'Analytics', 'href' => route('admin.activecampaign.analytics')],
            ['label' => 'Automation Center', 'href' => route('admin.activecampaign.automations')],
            ['label' => 'Contactos', 'href' => route('admin.activecampaign.contacts')],
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function quickActions(): array
    {
        return [
            ['id' => 'tags', 'label' => 'Tags Manager', 'href' => route('admin.activecampaign.tags'), 'enabled' => true],
            ['id' => 'automations', 'label' => 'Automation Center', 'href' => route('admin.activecampaign.automations'), 'enabled' => true],
            ['id' => 'journey', 'label' => 'Customer Journey', 'href' => route('admin.activecampaign.customer-journey'), 'enabled' => true],
            ['id' => 'analytics', 'label' => 'Analytics', 'href' => route('admin.activecampaign.analytics'), 'enabled' => true],
            ['id' => 'contacts', 'label' => 'Contactos', 'href' => route('admin.activecampaign.contacts'), 'enabled' => true],
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
                'label' => 'Paginación completa de /fields',
                'reason' => 'getFields() no pagina; catálogos grandes pueden quedar incompletos (getCustomFields sí pagina).',
                'truth' => 'proxy',
            ],
            [
                'label' => 'Conteo de contactos con valor en el campo',
                'reason' => 'Sin lectura agregada de fieldValues / CRM local.',
                'truth' => 'instrumentacion',
            ],
            [
                'label' => 'Crear / editar campos desde MI',
                'reason' => 'Consola de lectura/análisis en v1; mutaciones en fase posterior.',
                'truth' => 'proximamente',
            ],
        ];
    }
}
