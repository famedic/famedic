<?php

namespace App\Services\ActiveCampaign;

use App\Models\ActiveCampaignDispatch;
use App\Support\ActiveCampaign\ActiveCampaignDashboardFilter;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;

/**
 * Tags Manager — consola de administración/análisis de tags (capa de composición).
 * No crea tablas/modelos: consolida ActiveCampaign API + config Famedic + dispatches + mapas Automation/Journey.
 */
class ActiveCampaignTagsManagerService
{
    private const TZ = 'America/Monterrey';

    /**
     * event_type de dispatch → clave config services.activecampaign.tags
     *
     * @var array<string, string>
     */
    private const EVENT_TAG_KEYS = [
        'credit_assigned' => 'credit.available',
        'credit_redeemed' => 'credit.used',
        'credit_restored' => 'credit.restored',
        'credit_revoked' => 'credit.revoked',
        'credit_expiring' => 'credit.expiring',
        'credit_closed' => 'credit.closed',
        'promo_validated' => 'promo.validated',
        'promo_used' => 'promo.used',
        'promo_released' => 'promo.abandoned',
        'pending_beneficiary_created' => 'beneficiary.pending',
        'pending_beneficiary_registered' => 'beneficiary.pending',
        'benefit_activated' => 'benefit.activated',
        'authorization_pending' => 'authorization.pending',
    ];

    /**
     * Familia de journey por prefijo de clave config.
     *
     * @var array<string, string>
     */
    private const JOURNEY_BY_FAMILY = [
        'credit' => 'Journey créditos / cupones',
        'promo' => 'Journey promociones',
        'beneficiary' => 'Journey beneficiarios',
        'benefit' => 'Journey beneficios',
        'authorization' => 'Journey autorizaciones',
        'lab' => 'Journey laboratorio',
        'pharmacy' => 'Journey farmacia',
        'cart' => 'Journey carrito abandonado',
        'registro' => 'Journey registro / onboarding',
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
            Cache::forget($this->cacheKey($filter, 'raw-tags'));
            Cache::forget($this->cacheKey($filter, 'executive'));
        }

        $overview = $this->dashboard->buildOverview($filter);
        $rawKey = $this->cacheKey($filter, 'raw-tags');

        $rawItems = Cache::remember($rawKey, now()->addMinutes(5), function () use ($filter) {
            return $this->collectTags($filter)->all();
        });

        $items = collect($rawItems);
        $filtered = $this->applyFilters($items, $filters);

