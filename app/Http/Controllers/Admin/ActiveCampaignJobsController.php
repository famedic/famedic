<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\ActiveCampaignDispatch;
use App\Services\ActiveCampaign\ActiveCampaignDispatchService;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Inertia\Inertia;
use Inertia\Response;
use Spatie\Permission\Exceptions\PermissionDoesNotExist;

class ActiveCampaignJobsController extends Controller
{
    public function index(Request $request, ActiveCampaignDispatchService $dispatchService): Response
    {
        $administrator = $request->user()->administrator;
        $canViewActiveCampaign = $this->hasPermission($administrator, 'activecampaign.manage');
        $canViewGeneralLogs = $this->hasPermission($administrator, 'logs-general.manage');

        ($canViewActiveCampaign || $canViewGeneralLogs) || abort(403);

        $filters = $this->filters($request);

        if (! Schema::hasTable('activecampaign_dispatches')) {
            return Inertia::render('Admin/ActiveCampaignJobs', [
                'dispatches' => [
                    'data' => [],
                    'links' => [],
                    'meta' => ['current_page' => 1, 'last_page' => 1, 'total' => 0],
                ],
                'filters' => $filters,
                'filterOptions' => $this->emptyFilterOptions(),
                'stats' => $this->emptyStats(),
                'selectedDispatch' => null,
                'tableExists' => false,
                'glossary' => $this->glossary(),
                'links' => $this->links($canViewActiveCampaign),
            ]);
        }

        $baseQuery = $this->filteredQuery($filters);

        $dispatches = (clone $baseQuery)
            ->orderByDesc('updated_at')
            ->orderByDesc('id')
            ->paginate(25)
            ->withQueryString()
            ->through(fn (ActiveCampaignDispatch $dispatch): array => $this->presentDispatch($dispatch));

        $selectedDispatch = null;
        if ($request->filled('dispatch_id')) {
            $selected = ActiveCampaignDispatch::query()->find($request->integer('dispatch_id'));
            $selectedDispatch = $selected ? $this->presentDispatch($selected, true, $dispatchService) : null;
        }

        return Inertia::render('Admin/ActiveCampaignJobs', [
            'dispatches' => $dispatches,
            'filters' => $filters,
            'filterOptions' => $this->filterOptions(),
            'stats' => $this->stats(),
            'selectedDispatch' => $selectedDispatch,
            'tableExists' => true,
            'glossary' => $this->glossary(),
            'links' => $this->links($canViewActiveCampaign),
        ]);
    }

    /**
     * @return array<string, string|null>
     */
    private function filters(Request $request): array
    {
        return [
            'q' => trim((string) $request->query('q', '')),
            'status' => trim((string) $request->query('status', '')),
            'operation' => trim((string) $request->query('operation', '')),
            'event_type' => trim((string) $request->query('event_type', '')),
            'from' => $this->dateFilter($request->query('from')),
            'to' => $this->dateFilter($request->query('to')),
            'dispatch_id' => $request->query('dispatch_id') ? (string) $request->query('dispatch_id') : null,
        ];
    }

    private function filteredQuery(array $filters)
    {
        return ActiveCampaignDispatch::query()
            ->when($filters['q'] !== '', function ($query) use ($filters) {
                $search = '%'.$filters['q'].'%';

                $query->where(function ($query) use ($search) {
                    $query->where('event_type', 'like', $search)
                        ->orWhere('entity_type', 'like', $search)
                        ->orWhere('idempotency_key', 'like', $search)
                        ->orWhere('email', 'like', $search)
                        ->orWhere('last_error', 'like', $search)
                        ->orWhere('payload', 'like', $search);
                });
            })
            ->when($filters['status'] !== '', fn ($query) => $query->where('status', $filters['status']))
            ->when($filters['operation'] !== '', fn ($query) => $query->where('payload->operation', $filters['operation']))
            ->when($filters['event_type'] !== '', fn ($query) => $query->where('event_type', $filters['event_type']))
            ->when($filters['from'] !== '', fn ($query) => $query->where('created_at', '>=', Carbon::parse($filters['from'])->startOfDay()))
            ->when($filters['to'] !== '', fn ($query) => $query->where('created_at', '<=', Carbon::parse($filters['to'])->endOfDay()));
    }

