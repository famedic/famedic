<?php

namespace App\Services\ActiveCampaign;

use App\DataTransferObjects\ActiveCampaign\ContactEngagementData;
use App\DataTransferObjects\ActiveCampaign\ContactLeadScoreSummary;
use App\Models\ActiveCampaignDispatch;
use App\Models\Customer;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

/**
 * Capa de orquestación del ActiveCampaign Operations Center.
 * Reutiliza ActiveCampaignService, ReadService, MirrorService y CacheService.
 * No duplica HTTP ni altera el Mirror.
 */
class ActiveCampaignOperationsService
{
    private const OPS_META_KEY = 'ac:ops:meta';

    private const UNAVAILABLE = 'No disponible';

    public function __construct(
        protected ActiveCampaignService $activeCampaign,
        protected ActiveCampaignReadService $read,
        protected ActiveCampaignMirrorService $mirror,
        protected ActiveCampaignCacheService $cache,
    ) {}

    /**
     * Payload completo de la consola.
     *
     * @return array<string, mixed>
     */
    public function overview(): array
    {
        return [
            'health' => $this->health(),
            'sync' => $this->synchronization(),
            'mirror' => $this->mirror(),
            'intelligence' => $this->contactIntelligence(),
            'activity' => $this->activity(),
            'diagnostics' => $this->diagnostics(),
            'meta' => [
                'generated_at' => now()->timezone('America/Monterrey')->format('d/m/Y H:i:s'),
                'generated_at_iso' => now()->toIso8601String(),
                'enabled' => $this->activeCampaign->enabled(),
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function health(): array
    {
        $meta = $this->opsMeta();
        $lastTest = $meta['last_api_test'] ?? null;
        $enabled = $this->activeCampaign->enabled();

        $endpoint = (string) (config('services.activecampaign.endpoint') ?? '');
        $environment = $this->resolveEnvironment($endpoint);

        $status = 'unknown';
        if (! $enabled) {
            $status = 'disabled';
        } elseif (is_array($lastTest)) {
            $status = ($lastTest['ok'] ?? false) ? 'healthy' : 'error';
        }

        $lastError = ActiveCampaignDispatch::query()
            ->where('status', ActiveCampaignDispatch::STATUS_FAILED)
            ->latest('id')
            ->first(['last_error', 'updated_at', 'event_type']);

        return [
            'status' => $status,
            'status_label' => match ($status) {
                'healthy' => 'Operativa',
                'error' => 'Con errores',
                'disabled' => 'Deshabilitada',
                default => 'Sin probar',
            },
            'response_ms' => $lastTest['response_ms'] ?? self::UNAVAILABLE,
            'environment' => $environment,
            'rate_limit' => $lastTest['rate_limit'] ?? self::UNAVAILABLE,
            'last_request_at' => $lastTest['at_human'] ?? self::UNAVAILABLE,
            'last_request_at_iso' => $lastTest['at'] ?? null,
            'last_error' => $lastError?->last_error
                ? \Illuminate\Support\Str::limit((string) $lastError->last_error, 160)
                : self::UNAVAILABLE,
            'last_error_at' => $lastError?->updated_at
                ? $lastError->updated_at->timezone('America/Monterrey')->format('d/m/Y H:i')
                : self::UNAVAILABLE,
            'api_version' => 'v3',
            'enabled' => $enabled,
            'endpoint_host' => $this->endpointHost($endpoint),
        ];
    }

    /**
     * Métricas del día desde activecampaign_dispatches.
     *
     * @return array<string, mixed>
     */
    public function synchronization(): array
    {
        $todayStart = now()->timezone('America/Monterrey')->startOfDay()->utc();

        $base = ActiveCampaignDispatch::query()->where('created_at', '>=', $todayStart);

        $synced = (clone $base)->where('status', ActiveCampaignDispatch::STATUS_SYNCED)->count();
        $failed = (clone $base)->where('status', ActiveCampaignDispatch::STATUS_FAILED)->count();
        $retries = (int) (clone $base)->sum('attempts');
        $pending = (clone $base)->whereIn('status', [
            ActiveCampaignDispatch::STATUS_PENDING,
            ActiveCampaignDispatch::STATUS_PROCESSING,
        ])->count();

        $byType = (clone $base)
            ->selectRaw('event_type, count(*) as total')
            ->groupBy('event_type')
            ->pluck('total', 'event_type')
            ->all();

        $categories = [
            'registros' => $this->sumEventTypes($byType, ['new_registration', 'registration', 'patient_created', 'sync_contact']),
            'compras' => $this->sumEventTypes($byType, [
                'completed_purchase', 'laboratory_purchase', 'pharmacy_purchase',
                'laboratoryPurchase', 'pharmacyPurchase', 'completedPurchase',
            ]),
            'pedidos' => $this->sumEventTypes($byType, ['create_order', 'ecom_order', 'cart_added']),
            'resultados' => $this->sumEventTypes($byType, ['results_available', 'sample_collected', 'invoice_available']),
            'membresias' => $this->sumEventTypes($byType, [
                'membership_activated', 'membership_ended', 'activate_membership', 'end_membership',
            ]),
            'cupones' => $this->sumEventTypes($byType, [
                'credit_assigned', 'credit_redeemed', 'credit_restored', 'credit_revoked', 'credit_expiring',
                'promo_validated', 'promo_used', 'promo_released',
            ]),
        ];

        return [
            'period' => 'hoy',
            'synced' => $synced,
            'failed' => $failed,
            'retries' => max(0, $retries - $synced - $failed - $pending),
            'pending' => $pending,
            'total' => (clone $base)->count(),
            'categories' => $categories,
            'by_event_type' => $byType,
        ];
    }

    /**
     * Estado del Mirror / cache (sin alterar MirrorService).
     *
     * @return array<string, mixed>
     */
    public function mirror(): array
    {
        $meta = $this->opsMeta();
        $sampleLimit = 100;

        $customerIds = Customer::query()
            ->whereNotNull('ac_contact_id')
            ->orderByDesc('ac_last_sync_at')
            ->limit($sampleLimit)
            ->pluck('id');

        $hits = 0;
        $misses = 0;
        foreach ($customerIds as $customerId) {
            if ($this->cache->getSnapshot((int) $customerId) !== null) {
                $hits++;
            } else {
                $misses++;
            }
        }

        $withAc = Customer::query()->whereNotNull('ac_contact_id')->count();
        $syncedToday = Customer::query()
            ->whereNotNull('ac_last_sync_at')
            ->where('ac_last_sync_at', '>=', now()->timezone('America/Monterrey')->startOfDay()->utc())
            ->count();

        $lastSync = Customer::query()
            ->whereNotNull('ac_last_sync_at')
            ->orderByDesc('ac_last_sync_at')
            ->value('ac_last_sync_at');

        return [
            'ttl_seconds' => ActiveCampaignCacheService::TTL_SECONDS,
            'ttl_human' => round(ActiveCampaignCacheService::TTL_SECONDS / 60).' min',
            'cache_hits' => $hits,
            'cache_misses' => $misses,
            'sample_size' => $customerIds->count(),
            'snapshots_cached' => $hits,
            'customers_linked' => $withAc,
            'synced_today' => $syncedToday,
            'last_sync_at' => $lastSync
                ? Carbon::parse($lastSync)->timezone('America/Monterrey')->format('d/m/Y H:i')
                : self::UNAVAILABLE,
            'last_sync_at_iso' => $lastSync ? Carbon::parse($lastSync)->toIso8601String() : null,
            'last_invalidation_at' => $meta['last_invalidation']['at_human'] ?? self::UNAVAILABLE,
            'last_invalidation_at_iso' => $meta['last_invalidation']['at'] ?? null,
        ];
    }

    /**
     * Inteligencia agregada desde snapshots en caché (sin contactos individuales).
     *
     * @return array<string, mixed>
     */
    public function contactIntelligence(): array
    {
        $buckets = [
            ContactLeadScoreSummary::CLASS_EXCELLENT => 0,
            ContactLeadScoreSummary::CLASS_GOOD => 0,
            ContactLeadScoreSummary::CLASS_RISK => 0,
            ContactLeadScoreSummary::CLASS_CRITICAL => 0,
        ];

        $owners = [];
        $withoutOwner = 0;
        $lists = [];
        $sampled = 0;

        $customerIds = Customer::query()
            ->whereNotNull('ac_contact_id')
            ->orderByDesc('ac_last_sync_at')
            ->limit(100)
            ->pluck('id');

        foreach ($customerIds as $customerId) {
            $snapshot = $this->cache->getSnapshot((int) $customerId);
            if ($snapshot === null) {
                continue;
            }

            $sampled++;
            $classification = $snapshot->leadScoreSummary()->classification;
            if (isset($buckets[$classification])) {
                $buckets[$classification]++;
            }

            if ($snapshot->owner === null) {
                $withoutOwner++;
            } else {
                $key = $snapshot->owner->email ?: ($snapshot->owner->name ?: ('#'.$snapshot->owner->id));
                $owners[$key] = ($owners[$key] ?? 0) + 1;
            }

            foreach ($snapshot->lists as $list) {
                $listName = $list->name ?: ('Lista #'.$list->listId);
                $lists[$listName] = ($lists[$listName] ?? 0) + 1;
            }
        }

        arsort($owners);
        arsort($lists);

        $ownerRows = [];
        foreach (array_slice($owners, 0, 8, true) as $name => $count) {
            $ownerRows[] = ['name' => $name, 'count' => $count];
        }

        $listRows = [];
        foreach (array_slice($lists, 0, 8, true) as $name => $count) {
            $listRows[] = ['name' => $name, 'count' => $count];
        }

        // Catálogo de listas conocidas (nombres) si no hay muestras en caché
        if ($listRows === [] && $this->activeCampaign->enabled()) {
            try {
                foreach (array_slice($this->activeCampaign->getLists(), 0, 8) as $list) {
                    $listRows[] = [
                        'name' => (string) ($list['name'] ?? ('Lista #'.($list['id'] ?? '?'))),
                        'count' => null,
                    ];
                }
            } catch (\Throwable) {
                // ignore
            }
        }

        return [
            'sample_size' => $sampled,
            'source' => $sampled > 0 ? 'mirror_cache' : 'empty',
            'lead_score' => [
                'excellent' => $buckets[ContactLeadScoreSummary::CLASS_EXCELLENT],
                'good' => $buckets[ContactLeadScoreSummary::CLASS_GOOD],
                'risk' => $buckets[ContactLeadScoreSummary::CLASS_RISK],
                'critical' => $buckets[ContactLeadScoreSummary::CLASS_CRITICAL],
                'labels' => [
                    'excellent' => ContactLeadScoreSummary::CLASS_EXCELLENT,
                    'good' => ContactLeadScoreSummary::CLASS_GOOD,
                    'risk' => ContactLeadScoreSummary::CLASS_RISK,
                    'critical' => ContactLeadScoreSummary::CLASS_CRITICAL,
                ],
            ],
            'owners' => [
                'distribution' => $ownerRows,
                'without_owner' => $withoutOwner,
            ],
            'lists' => [
                'distribution' => $listRows,
            ],
            'note' => $sampled > 0
                ? "Agregado desde {$sampled} snapshots en caché del Mirror."
                : 'Sin snapshots en caché. Abre contactos en el Drawer o usa «Releer Snapshot» para poblar estadísticas.',
        ];
    }

    /**
     * Feed de actividad desde dispatches (proxy operacional).
     *
     * @return list<array<string, mixed>>
     */
    public function activity(int $limit = 40): array
    {
        $rows = ActiveCampaignDispatch::query()
            ->latest('id')
            ->limit($limit)
            ->get([
                'id', 'event_type', 'status', 'email', 'last_error',
                'attempts', 'created_at', 'synced_at', 'updated_at',
            ]);

        $feed = [];
        foreach ($rows as $row) {
            $feed[] = [
                'id' => $row->id,
                'at' => $row->created_at?->timezone('America/Monterrey')->format('H:i:s'),
                'at_full' => $row->created_at?->timezone('America/Monterrey')->format('d/m/Y H:i:s'),
                'at_iso' => $row->created_at?->toIso8601String(),
                'type' => $row->event_type,
                'type_label' => $this->eventTypeLabel((string) $row->event_type),
                'description' => $this->eventDescription($row),
                'status' => $row->status,
                'status_label' => $this->statusLabel((string) $row->status),
                'icon' => $this->eventIcon((string) $row->event_type, (string) $row->status),
                'email' => $row->email,
            ];
        }

        $meta = $this->opsMeta();
        if (! empty($meta['last_api_test'])) {
            array_unshift($feed, [
                'id' => 'ops-api-test',
                'at' => Carbon::parse($meta['last_api_test']['at'])->timezone('America/Monterrey')->format('H:i:s'),
                'at_full' => Carbon::parse($meta['last_api_test']['at'])->timezone('America/Monterrey')->format('d/m/Y H:i:s'),
                'at_iso' => $meta['last_api_test']['at'],
                'type' => 'api_test',
                'type_label' => 'Test API',
                'description' => ($meta['last_api_test']['ok'] ?? false)
                    ? 'Prueba de API exitosa ('.$meta['last_api_test']['response_ms'].' ms)'
                    : 'Prueba de API fallida: '.($meta['last_api_test']['error'] ?? 'error'),
                'status' => ($meta['last_api_test']['ok'] ?? false) ? 'synced' : 'failed',
                'status_label' => ($meta['last_api_test']['ok'] ?? false) ? 'OK' : 'Error',
                'icon' => ($meta['last_api_test']['ok'] ?? false) ? 'check' : 'error',
                'email' => null,
            ]);
        }

        usort($feed, static fn ($a, $b) => strcmp((string) ($b['at_iso'] ?? ''), (string) ($a['at_iso'] ?? '')));

        return array_values(array_slice($feed, 0, $limit));
    }

    /**
     * Acciones y últimos resultados de diagnóstico.
     *
     * @return array<string, mixed>
     */
    public function diagnostics(): array
    {
        $meta = $this->opsMeta();

        return [
            'actions' => [
                ['key' => 'test_api', 'label' => 'Test API', 'description' => 'Verifica conectividad con ActiveCampaign API v3.'],
                ['key' => 'search_contact', 'label' => 'Buscar contacto', 'description' => 'Lookup por email vía ActiveCampaignService.', 'needs' => 'email'],
                ['key' => 'reread_snapshot', 'label' => 'Releer snapshot', 'description' => 'Fuerza MirrorService::snapshot($customer).', 'needs' => 'email'],
                ['key' => 'invalidate_cache', 'label' => 'Invalidar cache', 'description' => 'MirrorService::invalidate($customer).', 'needs' => 'email'],
                ['key' => 'last_payload', 'label' => 'Ver último payload', 'description' => 'Último dispatch sincronizado (sanitizado).'],
                ['key' => 'last_response', 'label' => 'Ver última respuesta', 'description' => 'Resultado del último Test API / diagnóstico.'],
                ['key' => 'test_webhook', 'label' => 'Test webhook', 'description' => 'Webhooks inbound aún no instrumentados.'],
                ['key' => 'test_lead_score', 'label' => 'Test lead score', 'description' => 'Lee scoreValues del snapshot Mirror.', 'needs' => 'email'],
                ['key' => 'test_owner', 'label' => 'Test owner', 'description' => 'Resuelve owner desde el snapshot.', 'needs' => 'email'],
                ['key' => 'test_engagement', 'label' => 'Test engagement', 'description' => 'Resuelve engagement desde el snapshot.', 'needs' => 'email'],
            ],
            'last_result' => $meta['last_diagnostic'] ?? null,
            'last_api_test' => $meta['last_api_test'] ?? null,
            'last_payload' => $meta['last_payload'] ?? null,
        ];
    }

    /**
     * Ejecuta una acción de diagnóstico reutilizando servicios existentes.
     *
     * @return array<string, mixed>
     */
    public function runDiagnostic(string $action, ?string $email = null): array
    {
        return match ($action) {
            'test_api' => $this->runTestApi(),
            'search_contact' => $this->runSearchContact($email),
            'reread_snapshot' => $this->runRereadSnapshot($email),
            'invalidate_cache' => $this->runInvalidateCache($email),
            'last_payload' => $this->runLastPayload(),
            'last_response' => $this->runLastResponse(),
            'test_webhook' => $this->diagnosticResult('test_webhook', false, 'Webhooks inbound no instrumentados aún.', [
                'available' => false,
            ]),
            'test_lead_score' => $this->runSnapshotProbe($email, 'lead_score'),
            'test_owner' => $this->runSnapshotProbe($email, 'owner'),
            'test_engagement' => $this->runSnapshotProbe($email, 'engagement'),
            default => $this->diagnosticResult($action, false, 'Acción desconocida.'),
        };
    }

    /**
     * @return array<string, mixed>
     */
    public function runTestApi(): array
    {
        if (! $this->activeCampaign->enabled()) {
            $result = $this->diagnosticResult('test_api', false, 'ActiveCampaign está deshabilitado en configuración.');
            $this->storeApiTest(false, null, 'disabled', self::UNAVAILABLE);

            return $result;
        }

        $started = hrtime(true);

        try {
            // Llamada ligera reutilizando el cliente HTTP existente (catálogo fields, 1 página).
            $fields = $this->activeCampaign->getFields();
            $ms = (int) round((hrtime(true) - $started) / 1e6);
            $ok = is_array($fields);
            $count = is_array($fields['fields'] ?? null)
                ? count($fields['fields'])
                : (is_array($fields) ? count($fields) : 0);

            $this->storeApiTest($ok, $ms, null, self::UNAVAILABLE);

            return $this->diagnosticResult('test_api', $ok, $ok
                ? "API respondió en {$ms} ms ({$count} fields)."
                : 'La API no devolvió una respuesta válida.', [
                    'response_ms' => $ms,
                    'fields_count' => $count,
                ]);
        } catch (\Throwable $e) {
            $ms = (int) round((hrtime(true) - $started) / 1e6);
            $this->storeApiTest(false, $ms, $e->getMessage(), self::UNAVAILABLE);
            Log::warning('AC Ops: test_api falló', ['error' => $e->getMessage()]);

            return $this->diagnosticResult('test_api', false, $e->getMessage(), [
                'response_ms' => $ms,
            ]);
        }
    }

    /**
     * @return array<string, mixed>
     */
    protected function runSearchContact(?string $email): array
    {
        $email = $this->normalizeEmail($email);
        if ($email === null) {
            return $this->diagnosticResult('search_contact', false, 'Indica un email válido.');
        }

        try {
            $contact = $this->activeCampaign->findContactByEmail($email);
            if ($contact === null) {
                return $this->diagnosticResult('search_contact', false, "No se encontró contacto AC para {$email}.");
            }

            return $this->diagnosticResult('search_contact', true, 'Contacto encontrado en ActiveCampaign.', [
                'ac_contact_id' => (int) ($contact['id'] ?? 0),
                'email' => $contact['email'] ?? $email,
                'first_name' => $contact['firstName'] ?? null,
                'last_name' => $contact['lastName'] ?? null,
            ]);
        } catch (\Throwable $e) {
            return $this->diagnosticResult('search_contact', false, $e->getMessage());
        }
    }

    /**
     * @return array<string, mixed>
     */
    protected function runRereadSnapshot(?string $email): array
    {
        $customer = $this->resolveCustomerByEmail($email);
        if ($customer === null) {
            return $this->diagnosticResult('reread_snapshot', false, 'No hay customer Famedic con ese email.');
        }

        try {
            $snapshot = $this->mirror->snapshot($customer, forceRefresh: true);
            if ($snapshot === null) {
                return $this->diagnosticResult('reread_snapshot', false, 'Mirror no pudo obtener snapshot (contacto ausente en AC o sync deshabilitado).');
            }

            $payload = [
                'ac_contact_id' => $snapshot->acContactId,
                'lead_score' => $snapshot->leadScoreTotal(),
                'tags' => count($snapshot->tags),
                'lists' => count($snapshot->lists),
                'automations' => count($snapshot->automations),
                'activities' => count($snapshot->activities),
            ];
            $this->putOpsMeta(['last_diagnostic' => [
                'action' => 'reread_snapshot',
                'ok' => true,
                'message' => 'Snapshot releído.',
                'data' => $payload,
                'at' => now()->toIso8601String(),
            ]]);

            return $this->diagnosticResult('reread_snapshot', true, 'Snapshot actualizado vía MirrorService.', $payload);
        } catch (\Throwable $e) {
            return $this->diagnosticResult('reread_snapshot', false, $e->getMessage());
        }
    }

    /**
     * @return array<string, mixed>
     */
    protected function runInvalidateCache(?string $email): array
    {
        $customer = $this->resolveCustomerByEmail($email);
        if ($customer === null) {
            return $this->diagnosticResult('invalidate_cache', false, 'No hay customer Famedic con ese email.');
        }

        try {
            $this->mirror->invalidate($customer);
            $this->putOpsMeta([
                'last_invalidation' => [
                    'at' => now()->toIso8601String(),
                    'at_human' => now()->timezone('America/Monterrey')->format('d/m/Y H:i'),
                    'customer_id' => $customer->id,
                    'email' => $email,
                ],
            ]);

            return $this->diagnosticResult('invalidate_cache', true, 'Caché del Mirror invalidada para el customer.', [
                'customer_id' => $customer->id,
            ]);
        } catch (\Throwable $e) {
            return $this->diagnosticResult('invalidate_cache', false, $e->getMessage());
        }
    }

    /**
     * @return array<string, mixed>
     */
    protected function runLastPayload(): array
    {
        $dispatch = ActiveCampaignDispatch::query()
            ->where('status', ActiveCampaignDispatch::STATUS_SYNCED)
            ->latest('synced_at')
            ->first();

        if (! $dispatch) {
            return $this->diagnosticResult('last_payload', false, 'No hay dispatches sincronizados.');
        }

        $payload = is_array($dispatch->payload) ? $dispatch->payload : [];
        // Sanitizar claves sensibles básicas
        unset($payload['validation_token'], $payload['cart_hash'], $payload['api_token']);

        $data = [
            'dispatch_id' => $dispatch->id,
            'event_type' => $dispatch->event_type,
            'email' => $dispatch->email,
            'synced_at' => $dispatch->synced_at?->toIso8601String(),
            'payload' => $payload,
        ];

        $this->putOpsMeta(['last_payload' => $data]);

        return $this->diagnosticResult('last_payload', true, 'Último payload sincronizado.', $data);
    }

    /**
     * @return array<string, mixed>
     */
    protected function runLastResponse(): array
    {
        $meta = $this->opsMeta();
        $last = $meta['last_api_test'] ?? $meta['last_diagnostic'] ?? null;
        if ($last === null) {
            return $this->diagnosticResult('last_response', false, 'Aún no hay respuestas de diagnóstico.');
        }

        return $this->diagnosticResult('last_response', true, 'Última respuesta registrada.', is_array($last) ? $last : []);
    }

    /**
     * @return array<string, mixed>
     */
    protected function runSnapshotProbe(?string $email, string $focus): array
    {
        $customer = $this->resolveCustomerByEmail($email);
        if ($customer === null) {
            return $this->diagnosticResult("test_{$focus}", false, 'No hay customer Famedic con ese email.');
        }

        try {
            $snapshot = $this->mirror->snapshot($customer);
            if ($snapshot === null) {
                return $this->diagnosticResult("test_{$focus}", false, 'Sin snapshot disponible.');
            }

            $data = match ($focus) {
                'lead_score' => $snapshot->leadScoreSummary()->toArray(),
                'owner' => $snapshot->owner?->toArray() ?? ['owner' => null],
                'engagement' => ($snapshot->engagement ?? ContactEngagementData::unavailable())->toArray(),
                default => [],
            };

            return $this->diagnosticResult("test_{$focus}", true, "Probe {$focus} OK.", $data);
        } catch (\Throwable $e) {
            return $this->diagnosticResult("test_{$focus}", false, $e->getMessage());
        }
    }

    protected function resolveCustomerByEmail(?string $email): ?Customer
    {
        $email = $this->normalizeEmail($email);
        if ($email === null) {
            return null;
        }

        $user = User::query()->where('email', $email)->first();
        if (! $user) {
            return null;
        }

        return Customer::query()->where('user_id', $user->id)->first();
    }

    protected function normalizeEmail(?string $email): ?string
    {
        $email = strtolower(trim((string) $email));

        return $email !== '' && filter_var($email, FILTER_VALIDATE_EMAIL) ? $email : null;
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected function diagnosticResult(string $action, bool $ok, string $message, array $data = []): array
    {
        $result = [
            'action' => $action,
            'ok' => $ok,
            'message' => $message,
            'data' => $data,
            'at' => now()->toIso8601String(),
            'at_human' => now()->timezone('America/Monterrey')->format('d/m/Y H:i:s'),
        ];

        $this->putOpsMeta(['last_diagnostic' => $result]);

        return $result;
    }

    protected function storeApiTest(bool $ok, ?int $ms, ?string $error, string $rateLimit): void
    {
        $this->putOpsMeta([
            'last_api_test' => [
                'ok' => $ok,
                'response_ms' => $ms,
                'error' => $error,
                'rate_limit' => $rateLimit,
                'at' => now()->toIso8601String(),
                'at_human' => now()->timezone('America/Monterrey')->diffForHumans(),
            ],
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    protected function opsMeta(): array
    {
        $value = Cache::get(self::OPS_META_KEY);

        return is_array($value) ? $value : [];
    }

    /**
     * @param  array<string, mixed>  $patch
     */
    protected function putOpsMeta(array $patch): void
    {
        Cache::put(self::OPS_META_KEY, array_merge($this->opsMeta(), $patch), now()->addDays(7));
    }

    /**
     * @param  array<string, int|string>  $byType
     * @param  list<string>  $keys
     */
    protected function sumEventTypes(array $byType, array $keys): int
    {
        $total = 0;
        foreach ($keys as $key) {
            $total += (int) ($byType[$key] ?? 0);
        }

        return $total;
    }

    protected function resolveEnvironment(string $endpoint): string
    {
        $host = $this->endpointHost($endpoint);
        if ($host === self::UNAVAILABLE || $host === '') {
            return self::UNAVAILABLE;
        }

        if (str_contains($host, 'test') || str_contains($host, 'sandbox') || str_contains($host, 'staging')) {
            return 'sandbox';
        }

        return app()->environment('production') ? 'production' : app()->environment();
    }

    protected function endpointHost(string $endpoint): string
    {
        if ($endpoint === '') {
            return self::UNAVAILABLE;
        }

        $host = parse_url($endpoint, PHP_URL_HOST);

        return is_string($host) && $host !== '' ? $host : self::UNAVAILABLE;
    }

    protected function eventTypeLabel(string $type): string
    {
        return match (true) {
            str_contains($type, 'purchase') || str_contains($type, 'order') => 'Compra / pedido',
            str_contains($type, 'registration') || str_contains($type, 'patient') => 'Contacto sincronizado',
            str_contains($type, 'tag') => 'Tag',
            str_contains($type, 'membership') => 'Membresía',
            str_contains($type, 'credit') || str_contains($type, 'promo') => 'Cupón / promo',
            str_contains($type, 'result') || str_contains($type, 'sample') => 'Laboratorio',
            str_contains($type, 'automation') => 'Automatización',
            default => $type !== '' ? $type : 'Evento',
        };
    }

    protected function eventDescription(ActiveCampaignDispatch $row): string
    {
        $label = $this->eventTypeLabel((string) $row->event_type);
        $email = $row->email ? " · {$row->email}" : '';

        if ($row->status === ActiveCampaignDispatch::STATUS_FAILED && $row->last_error) {
            return $label.$email.' — '.\Illuminate\Support\Str::limit((string) $row->last_error, 80);
        }

        return $label.$email;
    }

    protected function statusLabel(string $status): string
    {
        return match ($status) {
            ActiveCampaignDispatch::STATUS_SYNCED => 'Sincronizado',
            ActiveCampaignDispatch::STATUS_FAILED => 'Error',
            ActiveCampaignDispatch::STATUS_PENDING => 'Pendiente',
            ActiveCampaignDispatch::STATUS_PROCESSING => 'Procesando',
            ActiveCampaignDispatch::STATUS_SKIPPED => 'Omitido',
            default => $status,
        };
    }

    protected function eventIcon(string $type, string $status): string
    {
        if ($status === ActiveCampaignDispatch::STATUS_FAILED) {
            return 'error';
        }

        return match (true) {
            str_contains($type, 'purchase') || str_contains($type, 'order') => 'cart',
            str_contains($type, 'tag') => 'tag',
            str_contains($type, 'membership') => 'membership',
            str_contains($type, 'registration') => 'user',
            str_contains($type, 'automation') => 'bolt',
            default => 'activity',
        };
    }
}