        return [
            'filters' => $filters,
            'filterOptions' => $this->filterOptions(),
            'summary' => $this->buildSummary($items),
            'tags' => $filtered->map(fn (array $t) => $this->listItem($t))->values()->all(),
            'actions' => $this->quickActions(),
            'meta' => [
                ...($overview['meta'] ?? []),
                'purpose' => 'Administrar, analizar y entender los tags del ecosistema Famedic × ActiveCampaign.',
                'source_of_truth' => 'ActiveCampaign /tags · config services.activecampaign.tags · ActiveCampaignDispatch · Automation/Journey (mapa)',
                'total' => $filtered->count(),
                'note' => 'No es solo la tabla AC. Uso y automatización son proxies locales cuando no hay API de contactTags.',
                'ac_available' => $items->contains(fn (array $t) => ($t['origin'] ?? '') === 'ActiveCampaign' || filled($t['ac_id'] ?? null)),
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function buildExecutive(Request $request): array
    {
        $filter = ActiveCampaignDashboardFilter::fromRequest($request);
        $rawKey = $this->cacheKey($filter, 'raw-tags');

        $items = collect(Cache::remember($rawKey, now()->addMinutes(5), function () use ($filter) {
            return $this->collectTags($filter)->all();
        }));

        $mostUsed = $items
            ->filter(fn (array $t) => ($t['usage_count'] ?? 0) > 0)
            ->sortByDesc('usage_count')
            ->take(10)
            ->map(fn (array $t) => [
                'label' => Str::limit((string) $t['name'], 28),
                'value' => (int) $t['usage_count'],
            ])
            ->values()
            ->all();

        $unused = $items
            ->where('usage', 'unused')
            ->take(12)
            ->map(fn (array $t) => [
                'label' => Str::limit((string) $t['name'], 28),
                'value' => 1,
            ])
            ->values()
            ->all();

        $distribution = [
            ['label' => 'Automáticos', 'value' => $items->where('application', 'automatic')->count()],
            ['label' => 'Manuales', 'value' => $items->where('application', 'manual')->count()],
            ['label' => 'Con uso', 'value' => $items->where('usage', 'used')->count()],
            ['label' => 'Sin uso', 'value' => $items->where('usage', 'unused')->count()],
        ];

        $byOrigin = $items->groupBy('origin')->map(fn ($g, $k) => [
            'label' => (string) $k,
            'value' => $g->count(),
        ])->values()->all();

        $trend = $this->usageTrend($filter);

        return [
            'most_used' => $mostUsed,
            'unused' => $unused,
            'distribution' => $distribution,
            'by_origin' => $byOrigin,
            'trend' => $trend,
            'gaps' => $this->gaps(),
            'truth' => 'disponible',
            'note' => 'Uso y tendencia derivados de dispatches del periodo + catálogo AC/config (cache 5 min).',
        ];
    }

    /**
     * @return array<string, mixed>|null
     */
    public function buildDetail(string $tagId, Request $request): ?array
    {
        if ($tagId === '') {
            return null;
        }

        $filter = ActiveCampaignDashboardFilter::fromRequest($request);
        $rawKey = $this->cacheKey($filter, 'raw-tags');
        $items = collect(Cache::remember($rawKey, now()->addMinutes(5), function () use ($filter) {
            return $this->collectTags($filter)->all();
        }));

        $item = $items->firstWhere('id', $tagId);
        if (! $item) {
            return null;
        }

        return [
            ...$item,
            'description_full' => $item['description'] ?: 'Sin descripción en ActiveCampaign ni en catálogo Famedic.',
            'related_automations' => $item['related_automations'] ?? [],
            'related_journey' => $item['related_journey'] ?? 'No mapeado',
            'contacts_count' => $item['contacts'],
            'contacts_truth' => $item['contacts_truth'],
            'campaign_usage' => $item['campaign_usage'] ?? [
                'label' => 'Uso en campañas AC',
                'value' => 'No disponible',
                'truth' => 'instrumentacion',
                'note' => 'Requiere API de campañas / contactTags histórico.',
            ],
            'related_timeline' => $item['related_timeline'] ?? 'Los eventos de tag pueden reflejarse en Timeline/Vista 360 del paciente cuando el dispatch está ligado a un contacto.',
            'quick_links' => $item['quick_links'] ?? $this->defaultLinks(),
            'truth' => $item['truth'] ?? 'disponible',
        ];
    }

    private function cacheKey(ActiveCampaignDashboardFilter $filter, string $suffix): string
    {
        return 'mi-tags:v1:'.sha1(json_encode($filter->toArray()).'|'.$suffix);
    }

    /**
     * @return array<string, mixed>
     */
    private function resolveFilters(Request $request): array
    {
        $dash = ActiveCampaignDashboardFilter::fromRequest($request)->toArray();

        return [
            'status' => trim((string) $request->input('status', '')),
            'origin' => trim((string) $request->input('origin', '')),
            'application' => trim((string) $request->input('application', '')),
            'usage' => trim((string) $request->input('usage', '')),
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
            'statuses' => [
                ['value' => '', 'label' => 'Todos'],
                ['value' => 'active', 'label' => 'Activo'],
                ['value' => 'unused', 'label' => 'Sin uso'],
                ['value' => 'missing', 'label' => 'Falta en AC'],
            ],
            'origins' => [
                ['value' => '', 'label' => 'Todos'],
                ['value' => 'ActiveCampaign', 'label' => 'ActiveCampaign'],
                ['value' => 'Config Famedic', 'label' => 'Config Famedic'],
                ['value' => 'Legacy ID', 'label' => 'Legacy ID'],
            ],
            'applications' => [
                ['value' => '', 'label' => 'Todos'],
                ['value' => 'automatic', 'label' => 'Automático'],
                ['value' => 'manual', 'label' => 'Manual'],
            ],
            'usages' => [
                ['value' => '', 'label' => 'Todos'],
                ['value' => 'used', 'label' => 'Con uso'],
                ['value' => 'unused', 'label' => 'Sin uso'],
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
                'label' => 'Total tags',
                'value' => (string) $col->count(),
                'tone' => 'sky',
                'hint' => 'AC + catálogo config + IDs legacy',
                'truth' => 'disponible',
            ],
            [
                'id' => 'active',
                'label' => 'Tags activos',
                'value' => (string) $col->filter(fn (array $t) => ($t['in_ac'] ?? false) || ($t['status'] ?? '') === 'active')->count(),
                'tone' => 'default',
                'hint' => 'Presentes en AC o con uso en el periodo',
                'truth' => 'disponible',
            ],
            [
                'id' => 'unused',
                'label' => 'Tags sin uso',
                'value' => (string) $col->where('usage', 'unused')->count(),
                'tone' => 'amber',
                'hint' => 'Sin dispatches mapeados en el periodo',
                'truth' => 'proxy',
            ],
            [
                'id' => 'automatic',
                'label' => 'Tags automáticos',
                'value' => (string) $col->where('application', 'automatic')->count(),
                'tone' => 'sky',
                'hint' => 'Cableados en jobs / config / automation',
                'truth' => 'disponible',
            ],
            [
                'id' => 'manual',
                'label' => 'Tags manuales',
                'value' => (string) $col->where('application', 'manual')->count(),
                'tone' => 'zinc',
                'hint' => 'En AC sin automatización Famedic conocida',
                'truth' => 'proxy',
            ],
        ];
    }

    /**
     * @return \Illuminate\Support\Collection<int, array<string, mixed>>
     */
    private function collectTags(ActiveCampaignDashboardFilter $filter)
    {
        $usage = $this->usageByTagKey($filter);
        $byKey = [];
        $byName = [];
        $byAcId = [];

        $upsert = function (array $partial) use (&$byKey, &$byName, &$byAcId) {
            $key = $partial['config_key'] ?? null;
            $nameNorm = $this->normalizeName($partial['name'] ?? '');
            $acId = isset($partial['ac_id']) ? (string) $partial['ac_id'] : null;

            $existing = null;
            if ($key && isset($byKey[$key])) {
                $existing = $byKey[$key];
            } elseif ($acId && isset($byAcId[$acId])) {
                $existing = $byAcId[$acId];
            } elseif ($nameNorm !== '' && isset($byName[$nameNorm])) {
                $existing = $byName[$nameNorm];
            }

            $merged = $existing
                ? $this->mergeTag($existing, $partial)
                : $this->makeTag($partial);

            if ($merged['config_key']) {
                $byKey[$merged['config_key']] = $merged;
            }
            if ($merged['ac_id']) {
                $byAcId[(string) $merged['ac_id']] = $merged;
            }
            $nn = $this->normalizeName($merged['name']);
            if ($nn !== '') {
                $byName[$nn] = $merged;
            }
        };

        foreach ($this->configNameTags() as $cfg) {
            $upsert($cfg);
        }

        foreach ($this->legacyIdTags() as $legacy) {
            $upsert($legacy);
        }

        foreach ($this->fetchAcTags() as $ac) {
            $upsert($ac);
        }

        $items = collect([...array_values($byKey), ...array_values($byAcId), ...array_values($byName)])
            ->unique('id')
            ->map(fn (array $tag) => $this->finalizeTag($tag, $usage))
            ->sortBy(fn (array $t) => mb_strtolower($t['name']))
            ->values();

        return $items;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function fetchAcTags(): array
    {
        try {
            $raw = $this->activeCampaign->getTags();
        } catch (\Throwable) {
            return [];
        }

        if (! is_array($raw) || $raw === []) {
            return [];
        }

        $out = [];
        foreach ($raw as $row) {
            if (! is_array($row)) {
                continue;
            }
            $name = (string) ($row['tag'] ?? $row['name'] ?? '');
            if ($name === '') {
                continue;
            }
            $acId = isset($row['id']) ? (int) $row['id'] : null;
            $contacts = $row['subscriber_count'] ?? $row['contactCount'] ?? null;

            $out[] = [
                'name' => $name,
                'description' => (string) ($row['description'] ?? ''),
                'ac_id' => $acId,
                'origin' => 'ActiveCampaign',
                'application' => 'manual',
                'contacts_raw' => is_numeric($contacts) ? (int) $contacts : null,
                'contacts_truth' => is_numeric($contacts) ? 'disponible' : 'instrumentacion',
                'truth' => 'disponible',
                'source' => 'ac_api',
            ];
        }

        return $out;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function configNameTags(): array
    {
        $tree = config('services.activecampaign.tags', []);
        if (! is_array($tree)) {
            return [];
        }

        $out = [];
        $walk = function (array $node, string $prefix) use (&$walk, &$out) {
            foreach ($node as $key => $value) {
                $path = $prefix === '' ? (string) $key : $prefix.'.'.$key;
                if (is_array($value)) {
                    $walk($value, $path);
                    continue;
                }
                if (! is_string($value) || trim($value) === '') {
                    continue;
                }
                $family = explode('.', $path)[0] ?? 'other';
                $out[] = [
                    'name' => $value,
                    'description' => 'Tag de catálogo Famedic ('.$path.')',
                    'config_key' => $path,
                    'family' => $family,
                    'origin' => 'Config Famedic',
                    'application' => 'automatic',
                    'truth' => 'disponible',
                    'source' => 'config',
                    'related_automations' => $this->automationsForFamily($family),
                    'related_journey' => self::JOURNEY_BY_FAMILY[$family] ?? 'Journey Marketing Intelligence',
                ];
            }
        };
        $walk($tree, '');

        return $out;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function legacyIdTags(): array
    {
        $defs = [
            [
                'config_key' => 'legacy.registro_nuevo',
                'name' => 'RegistroNuevo',
                'ac_id' => $this->intConfig('tag_registro_nuevo'),
                'family' => 'registro',
                'description' => 'Tag onboarding (config tag_registro_nuevo)',
            ],
            [
                'config_key' => 'legacy.pharmacy_purchase',
                'name' => 'Pharmacy purchase completed',
                'ac_id' => $this->intConfig('tag_pharmacy_purchase_completed'),
                'family' => 'pharmacy',
                'description' => 'Compra farmacia completada (ID legacy)',
            ],
            [
                'config_key' => 'legacy.laboratory_purchase',
                'name' => 'Laboratory purchase completed',
                'ac_id' => $this->intConfig('tag_laboratory_purchase_completed'),
                'family' => 'lab',
                'description' => 'Compra laboratorio completada (ID legacy)',
            ],
            [
                'config_key' => 'legacy.lab_sample',
                'name' => 'Lab Toma de muestra',
                'ac_id' => $this->intConfig('tag_lab_sample_collected'),
                'family' => 'lab',
                'description' => 'Muestra recolectada (ID legacy)',
            ],
            [
                'config_key' => 'legacy.lab_results',
                'name' => 'Lab Resultado Disponible',
                'ac_id' => $this->intConfig('tag_lab_results_available'),
                'family' => 'lab',
                'description' => 'Resultados disponibles (ID legacy)',
            ],
            [
                'config_key' => 'legacy.abandoned_carts',
                'name' => 'Carrito abandonado',
                'ac_id' => null,
                'family' => 'cart',
                'description' => 'Tag aplicado por activecampaign:tag-abandoned-carts (nombre resuelto en runtime)',
            ],
        ];

        $out = [];
        foreach ($defs as $def) {
            if ($def['config_key'] !== 'legacy.abandoned_carts' && empty($def['ac_id'])) {
                continue;
            }
            $out[] = [
                'name' => $def['name'],
                'description' => $def['description'],
                'config_key' => $def['config_key'],
                'ac_id' => $def['ac_id'],
                'family' => $def['family'],
                'origin' => 'Legacy ID',
                'application' => 'automatic',
                'truth' => $def['ac_id'] ? 'disponible' : 'proxy',
                'source' => 'legacy_config',
                'related_automations' => $this->automationsForFamily($def['family']),
                'related_journey' => self::JOURNEY_BY_FAMILY[$def['family']] ?? 'Journey Marketing Intelligence',
            ];
        }

        return $out;
    }

    private function intConfig(string $key): ?int
    {
        $raw = config('services.activecampaign.'.$key);
        if (is_numeric($raw) && (int) $raw > 0) {
            return (int) $raw;
        }

        return null;
    }

    /**
     * @param  array<string, mixed>  $partial
     * @return array<string, mixed>
     */
    private function makeTag(array $partial): array
    {
        $name = (string) ($partial['name'] ?? 'Sin nombre');
        $configKey = $partial['config_key'] ?? null;
        $acId = $partial['ac_id'] ?? null;
        $id = $configKey
            ? 'tag-cfg-'.str_replace('.', '-', $configKey)
            : ($acId ? 'tag-ac-'.$acId : 'tag-name-'.md5($this->normalizeName($name)));

        return [
            'id' => $id,
            'name' => $name,
            'description' => (string) ($partial['description'] ?? ''),
            'config_key' => $configKey,
            'ac_id' => $acId,
            'family' => $partial['family'] ?? $this->familyFromName($name),
            'origin' => $partial['origin'] ?? 'ActiveCampaign',
            'application' => $partial['application'] ?? 'manual',
            'contacts_raw' => $partial['contacts_raw'] ?? null,
            'contacts_truth' => $partial['contacts_truth'] ?? 'instrumentacion',
            'truth' => $partial['truth'] ?? 'disponible',
            'source' => $partial['source'] ?? 'unknown',
            'in_ac' => ($partial['source'] ?? '') === 'ac_api',
            'related_automations' => $partial['related_automations'] ?? [],
            'related_journey' => $partial['related_journey'] ?? null,
        ];
    }

    /**
     * @param  array<string, mixed>  $existing
     * @param  array<string, mixed>  $partial
     * @return array<string, mixed>
     */
    private function mergeTag(array $existing, array $partial): array
    {
        $base = $this->makeTag($partial);
        $merged = [
            ...$existing,
            'name' => $existing['name'] ?: $base['name'],
            'description' => $existing['description'] !== '' ? $existing['description'] : $base['description'],
            'config_key' => $existing['config_key'] ?: $base['config_key'],
            'ac_id' => $existing['ac_id'] ?: $base['ac_id'],
            'family' => $existing['family'] ?: $base['family'],
            'application' => ($existing['application'] === 'automatic' || $base['application'] === 'automatic')
                ? 'automatic'
                : 'manual',
            'truth' => $existing['truth'] === 'disponible' || $base['truth'] === 'disponible'
                ? 'disponible'
                : ($existing['truth'] ?? $base['truth']),
            'in_ac' => ($existing['in_ac'] ?? false) || ($base['in_ac'] ?? false),
            'related_automations' => $existing['related_automations'] ?: $base['related_automations'],
            'related_journey' => $existing['related_journey'] ?: $base['related_journey'],
        ];

        if (($partial['source'] ?? '') === 'ac_api') {
            $merged['in_ac'] = true;
            $merged['origin'] = $existing['config_key']
                ? ($existing['origin'] === 'Legacy ID' ? 'Legacy ID' : 'Config Famedic')
                : 'ActiveCampaign';
            if (isset($partial['contacts_raw'])) {
                $merged['contacts_raw'] = $partial['contacts_raw'];
                $merged['contacts_truth'] = $partial['contacts_truth'] ?? 'disponible';
            }
            if (($partial['description'] ?? '') !== '' && ($existing['description'] ?? '') === '') {
                $merged['description'] = $partial['description'];
            }
            // Prefer AC display name when config had placeholder English labels for legacy.
            if (($existing['source'] ?? '') === 'legacy_config' && ($partial['name'] ?? '') !== '') {
                $merged['name'] = $partial['name'];
            }
        }

        if (($partial['source'] ?? '') === 'config' || ($partial['source'] ?? '') === 'legacy_config') {
            $merged['application'] = 'automatic';
            if (! $merged['config_key']) {
                $merged['config_key'] = $partial['config_key'] ?? null;
            }
            if (($partial['origin'] ?? '') === 'Legacy ID') {
                $merged['origin'] = 'Legacy ID';
            } elseif ($merged['origin'] === 'ActiveCampaign' && ($partial['origin'] ?? '') === 'Config Famedic') {
                $merged['origin'] = 'Config Famedic';
            }
        }

        // Stable id preference: config key > ac id
        if ($merged['config_key']) {
            $merged['id'] = 'tag-cfg-'.str_replace('.', '-', $merged['config_key']);
        } elseif ($merged['ac_id']) {
            $merged['id'] = 'tag-ac-'.$merged['ac_id'];
        }

        return $merged;
    }

    /**
     * @param  array<string, mixed>  $tag
     * @param  array<string, array{count: int, last_at: ?string}>  $usage
     * @return array<string, mixed>
     */
    private function finalizeTag(array $tag, array $usage): array
    {
        $usageKey = $tag['config_key'] ?? null;
        $stats = $usageKey && isset($usage[$usageKey])
            ? $usage[$usageKey]
            : ['count' => 0, 'last_at' => null];

        // Fallback: match usage by name for AC-only tags
        if ($stats['count'] === 0) {
            foreach ($usage as $key => $u) {
                $cfgName = data_get(config('services.activecampaign.tags'), $key);
                if (is_string($cfgName) && $this->normalizeName($cfgName) === $this->normalizeName($tag['name'])) {
                    $stats = $u;
                    break;
                }
            }
        }

        $usageCount = (int) $stats['count'];
        $hasUsage = $usageCount > 0;
        $inAc = (bool) ($tag['in_ac'] ?? false);
        $configured = filled($tag['config_key'] ?? null);

        $usageFlag = $hasUsage ? 'used' : 'unused';

        if ($configured && ! $inAc && ($tag['origin'] ?? '') === 'Config Famedic') {
            $status = 'missing';
        } elseif ($hasUsage) {
            $status = 'active';
        } else {
            $status = 'unused';
        }

        $contactsRaw = $tag['contacts_raw'] ?? null;
        $contactsLabel = $contactsRaw !== null
            ? number_format($contactsRaw)
            : '—';

        $lastUse = $stats['last_at']
            ? Carbon::parse($stats['last_at'])->timezone(self::TZ)->format('d/m/Y H:i')
            : 'No disponible';

        $family = $tag['family'] ?? $this->familyFromName($tag['name']);

        return [
            ...$tag,
            'family' => $family,
            'contacts' => $contactsLabel,
            'contacts_truth' => $tag['contacts_truth'] ?? 'instrumentacion',
            'automation_label' => ($tag['application'] ?? '') === 'automatic' ? 'Automático' : 'Manual',
            'application' => $tag['application'] ?? 'manual',
            'application_label' => ($tag['application'] ?? '') === 'automatic' ? 'Automático' : 'Manual',
            'last_use' => $lastUse,
            'last_use_truth' => $stats['last_at'] ? 'proxy' : 'instrumentacion',
            'status' => $status,
            'status_label' => match ($status) {
                'active' => 'Activo',
                'missing' => 'Falta en AC',
                default => 'Sin uso',
            },
            'usage' => $usageFlag,
            'usage_count' => $usageCount,
            'usage_label' => $hasUsage ? number_format($usageCount).' eventos' : 'Sin uso',
            'related_automations' => $tag['related_automations'] ?: $this->automationsForFamily($family),
            'related_journey' => $tag['related_journey'] ?: (self::JOURNEY_BY_FAMILY[$family] ?? 'Journey Marketing Intelligence'),
            'related_timeline' => 'Mapa Timeline/CRM: el tag puede aparecer vía dispatches ligados al paciente (Vista 360 / Timeline).',
            'campaign_usage' => [
                'label' => 'Uso en campañas AC',
                'value' => 'No disponible',
                'truth' => 'instrumentacion',
                'note' => 'Sin lectura de campañas ActiveCampaign en MI.',
            ],
            'quick_links' => [
                ['label' => 'Automation Center', 'href' => route('admin.activecampaign.automations')],
                ['label' => 'Customer Journey', 'href' => route('admin.activecampaign.customer-journey')],
                ['label' => 'Contactos', 'href' => route('admin.activecampaign.contacts')],
                ['label' => 'Event Center', 'href' => route('admin.activecampaign.events')],
                ['label' => 'QA vs Prod', 'href' => route('admin.activecampaign.qa-compare')],
            ],
            'truth' => $tag['truth'] ?? ($inAc ? 'disponible' : 'proxy'),
        ];
    }

    /**
     * @return array<string, array{count: int, last_at: ?string}>
     */
    private function usageByTagKey(ActiveCampaignDashboardFilter $filter): array
    {
        $startS = $filter->start->toDateTimeString();
        $endS = $filter->end->toDateTimeString();

        $eventTypes = array_keys(self::EVENT_TAG_KEYS);

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
                    ->orWhere('event_type', 'like', 'pending_beneficiary_%')
                    ->orWhere('event_type', 'like', '%tag%')
                    ->orWhere('event_type', 'like', '%abandoned%');
            })
            ->groupBy('event_type')
            ->get();

        $usage = [];
        foreach ($rows as $row) {
            $type = (string) $row->event_type;
            $key = self::EVENT_TAG_KEYS[$type] ?? null;
            if (! $key) {
                if (str_starts_with($type, 'credit_')) {
                    $key = 'credit.available';
                } elseif (str_starts_with($type, 'promo_')) {
                    $key = 'promo.validated';
                } elseif (str_contains($type, 'abandoned')) {
                    $key = 'legacy.abandoned_carts';
                } elseif (str_contains($type, 'beneficiary')) {
                    $key = 'beneficiary.pending';
                } else {
                    continue;
                }
            }

            if (! isset($usage[$key])) {
                $usage[$key] = ['count' => 0, 'last_at' => null];
            }
            $usage[$key]['count'] += (int) $row->c;
            $last = $row->last_at ? (string) $row->last_at : null;
            if ($last && ($usage[$key]['last_at'] === null || $last > $usage[$key]['last_at'])) {
                $usage[$key]['last_at'] = $last;
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
                $q->whereIn('event_type', array_keys(self::EVENT_TAG_KEYS))
                    ->orWhere('event_type', 'like', 'credit_%')
                    ->orWhere('event_type', 'like', 'promo_%')
                    ->orWhere('event_type', 'like', '%abandoned%');
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
        return $items->filter(function (array $t) use ($filters) {
            if ($filters['status'] !== '' && ($t['status'] ?? '') !== $filters['status']) {
                return false;
            }
            if ($filters['origin'] !== '' && ($t['origin'] ?? '') !== $filters['origin']) {
                return false;
            }
            if ($filters['application'] !== '' && ($t['application'] ?? '') !== $filters['application']) {
                return false;
            }
            if ($filters['usage'] !== '' && ($t['usage'] ?? '') !== $filters['usage']) {
                return false;
            }

            return true;
        });
    }

    /**
     * @param  array<string, mixed>  $t
     * @return array<string, mixed>
     */
    private function listItem(array $t): array
    {
        return [
            'id' => $t['id'],
            'name' => $t['name'],
            'description' => Str::limit((string) $t['description'], 120),
            'contacts' => $t['contacts'],
            'contacts_truth' => $t['contacts_truth'],
            'origin' => $t['origin'],
            'automation_label' => $t['automation_label'],
            'application' => $t['application'],
            'last_use' => $t['last_use'],
            'last_use_truth' => $t['last_use_truth'],
            'status' => $t['status'],
            'status_label' => $t['status_label'],
            'usage' => $t['usage'],
            'usage_label' => $t['usage_label'],
            'truth' => $t['truth'],
        ];
    }

    /**
     * @return list<array{label: string, truth: string}>
     */
    private function automationsForFamily(string $family): array
    {
        return match ($family) {
            'credit' => [
                ['label' => 'Pipeline dispatches credit_*', 'truth' => 'disponible'],
                ['label' => 'Cupones por vencer', 'truth' => 'disponible'],
            ],
            'promo' => [
                ['label' => 'Pipeline dispatches promo_*', 'truth' => 'disponible'],
            ],
            'cart' => [
                ['label' => 'Tag carritos abandonados', 'truth' => 'disponible'],
            ],
            'lab' => [
                ['label' => 'Jobs laboratorio (muestra / resultados)', 'truth' => 'disponible'],
            ],
            'pharmacy' => [
                ['label' => 'Tag compra farmacia', 'truth' => 'disponible'],
            ],
            'beneficiary', 'benefit', 'authorization' => [
                ['label' => 'Pipeline dispatches cupones / beneficiarios', 'truth' => 'disponible'],
            ],
            'registro' => [
                ['label' => 'RegistroNuevo en sync de contacto', 'truth' => 'disponible'],
            ],
            default => [
                ['label' => 'Sin automatización Famedic mapeada', 'truth' => 'proxy'],
            ],
        };
    }

    private function familyFromName(string $name): string
    {
        $n = mb_strtolower($name);
        if (str_contains($n, 'credito') || str_contains($n, 'credit')) {
            return 'credit';
        }
        if (str_contains($n, 'promo')) {
            return 'promo';
        }
        if (str_contains($n, 'lab')) {
            return 'lab';
        }
        if (str_contains($n, 'farmac') || str_contains($n, 'pharm')) {
            return 'pharmacy';
        }
        if (str_contains($n, 'carrito') || str_contains($n, 'abandon')) {
            return 'cart';
        }
        if (str_contains($n, 'benefici')) {
            return 'beneficiary';
        }
        if (str_contains($n, 'registro')) {
            return 'registro';
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
            ['label' => 'Automation Center', 'href' => route('admin.activecampaign.automations')],
            ['label' => 'Contactos', 'href' => route('admin.activecampaign.contacts')],
            ['label' => 'Customer Journey', 'href' => route('admin.activecampaign.customer-journey')],
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function quickActions(): array
    {
        return [
            ['id' => 'automations', 'label' => 'Automation Center', 'href' => route('admin.activecampaign.automations'), 'enabled' => true],
            ['id' => 'journey', 'label' => 'Customer Journey', 'href' => route('admin.activecampaign.customer-journey'), 'enabled' => true],
            ['id' => 'contacts', 'label' => 'Contactos', 'href' => route('admin.activecampaign.contacts'), 'enabled' => true],
            ['id' => 'events', 'label' => 'Event Center', 'href' => route('admin.activecampaign.events'), 'enabled' => true],
            ['id' => 'qa', 'label' => 'QA vs Prod', 'href' => route('admin.activecampaign.qa-compare'), 'enabled' => true],
            ['id' => 'analytics', 'label' => 'Analytics', 'href' => route('admin.activecampaign.analytics'), 'enabled' => true],
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function gaps(): array
    {
        return [
            [
                'label' => 'Conteo real de contactos por tag (CRM local)',
                'reason' => 'Contactos MI aún no sincroniza contactTags; se usa subscriber_count AC si existe.',
                'truth' => 'instrumentacion',
            ],
            [
                'label' => 'Uso en campañas ActiveCampaign',
                'reason' => 'Sin lectura de campaigns API en Marketing Intelligence.',
                'truth' => 'instrumentacion',
            ],
            [
                'label' => 'Crear / editar / eliminar tags desde MI',
                'reason' => 'Consola de lectura/análisis en v1; mutaciones quedan para fase posterior.',
                'truth' => 'proximamente',
            ],
        ];
    }
}