    private function dateFilter(mixed $value): string
    {
        $value = trim((string) $value);

        return preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) ? $value : '';
    }

    /**
     * @return array<string, mixed>
     */
    private function presentDispatch(
        ActiveCampaignDispatch $dispatch,
        bool $detail = false,
        ?ActiveCampaignDispatchService $dispatchService = null
    ): array {
        $payload = is_array($dispatch->payload) ? $dispatch->payload : [];

        $row = [
            'id' => $dispatch->id,
            'event_type' => $dispatch->event_type,
            'operation' => $payload['operation'] ?? null,
            'entity_type' => $dispatch->entity_type,
            'entity_id' => $dispatch->entity_id,
            'related_entity_type' => $dispatch->related_entity_type,
            'related_entity_id' => $dispatch->related_entity_id,
            'user_id' => $dispatch->user_id,
            'customer_id' => $dispatch->customer_id,
            'email' => $this->redactEmail($dispatch->email),
            'status' => $dispatch->status,
            'status_label' => $this->statusLabel($dispatch->status),
            'attempts' => $dispatch->attempts,
            'last_error' => $this->shortText($dispatch->last_error, 220),
            'idempotency_key' => $dispatch->idempotency_key,
            'created_at' => $dispatch->created_at?->toIso8601String(),
            'updated_at' => $dispatch->updated_at?->toIso8601String(),
            'synced_at' => $dispatch->synced_at?->toIso8601String(),
        ];

        if (! $detail) {
            return $row;
        }

        $safePayload = $dispatchService
            ? $dispatchService->sanitizePayloadForLog($payload)
            : $payload;

        return array_merge($row, [
            'payload' => json_encode($safePayload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?: '{}',
            'last_error_full' => $this->shortText($dispatch->last_error, 6000),
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function stats(): array
    {
        $now = now();

        return [
            'total' => ActiveCampaignDispatch::query()->count(),
            'today' => ActiveCampaignDispatch::query()->where('created_at', '>=', $now->copy()->startOfDay())->count(),
            'last_24_hours' => ActiveCampaignDispatch::query()->where('created_at', '>=', $now->copy()->subDay())->count(),
            'synced' => ActiveCampaignDispatch::query()->where('status', ActiveCampaignDispatch::STATUS_SYNCED)->count(),
            'failed' => ActiveCampaignDispatch::query()->where('status', ActiveCampaignDispatch::STATUS_FAILED)->count(),
            'pending' => ActiveCampaignDispatch::query()->whereIn('status', [
                ActiveCampaignDispatch::STATUS_PENDING,
                ActiveCampaignDispatch::STATUS_PROCESSING,
            ])->count(),
            'skipped' => ActiveCampaignDispatch::query()->where('status', ActiveCampaignDispatch::STATUS_SKIPPED)->count(),
            'latest_synced_at' => ActiveCampaignDispatch::query()->whereNotNull('synced_at')->max('synced_at'),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function emptyStats(): array
    {
        return [
            'total' => 0,
            'today' => 0,
            'last_24_hours' => 0,
            'synced' => 0,
            'failed' => 0,
            'pending' => 0,
            'skipped' => 0,
            'latest_synced_at' => null,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function filterOptions(): array
    {
        return [
            'statuses' => ActiveCampaignDispatch::STATUSES,
            'operations' => ActiveCampaignDispatch::query()
                ->whereNotNull('payload')
                ->latest('id')
                ->limit(1000)
                ->pluck('payload')
                ->map(function (mixed $payload) {
                    if (is_string($payload)) {
                        $decoded = json_decode($payload, true);
                        $payload = is_array($decoded) ? $decoded : [];
                    }

                    return is_array($payload) ? ($payload['operation'] ?? null) : null;
                })
                ->filter(fn (mixed $operation) => is_string($operation) && $operation !== '')
                ->unique()
                ->sort()
                ->values(),
            'event_types' => ActiveCampaignDispatch::query()
                ->select('event_type')
                ->distinct()
                ->orderBy('event_type')
                ->pluck('event_type')
                ->filter()
                ->values(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function emptyFilterOptions(): array
    {
        return [
            'statuses' => ActiveCampaignDispatch::STATUSES,
            'operations' => [],
            'event_types' => [],
        ];
    }

    /**
     * @return array<string, string|null>
     */
    private function links(bool $canViewActiveCampaign): array
    {
        return [
            'activecampaign_logs' => $canViewActiveCampaign ? route('admin.activecampaign.logs') : null,
            'failed_jobs' => route('admin.failed-jobs.index'),
        ];
    }

    /**
     * @return array<string, list<array<string, mixed>>>
     */
    private function glossary(): array
    {
        return [
            'tracked' => [
                [
                    'job' => 'DispatchActiveCampaignOutboundJob',
                    'coverage' => 'Carritos, citas, llamadas, site events, campos de laboratorio y compra de laboratorio completada.',
                    'records' => 'activecampaign_dispatches + laravel.log + failed_jobs si agota reintentos.',
                    'source' => 'ActiveCampaignOutboundDispatcher',
                ],
                [
                    'job' => 'DispatchActiveCampaignCouponEventJob',
                    'coverage' => 'Créditos, cupones, beneficiarios pendientes y promos.',
                    'records' => 'activecampaign_dispatches + laravel.log + failed_jobs si agota reintentos.',
                    'source' => 'CouponActiveCampaignDispatcher',
                ],
                [
                    'job' => 'AutomationExecutionJob / ActiveCampaignOrderDriver',
                    'coverage' => 'Automatizaciones de órdenes completadas. Laboratorio crea dispatch outbox; farmacia y membresías registran automation_runs.',
                    'records' => 'automation_runs, automation_operation_events y, para laboratorio, activecampaign_dispatches.',
                    'source' => 'OrderAutomationDispatcher',
                ],
            ],
            'legacy' => [
                [
                    'job' => 'SendCartAbandonedToActiveCampaignJob',
                    'coverage' => 'Flujo legacy de carrito abandonado cuando el outbox está desactivado.',
                    'records' => 'laravel.log, customers.cart_abandoned_tagged_at y failed_jobs si falla definitivamente.',
                    'gap' => 'No crea fila propia en activecampaign_dispatches.',
                ],
                [
                    'job' => 'SendContactToActiveCampaignJob / SendPatient*',
                    'coverage' => 'Contactos y pacientes enviados directo a ActiveCampaign.',
                    'records' => 'Algunos Log::info/error y failed_jobs si falla definitivamente.',
                    'gap' => 'No hay bitácora estructurada por interacción.',
                ],
                [
                    'job' => 'SendLaboratoryPurchaseToActiveCampaignJob / SendOnlinePharmacyPurchaseToActiveCampaignJob',
                    'coverage' => 'Compras enviadas directo por observers legacy.',
                    'records' => 'failed_jobs si falla definitivamente; logs dependen del servicio llamado.',
                    'gap' => 'No crea dispatch estructurado salvo cuando entra por el flujo nuevo.',
                ],
                [
                    'job' => 'SendMembershipActivated/EndedToActiveCampaignJob',
                    'coverage' => 'Activación o finalización de membresías por observer legacy.',
                    'records' => 'failed_jobs si falla definitivamente; logs dependen del servicio llamado.',
                    'gap' => 'No crea fila propia en activecampaign_dispatches.',
                ],
                [
                    'job' => 'TagLaboratoryEmailToActiveCampaignJob / SendSampleCollected / SendResultsAvailable',
                    'coverage' => 'Etiquetas de toma de muestra y resultados disponibles.',
                    'records' => 'laravel.log y failed_jobs si falla definitivamente.',
                    'gap' => 'El job de tag no guarda dispatch estructurado.',
                ],
                [
                    'job' => 'SendInvoiceAvailableToActiveCampaignJob',
                    'coverage' => 'Factura disponible enviada directo a ActiveCampaign.',
                    'records' => 'failed_jobs si falla definitivamente.',
                    'gap' => 'Sin fila estructurada local para éxito/omisión.',
                ],
            ],
        ];
    }

    private function statusLabel(string $status): string
    {
        return match ($status) {
            ActiveCampaignDispatch::STATUS_PENDING => 'Pendiente',
            ActiveCampaignDispatch::STATUS_PROCESSING => 'Procesando',
            ActiveCampaignDispatch::STATUS_SYNCED => 'Sincronizado',
            ActiveCampaignDispatch::STATUS_FAILED => 'Error',
            ActiveCampaignDispatch::STATUS_SKIPPED => 'Omitido',
            default => Str::headline($status),
        };
    }

    private function shortText(?string $value, int $limit): ?string
    {
        if ($value === null || trim($value) === '') {
            return null;
        }

        return Str::limit($value, $limit);
    }

    private function redactEmail(?string $email): ?string
    {
        if (! $email || ! str_contains($email, '@')) {
            return $email;
        }

        [$local, $domain] = explode('@', $email, 2);

        return Str::substr($local, 0, 1).'***@'.$domain;
    }

    private function hasPermission(mixed $administrator, string $permission): bool
    {
        try {
            return $administrator->hasPermissionTo($permission);
        } catch (PermissionDoesNotExist) {
            return false;
        }
    }
}
