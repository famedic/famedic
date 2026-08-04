<?php

namespace App\Services\ActiveCampaign;

use App\Models\ActiveCampaignDispatch;
use App\Models\Contact;
use App\Models\Customer;
use App\Models\Invoice;
use App\Models\LaboratoryPurchase;
use App\Models\OnlinePharmacyPurchase;
use App\Support\ActiveCampaign\ActiveCampaignDashboardFilter;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;

/**
 * Event Center — consola operativa de eventos.
 * No crea modelo nuevo: reutiliza Timeline, Dispatches, Dashboard y señales de Journey.
 */
class ActiveCampaignEventCenterService
{
    private const TZ = 'America/Monterrey';

    private const UNAVAILABLE = 'No disponible';

    private const LIST_LIMIT = 60;

    private const PER_SOURCE_LIMIT = 20;

    private ActiveCampaignDashboardService $dashboard;

    private ActiveCampaignContactTimelineService $timeline;

    private ActiveCampaignDispatchService $dispatchService;

    /** @var list<string> */
    private const PAYLOAD_ALLOWLIST = [
        'event_type',
        'entity_type',
        'entity_id',
        'related_entity_type',
        'related_entity_id',
        'amount_cents',
        'currency',
        'coupon_id',
        'source',
        'status',
        'brand',
        'type',
    ];

    public function __construct(
        ActiveCampaignDashboardService $dashboard,
        ActiveCampaignContactTimelineService $timeline,
        ActiveCampaignDispatchService $dispatchService,
    ) {
        $this->dashboard = $dashboard;
        $this->timeline = $timeline;
        $this->dispatchService = $dispatchService;
    }

    /**
     * Payload inmediato (sin lista pesada ni payloads).
     *
     * @return array<string, mixed>
     */
    public function buildCore(Request $request): array
    {
        $filters = $this->resolveFilters($request);
        $dashFilter = ActiveCampaignDashboardFilter::fromRequest($request);
        $overview = $this->dashboard->buildOverview($dashFilter);

        return [
            'filters' => $filters,
            'filterOptions' => $this->filterOptions(),
            'summary' => $this->buildSummary($overview),
            'actions' => $this->buildQuickActions($filters),
            'contactOptions' => $this->contactOptionsFor($filters),
            'meta' => [
                'generated_at' => $overview['meta']['generated_at'] ?? now(self::TZ)->format('d/m/Y H:i'),
                'source_of_truth' => 'Timeline + ActiveCampaignDispatch + ActiveCampaignDashboardService',
                'previous_period' => $overview['meta']['previous_period'] ?? null,
            ],
        ];
    }

    /**
     * Lista global / por paciente sin payloads (diferida).
     *
     * @return array{items: list<array<string, mixed>>, total: int, truncated: bool}
     */
    public function buildEvents(Request $request): array
    {
        $filters = $this->resolveFilters($request);
        $dashFilter = ActiveCampaignDashboardFilter::fromRequest($request);

        $contactOnlyTypes = ['membership', 'beneficiary_added', 'coupon_assigned'];
        if (
            ! $filters['contact_id']
            && in_array($filters['type'], $contactOnlyTypes, true)
        ) {
            return [
                'items' => [],
                'total' => 0,
                'truncated' => false,
            ];
        }

        $items = $filters['contact_id']
            ? $this->eventsForContact($filters['contact_id'], $filters, $dashFilter)
            : $this->eventsGlobal($filters, $dashFilter);

        $filtered = $this->applyFilters($items, $filters);
        $total = $filtered->count();
        $page = $filtered->take(self::LIST_LIMIT)->values()->all();

        return [
            'items' => $page,
            'total' => $total,
            'truncated' => $total > self::LIST_LIMIT,
        ];
    }

    /**
     * Detalle bajo demanda (incluye payload de dispatch si existe).
     *
     * @return array<string, mixed>|null
     */
    public function buildEventDetail(string $eventId, ?int $contactId = null): ?array
    {
        if ($eventId === '') {
            return null;
        }

        if (str_starts_with($eventId, 'ac-dispatch-')) {
            $dispatchId = (int) str_replace('ac-dispatch-', '', $eventId);
            $dispatch = ActiveCampaignDispatch::query()->find($dispatchId);
            if (! $dispatch) {
                return null;
            }

            $row = $this->mapDispatchEvent($dispatch, $this->contactIdMapForDispatches(collect([$dispatch])));
            $resolvedContactId = $contactId
                ?: ($row['contact_id'] ?? null);

            return [
                ...$row,
                'payload' => $this->sanitizePayloadForUi(
                    is_array($dispatch->payload) ? $dispatch->payload : []
                ),
                'last_error' => $this->sanitizeError($dispatch->last_error),
                'related_model' => [
                    'label' => 'ActiveCampaignDispatch',
                    'entity_type' => $dispatch->entity_type,
                    'entity_id' => $dispatch->entity_id,
                    'related_entity_type' => $dispatch->related_entity_type,
                    'related_entity_id' => $dispatch->related_entity_id,
                ],
                'timeline_note' => 'Mismo evento que aparece en Timeline / Journey del paciente cuando hay match por customer/email.',
                'actions' => $this->detailActions($resolvedContactId, $dispatch->status === ActiveCampaignDispatch::STATUS_FAILED),
            ];
        }

        if ($contactId) {
            $contact = Contact::query()->with(['customer.user:id,email'])->find($contactId);
            if ($contact) {
                $timeline = $this->timeline->buildForContact($contact);
                $event = collect($timeline['events'])->firstWhere('id', $eventId);
                if ($event) {
                    return [
                        ...$this->mapTimelineEvent($event, $contact),
                        'payload' => null,
                        'payload_label' => self::UNAVAILABLE,
                        'last_error' => null,
                        'related_model' => [
                            'label' => $this->modelLabelForType((string) $event['type']),
                            'event_id' => $eventId,
                        ],
                        'timeline_note' => 'Evento de dominio Famedic (fuente Timeline).',
                        'actions' => $this->detailActions($contact->id, false),
                    ];
                }
            }
        }

        $domain = $this->resolveDomainEventDetail($eventId);
        if ($domain) {
            return $domain;
        }

        return null;
    }

    /**
     * @return array<string, mixed>
     */
    private function resolveFilters(Request $request): array
    {
        $dash = ActiveCampaignDashboardFilter::fromRequest($request)->toArray();

        return [
            'search' => trim((string) $request->input('search', '')),
            'type' => trim((string) $request->input('type', '')),
            'origin' => trim((string) $request->input('origin', '')),
            'status' => trim((string) $request->input('status', '')),
            'severity' => trim((string) $request->input('severity', '')),
            'patient' => trim((string) $request->input('patient', '')),
            'contact_id' => $request->integer('contact_id') ?: null,
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
                ['value' => '', 'label' => 'Todos', 'scope' => 'all'],
                ['value' => 'registration', 'label' => 'Registro', 'scope' => 'all'],
                ['value' => 'laboratory_purchase', 'label' => 'Compra laboratorio', 'scope' => 'all'],
                ['value' => 'laboratory_results', 'label' => 'Resultado laboratorio', 'scope' => 'all'],
                ['value' => 'pharmacy_purchase', 'label' => 'Compra farmacia', 'scope' => 'all'],
                ['value' => 'invoice', 'label' => 'Factura', 'scope' => 'all'],
                ['value' => 'membership', 'label' => 'Membresía', 'scope' => 'contact'],
                ['value' => 'beneficiary_added', 'label' => 'Beneficiario', 'scope' => 'contact'],
                ['value' => 'coupon_assigned', 'label' => 'Cupón', 'scope' => 'contact'],
                ['value' => 'activecampaign_dispatch', 'label' => 'Dispatch ActiveCampaign', 'scope' => 'all'],
            ],
            'origins' => [
                ['value' => '', 'label' => 'Todos'],
                ['value' => 'famedic', 'label' => 'Famedic'],
                ['value' => 'activecampaign_local', 'label' => 'Dispatches locales'],
            ],
            'statuses' => [
                ['value' => '', 'label' => 'Todos'],
                ['value' => 'synced', 'label' => 'Sincronizado'],
                ['value' => 'failed', 'label' => 'Error'],
                ['value' => 'pending', 'label' => 'Pendiente'],
                ['value' => 'processing', 'label' => 'Procesando'],
                ['value' => 'completed', 'label' => 'Completado'],
                ['value' => 'ready', 'label' => 'Disponible'],
                ['value' => 'active', 'label' => 'Activa'],
                ['value' => 'cancelled', 'label' => 'Cancelada'],
            ],
            'severities' => [
                ['value' => '', 'label' => 'Todas'],
                ['value' => 'error', 'label' => 'Crítica'],
                ['value' => 'warning', 'label' => 'Advertencia'],
                ['value' => 'info', 'label' => 'Informativa'],
            ],
        ];
    }

    /**
     * @param  array<string, mixed>  $overview
     * @return list<array<string, mixed>>
     */
    private function buildSummary(array $overview): array
    {
        $health = collect($overview['health'])->keyBy('id');
        $business = collect($overview['business'])->keyBy('id');

        $dayStart = Carbon::now(self::TZ)->startOfDay()->utc();
        $dayEnd = Carbon::now(self::TZ)->endOfDay()->utc();
        $eventsToday = ActiveCampaignDispatch::query()
            ->whereBetween('created_at', [$dayStart, $dayEnd])
            ->count();

        return [
            $this->summaryCard('events_today', 'Eventos del día', number_format($eventsToday), 'disponible', 'Dispatches creados hoy (tabla local)', 'sky'),
            $this->summaryCard('errors', 'Errores', (string) ($health->get('errors')['value'] ?? '0'), 'disponible', 'Failed del periodo (Dashboard)', 'red'),
            $this->summaryCard('dispatches', 'Dispatches', (string) ($health->get('backlog')['value'] ?? '0'), 'disponible', 'Pendientes + procesando', 'amber'),
            $this->summaryCard('results', 'Resultados', self::UNAVAILABLE, 'no_disponible', 'Sin agregación global en Dashboard', 'zinc'),
            $this->summaryCard('invoices', 'Facturas', self::UNAVAILABLE, 'no_disponible', 'Sin agregación global en Dashboard', 'zinc'),
            $this->summaryCard(
                'purchases',
                'Compras',
                (string) ($business->get('pharmacy')['value_formatted'] ?? self::UNAVAILABLE),
                'proxy',
                'Farmacia del periodo (proxy Dashboard)',
                'sky',
            ),
            $this->summaryCard(
                'laboratories',
                'Laboratorios',
                (string) ($business->get('lab')['value_formatted'] ?? self::UNAVAILABLE),
                'proxy',
                'Compras lab del periodo (proxy Dashboard)',
                'sky',
            ),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function summaryCard(
        string $id,
        string $label,
        string $value,
        string $truth,
        string $hint,
        string $tone,
    ): array {
        return compact('id', 'label', 'value', 'truth', 'hint', 'tone');
    }

    /**
     * @param  array<string, mixed>  $filters
     * @return list<array<string, mixed>>
     */
    private function buildQuickActions(array $filters): array
    {
        $contactId = $filters['contact_id'];

        return [
            [
                'id' => 'contact',
                'label' => 'Ir a Contacto',
                'enabled' => (bool) $contactId,
                'href' => $contactId
                    ? route('admin.activecampaign.contacts', ['drawer_contact_id' => $contactId])
                    : null,
                'hint' => $contactId ? null : 'Selecciona un paciente o un evento con contacto.',
            ],
            [
                'id' => 'journey',
                'label' => 'Abrir Journey',
                'enabled' => (bool) $contactId,
                'href' => $contactId
                    ? route('admin.activecampaign.customer-journey', ['contact_id' => $contactId])
                    : null,
                'hint' => $contactId ? null : 'Requiere paciente seleccionado.',
            ],
            [
                'id' => 'analytics',
                'label' => 'Abrir Analytics',
                'enabled' => true,
                'href' => route('admin.activecampaign.analytics'),
                'hint' => null,
            ],
            [
                'id' => 'health',
                'label' => 'Abrir Health',
                'enabled' => true,
                'href' => route('admin.activecampaign.health'),
                'hint' => null,
            ],
            [
                'id' => 'retry',
                'label' => 'Reintentar',
                'enabled' => false,
                'href' => null,
                'hint' => 'No hay endpoint de reintento masivo.',
            ],
        ];
    }

    /**
     * @param  array<string, mixed>  $filters
     * @return list<array{id: int, label: string, email: string|null}>
     */
    private function contactOptionsFor(array $filters): array
    {
        $options = $this->searchContacts(
            $filters['patient'] !== '' ? $filters['patient'] : $filters['search']
        );

        if (! $filters['contact_id']) {
            return $options;
        }

        $selectedId = (int) $filters['contact_id'];
        if (collect($options)->contains(fn ($row) => (int) $row['id'] === $selectedId)) {
            return $options;
        }

        $contact = Contact::query()
            ->with(['customer.user:id,email'])
            ->find($selectedId);

        if (! $contact) {
            return $options;
        }

        $name = trim((string) $contact->full_name);
        array_unshift($options, [
            'id' => $contact->id,
            'label' => $name !== '' ? $name : ('Contacto #'.$contact->id),
            'email' => $contact->customer?->user?->email,
        ]);

        return $options;
    }

    /**
     * @return list<array{id: int, label: string, email: string|null}>
     */
    private function searchContacts(string $term): array
    {
        $query = Contact::query()
            ->with(['customer.user:id,email'])
            ->whereNull('deleted_at')
            ->latest('id')
            ->limit(12);

        if ($term !== '') {
            $like = '%'.$term.'%';
            $query->where(function ($q) use ($like) {
                $q->where('name', 'like', $like)
                    ->orWhere('paternal_lastname', 'like', $like)
                    ->orWhere('maternal_lastname', 'like', $like)
                    ->orWhereHas('customer.user', fn ($u) => $u->where('email', 'like', $like));
            });
        }

        return $query->get(['id', 'name', 'paternal_lastname', 'maternal_lastname', 'customer_id'])
            ->map(function (Contact $contact) {
                $name = trim((string) $contact->full_name);

                return [
                    'id' => $contact->id,
                    'label' => $name !== '' ? $name : ('Contacto #'.$contact->id),
                    'email' => $contact->customer?->user?->email,
                ];
            })
            ->all();
    }

    /**
     * @param  array<string, mixed>  $filters
     * @return Collection<int, array<string, mixed>>
     */
    private function eventsForContact(int $contactId, array $filters, ActiveCampaignDashboardFilter $dashFilter): Collection
    {
        $contact = Contact::query()->with(['customer.user:id,email'])->find($contactId);
        if (! $contact) {
            return collect();
        }

        $timeline = $this->timeline->buildForContact(
            $contact,
            $dashFilter->start,
            $dashFilter->end,
        );

        return collect($timeline['events'])
            ->map(fn (array $event) => $this->mapTimelineEvent($event, $contact))
            ->values();
    }

    /**
     * @param  array<string, mixed>  $filters
     * @return Collection<int, array<string, mixed>>
     */
    private function eventsGlobal(array $filters, ActiveCampaignDashboardFilter $dashFilter): Collection
    {
        $events = collect()
            ->merge($this->globalDispatchEvents($dashFilter))
            ->merge($this->globalLabPurchaseEvents($dashFilter))
            ->merge($this->globalLabResultEvents($dashFilter))
            ->merge($this->globalPharmacyEvents($dashFilter))
            ->merge($this->globalInvoiceEvents($dashFilter))
            ->merge($this->globalRegistrationEvents($dashFilter));

        return $events
            ->sortByDesc(fn (array $e) => $e['occurred_at_sort'] ?? 0)
            ->values();
    }

    /**
     * @return Collection<int, array<string, mixed>>
     */
    private function globalDispatchEvents(ActiveCampaignDashboardFilter $filter): Collection
    {
        $rows = ActiveCampaignDispatch::query()
            ->whereBetween('created_at', [$filter->start, $filter->end])
            ->orderByDesc('id')
            ->limit(self::PER_SOURCE_LIMIT)
            ->get();

        $contactMap = $this->contactIdMapForDispatches($rows);

        return $rows->map(fn (ActiveCampaignDispatch $row) => $this->mapDispatchEvent($row, $contactMap));
    }

    /**
     * @return Collection<int, array<string, mixed>>
     */
    private function globalLabPurchaseEvents(ActiveCampaignDashboardFilter $filter): Collection
    {
        return LaboratoryPurchase::query()
            ->withTrashed()
            ->with(['customer.contacts:id,customer_id,name,paternal_lastname,maternal_lastname', 'customer.user:id,email'])
            ->whereBetween('created_at', [$filter->start, $filter->end])
            ->latest('id')
            ->limit(self::PER_SOURCE_LIMIT)
            ->get(['id', 'customer_id', 'brand', 'created_at', 'total_cents', 'deleted_at'])
            ->map(function (LaboratoryPurchase $purchase) {
                $brand = $purchase->brand?->label() ?: 'Laboratorio';
                $trashed = $purchase->trashed();
                $patient = $this->patientFromCustomer($purchase->customer);

                return $this->row(
                    id: 'lab-purchase-'.$purchase->id,
                    at: $purchase->created_at,
                    type: 'laboratory_purchase',
                    typeLabel: 'Compra laboratorio',
                    source: 'famedic',
                    sourceLabel: 'Famedic · Laboratorio',
                    description: "Compra {$brand}.",
                    status: $trashed ? 'cancelled' : 'completed',
                    statusLabel: $trashed ? 'Cancelada' : 'Completada',
                    badge: 'Lab',
                    color: $trashed ? 'zinc' : 'blue',
                    patient: $patient['label'],
                    patientEmail: $patient['email'],
                    contactId: $patient['contact_id'],
                );
            });
    }

    /**
     * @return Collection<int, array<string, mixed>>
     */
    private function globalLabResultEvents(ActiveCampaignDashboardFilter $filter): Collection
    {
        return LaboratoryPurchase::query()
            ->with(['customer.contacts:id,customer_id,name,paternal_lastname,maternal_lastname', 'customer.user:id,email'])
            ->where(function ($q) {
                $q->whereNotNull('results')->orWhereNotNull('ready_at');
            })
            ->where(function ($q) use ($filter) {
                $q->whereBetween('ready_at', [$filter->start, $filter->end])
                    ->orWhere(function ($inner) use ($filter) {
                        $inner->whereNull('ready_at')
                            ->whereBetween('updated_at', [$filter->start, $filter->end]);
                    });
            })
            ->latest('id')
            ->limit(self::PER_SOURCE_LIMIT)
            ->get(['id', 'customer_id', 'brand', 'results', 'ready_at', 'created_at', 'updated_at'])
            ->map(function (LaboratoryPurchase $purchase) {
                $at = $purchase->ready_at ?? $purchase->updated_at ?? $purchase->created_at;
                $brand = $purchase->brand?->label() ?: 'Laboratorio';
                $patient = $this->patientFromCustomer($purchase->customer);

                return $this->row(
                    id: 'lab-results-'.$purchase->id,
                    at: $at,
                    type: 'laboratory_results',
                    typeLabel: 'Resultado laboratorio',
                    source: 'famedic',
                    sourceLabel: 'Famedic · Laboratorio',
                    description: "Resultados disponibles ({$brand}).",
                    status: 'ready',
                    statusLabel: 'Disponible',
                    badge: 'Resultados',
                    color: 'emerald',
                    patient: $patient['label'],
                    patientEmail: $patient['email'],
                    contactId: $patient['contact_id'],
                );
            });
    }

    /**
     * @return Collection<int, array<string, mixed>>
     */
    private function globalPharmacyEvents(ActiveCampaignDashboardFilter $filter): Collection
    {
        return OnlinePharmacyPurchase::query()
            ->withTrashed()
            ->with(['customer.contacts:id,customer_id,name,paternal_lastname,maternal_lastname', 'customer.user:id,email'])
            ->whereBetween('created_at', [$filter->start, $filter->end])
            ->latest('id')
            ->limit(self::PER_SOURCE_LIMIT)
            ->get(['id', 'customer_id', 'created_at', 'total_cents', 'deleted_at'])
            ->map(function (OnlinePharmacyPurchase $purchase) {
                $trashed = $purchase->trashed();
                $patient = $this->patientFromCustomer($purchase->customer);

                return $this->row(
                    id: 'pharmacy-purchase-'.$purchase->id,
                    at: $purchase->created_at,
                    type: 'pharmacy_purchase',
                    typeLabel: 'Compra farmacia',
                    source: 'famedic',
                    sourceLabel: 'Famedic · Farmacia',
                    description: 'Compra en farmacia en línea.',
                    status: $trashed ? 'cancelled' : 'completed',
                    statusLabel: $trashed ? 'Cancelada' : 'Completada',
                    badge: 'Farmacia',
                    color: $trashed ? 'zinc' : 'purple',
                    patient: $patient['label'],
                    patientEmail: $patient['email'],
                    contactId: $patient['contact_id'],
                );
            });
    }

    /**
     * @return Collection<int, array<string, mixed>>
     */
    private function globalInvoiceEvents(ActiveCampaignDashboardFilter $filter): Collection
    {
        return Invoice::query()
            ->where(function ($q) use ($filter) {
                $q->whereBetween('completed_at', [$filter->start, $filter->end])
                    ->orWhere(function ($inner) use ($filter) {
                        $inner->whereNull('completed_at')
                            ->whereBetween('created_at', [$filter->start, $filter->end]);
                    });
            })
            ->latest('id')
            ->limit(self::PER_SOURCE_LIMIT)
            ->get(['id', 'created_at', 'completed_at', 'invoiceable_type'])
            ->map(function (Invoice $invoice) {
                $at = $invoice->completed_at ?? $invoice->created_at;
                $channel = str_contains((string) $invoice->invoiceable_type, 'Laboratory')
                    ? 'laboratorio'
                    : 'farmacia';

                return $this->row(
                    id: 'invoice-'.$invoice->id,
                    at: $at,
                    type: 'invoice',
                    typeLabel: 'Factura',
                    source: 'famedic',
                    sourceLabel: 'Famedic · Facturación',
                    description: "Factura generada (compra de {$channel}).",
                    status: $invoice->completed_at ? 'completed' : 'pending',
                    statusLabel: $invoice->completed_at ? 'Completada' : 'En proceso',
                    badge: 'CFDI',
                    color: 'amber',
                    patient: '—',
                    patientEmail: null,
                    contactId: null,
                );
            });
    }

    /**
     * @return Collection<int, array<string, mixed>>
     */
    private function globalRegistrationEvents(ActiveCampaignDashboardFilter $filter): Collection
    {
        return Contact::query()
            ->with(['customer.user:id,email'])
            ->whereNull('deleted_at')
            ->whereBetween('created_at', [$filter->start, $filter->end])
            ->latest('id')
            ->limit(self::PER_SOURCE_LIMIT)
            ->get(['id', 'name', 'paternal_lastname', 'maternal_lastname', 'customer_id', 'created_at'])
            ->map(function (Contact $contact) {
                $name = trim((string) $contact->full_name);
                $email = $contact->customer?->user?->email;

                return $this->row(
                    id: 'contact-registered-'.$contact->id,
                    at: $contact->created_at,
                    type: 'registration',
                    typeLabel: 'Registro',
                    source: 'famedic',
                    sourceLabel: 'Famedic',
                    description: 'Paciente registrado en Famedic.',
                    status: 'completed',
                    statusLabel: 'Completado',
                    badge: 'Registro',
                    color: 'sky',
                    patient: $name !== '' ? $name : ('Contacto #'.$contact->id),
                    patientEmail: $email,
                    contactId: $contact->id,
                );
            });
    }

    /**
     * @param  array<string, mixed>  $event
     * @return array<string, mixed>
     */
    private function mapTimelineEvent(array $event, Contact $contact): array
    {
        $name = trim((string) $contact->full_name);
        $email = $contact->customer?->user?->email;

        $row = $this->row(
            id: (string) $event['id'],
            at: $event['occurred_at'] ?? null,
            type: (string) $event['type'],
            typeLabel: (string) $event['type_label'],
            source: (string) $event['source'],
            sourceLabel: (string) $event['source_label'],
            description: (string) $event['description'],
            status: (string) $event['status'],
            statusLabel: (string) $event['status_label'],
            badge: (string) $event['badge'],
            color: (string) $event['color'],
            patient: $name !== '' ? $name : ('Contacto #'.$contact->id),
            patientEmail: $email,
            contactId: $contact->id,
        );

        // Prefer Timeline date/time already formatted.
        $row['date'] = $event['date'] ?? $row['date'];
        $row['time'] = $event['time'] ?? $row['time'];
        $row['occurred_at'] = $event['occurred_at'] ?? $row['occurred_at'];

        return $row;
    }

    /**
     * @param  Collection<int, array<string, mixed>>  $items
     * @param  array<string, mixed>  $filters
     * @return Collection<int, array<string, mixed>>
     */
    private function applyFilters(Collection $items, array $filters): Collection
    {
        return $items
            ->filter(function (array $event) use ($filters) {
                if ($filters['type'] !== '' && ($event['type'] ?? '') !== $filters['type']) {
                    return false;
                }
                if ($filters['origin'] !== '' && ($event['source'] ?? '') !== $filters['origin']) {
                    return false;
                }
                if ($filters['status'] !== '' && ($event['status'] ?? '') !== $filters['status']) {
                    return false;
                }
                if ($filters['severity'] !== '' && ($event['severity'] ?? '') !== $filters['severity']) {
                    return false;
                }
                if ($filters['search'] !== '') {
                    $hay = strtolower(implode(' ', [
                        $event['patient'] ?? '',
                        $event['patient_email'] ?? '',
                        $event['type_label'] ?? '',
                        $event['description'] ?? '',
                        $event['event_type'] ?? '',
                        $event['badge'] ?? '',
                    ]));
                    if (! str_contains($hay, strtolower($filters['search']))) {
                        return false;
                    }
                }
                if ($filters['patient'] !== '' && ! $filters['contact_id']) {
                    $hay = strtolower(($event['patient'] ?? '').' '.($event['patient_email'] ?? ''));
                    if (! str_contains($hay, strtolower($filters['patient']))) {
                        return false;
                    }
                }

                return true;
            })
            ->map(function (array $event) {
                unset($event['occurred_at_sort']);

                return $event;
            })
            ->values();
    }

    /**
     * @param  \Illuminate\Support\Collection<int, ActiveCampaignDispatch>  $dispatches
     * @return array{by_customer: array<int, int>, by_email: array<string, int>}
     */
    private function contactIdMapForDispatches($dispatches): array
    {
        $customerIds = $dispatches->pluck('customer_id')->filter()->unique()->values()->all();
        $emails = $dispatches->pluck('email')->filter()->unique()->values()->all();

        $byCustomer = [];
        if ($customerIds !== []) {
            $byCustomer = Contact::query()
                ->whereIn('customer_id', $customerIds)
                ->pluck('id', 'customer_id')
                ->map(fn ($id) => (int) $id)
                ->all();
        }

        $byEmail = [];
        if ($emails !== []) {
            $contacts = Contact::query()
                ->with(['customer.user:id,email'])
                ->whereHas('customer.user', fn ($q) => $q->whereIn('email', $emails))
                ->get(['id', 'customer_id']);

            foreach ($contacts as $contact) {
                $email = $contact->customer?->user?->email;
                if (filled($email) && ! isset($byEmail[$email])) {
                    $byEmail[$email] = (int) $contact->id;
                }
            }
        }

        return [
            'by_customer' => $byCustomer,
            'by_email' => $byEmail,
        ];
    }

    /**
     * @param  array{by_customer?: array<int, int>, by_email?: array<string, int>}|null  $contactMap
     * @return array<string, mixed>
     */
    private function mapDispatchEvent(ActiveCampaignDispatch $dispatch, ?array $contactMap = null): array
    {
        $at = $dispatch->synced_at ?? $dispatch->created_at;
        $statusLabel = match ($dispatch->status) {
            ActiveCampaignDispatch::STATUS_SYNCED => 'Sincronizado',
            ActiveCampaignDispatch::STATUS_FAILED => 'Error',
            ActiveCampaignDispatch::STATUS_PENDING => 'Pendiente',
            ActiveCampaignDispatch::STATUS_PROCESSING => 'Procesando',
            ActiveCampaignDispatch::STATUS_SKIPPED => 'Omitido',
            default => (string) $dispatch->status,
        };
        $color = match ($dispatch->status) {
            ActiveCampaignDispatch::STATUS_SYNCED => 'emerald',
            ActiveCampaignDispatch::STATUS_FAILED => 'red',
            ActiveCampaignDispatch::STATUS_PENDING,
            ActiveCampaignDispatch::STATUS_PROCESSING => 'amber',
            default => 'zinc',
        };

        $contactId = null;
        if ($contactMap) {
            if ($dispatch->customer_id && isset($contactMap['by_customer'][$dispatch->customer_id])) {
                $contactId = $contactMap['by_customer'][$dispatch->customer_id];
            } elseif (filled($dispatch->email) && isset($contactMap['by_email'][$dispatch->email])) {
                $contactId = $contactMap['by_email'][$dispatch->email];
            }
        } else {
            $contactId = $this->resolveContactIdForDispatch($dispatch);
        }

        return $this->row(
            id: 'ac-dispatch-'.$dispatch->id,
            at: $at,
            type: 'activecampaign_dispatch',
            typeLabel: 'Dispatch ActiveCampaign',
            source: 'activecampaign_local',
            sourceLabel: 'Famedic · Dispatches',
            description: 'Evento '.$dispatch->event_type.'.',
            status: (string) $dispatch->status,
            statusLabel: $statusLabel,
            badge: 'AC',
            color: $color,
            patient: $dispatch->email ?: '—',
            patientEmail: $dispatch->email,
            contactId: $contactId,
            eventType: (string) $dispatch->event_type,
        );
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    private function sanitizePayloadForUi(array $payload): array
    {
        $redacted = $this->dispatchService->sanitizePayloadForLog($payload);
        $safe = [];

        foreach (self::PAYLOAD_ALLOWLIST as $key) {
            if (array_key_exists($key, $redacted)) {
                $safe[$key] = $redacted[$key];
            }
        }

        return $safe !== [] ? $safe : ['note' => 'Sin campos autorizados para mostrar'];
    }

    private function sanitizeError(?string $error): ?string
    {
        if ($error === null || trim($error) === '') {
            return null;
        }

        $line = trim(explode("\n", $error)[0] ?? '');
        $line = preg_replace('/\s+/', ' ', $line) ?? $line;
        // Oculta rutas absolutas / stacks.
        $line = preg_replace('/(\/[\w.\-]+)+/', '[path]', $line) ?? $line;

        if (mb_strlen($line) > 120) {
            $line = mb_substr($line, 0, 117).'…';
        }

        return $line;
    }

    /**
     * @return array{label: string, email: ?string, contact_id: ?int}
     */
    private function patientFromCustomer(?Customer $customer): array
    {
        if (! $customer) {
            return ['label' => '—', 'email' => null, 'contact_id' => null];
        }

        $contact = $customer->relationLoaded('contacts')
            ? $customer->contacts->first()
            : $customer->contacts()->orderBy('id')->first();

        if ($contact) {
            $name = trim((string) $contact->full_name);

            return [
                'label' => $name !== '' ? $name : ('Contacto #'.$contact->id),
                'email' => $customer->user?->email,
                'contact_id' => $contact->id,
            ];
        }

        return [
            'label' => $customer->user?->email ?: ('Customer #'.$customer->id),
            'email' => $customer->user?->email,
            'contact_id' => null,
        ];
    }

    private function resolveContactIdForDispatch(ActiveCampaignDispatch $dispatch): ?int
    {
        if ($dispatch->customer_id) {
            $id = Contact::query()->where('customer_id', $dispatch->customer_id)->value('id');
            if ($id) {
                return (int) $id;
            }
        }

        if (filled($dispatch->email)) {
            $id = Contact::query()
                ->whereHas('customer.user', fn ($q) => $q->where('email', $dispatch->email))
                ->value('id');
            if ($id) {
                return (int) $id;
            }
        }

        return null;
    }

    /**
     * @return array<string, mixed>|null
     */
    private function resolveDomainEventDetail(string $eventId): ?array
    {
        if (str_starts_with($eventId, 'lab-purchase-')) {
            $id = (int) str_replace('lab-purchase-', '', $eventId);
            $purchase = LaboratoryPurchase::query()->withTrashed()->with(['customer.contacts', 'customer.user'])->find($id);
            if (! $purchase) {
                return null;
            }
            $patient = $this->patientFromCustomer($purchase->customer);
            $row = $this->row(
                id: $eventId,
                at: $purchase->created_at,
                type: 'laboratory_purchase',
                typeLabel: 'Compra laboratorio',
                source: 'famedic',
                sourceLabel: 'Famedic · Laboratorio',
                description: 'Compra de laboratorio.',
                status: $purchase->trashed() ? 'cancelled' : 'completed',
                statusLabel: $purchase->trashed() ? 'Cancelada' : 'Completada',
                badge: 'Lab',
                color: $purchase->trashed() ? 'zinc' : 'blue',
                patient: $patient['label'],
                patientEmail: $patient['email'],
                contactId: $patient['contact_id'],
            );

            return [
                ...$row,
                'payload' => null,
                'payload_label' => self::UNAVAILABLE,
                'last_error' => null,
                'related_model' => [
                    'label' => 'LaboratoryPurchase',
                    'entity_id' => $purchase->id,
                ],
                'timeline_note' => 'Evento de dominio Famedic (misma fuente que Timeline).',
                'actions' => $this->detailActions($patient['contact_id'], false),
            ];
        }

        if (str_starts_with($eventId, 'lab-results-')) {
            $id = (int) str_replace('lab-results-', '', $eventId);
            $purchase = LaboratoryPurchase::query()->with(['customer.contacts', 'customer.user'])->find($id);
            if (! $purchase) {
                return null;
            }
            $patient = $this->patientFromCustomer($purchase->customer);
            $at = $purchase->ready_at ?? $purchase->updated_at ?? $purchase->created_at;
            $row = $this->row(
                id: $eventId,
                at: $at,
                type: 'laboratory_results',
                typeLabel: 'Resultado laboratorio',
                source: 'famedic',
                sourceLabel: 'Famedic · Laboratorio',
                description: 'Resultados disponibles.',
                status: 'ready',
                statusLabel: 'Disponible',
                badge: 'Resultados',
                color: 'emerald',
                patient: $patient['label'],
                patientEmail: $patient['email'],
                contactId: $patient['contact_id'],
            );

            return [
                ...$row,
                'payload' => null,
                'payload_label' => self::UNAVAILABLE,
                'last_error' => null,
                'related_model' => [
                    'label' => 'LaboratoryPurchase',
                    'entity_id' => $purchase->id,
                ],
                'timeline_note' => 'Evento de dominio Famedic (misma fuente que Timeline).',
                'actions' => $this->detailActions($patient['contact_id'], false),
            ];
        }

        if (str_starts_with($eventId, 'pharmacy-purchase-')) {
            $id = (int) str_replace('pharmacy-purchase-', '', $eventId);
            $purchase = OnlinePharmacyPurchase::query()->withTrashed()->with(['customer.contacts', 'customer.user'])->find($id);
            if (! $purchase) {
                return null;
            }
            $patient = $this->patientFromCustomer($purchase->customer);
            $row = $this->row(
                id: $eventId,
                at: $purchase->created_at,
                type: 'pharmacy_purchase',
                typeLabel: 'Compra farmacia',
                source: 'famedic',
                sourceLabel: 'Famedic · Farmacia',
                description: 'Compra en farmacia en línea.',
                status: $purchase->trashed() ? 'cancelled' : 'completed',
                statusLabel: $purchase->trashed() ? 'Cancelada' : 'Completada',
                badge: 'Farmacia',
                color: $purchase->trashed() ? 'zinc' : 'purple',
                patient: $patient['label'],
                patientEmail: $patient['email'],
                contactId: $patient['contact_id'],
            );

            return [
                ...$row,
                'payload' => null,
                'payload_label' => self::UNAVAILABLE,
                'last_error' => null,
                'related_model' => [
                    'label' => 'OnlinePharmacyPurchase',
                    'entity_id' => $purchase->id,
                ],
                'timeline_note' => 'Evento de dominio Famedic (misma fuente que Timeline).',
                'actions' => $this->detailActions($patient['contact_id'], false),
            ];
        }

        if (str_starts_with($eventId, 'invoice-')) {
            $id = (int) str_replace('invoice-', '', $eventId);
            $invoice = Invoice::query()->find($id);
            if (! $invoice) {
                return null;
            }
            $at = $invoice->completed_at ?? $invoice->created_at;
            $row = $this->row(
                id: $eventId,
                at: $at,
                type: 'invoice',
                typeLabel: 'Factura',
                source: 'famedic',
                sourceLabel: 'Famedic · Facturación',
                description: 'Factura generada.',
                status: $invoice->completed_at ? 'completed' : 'pending',
                statusLabel: $invoice->completed_at ? 'Completada' : 'En proceso',
                badge: 'CFDI',
                color: 'amber',
                patient: '—',
                patientEmail: null,
                contactId: null,
            );

            return [
                ...$row,
                'payload' => null,
                'payload_label' => self::UNAVAILABLE,
                'last_error' => null,
                'related_model' => [
                    'label' => 'Invoice',
                    'entity_id' => $invoice->id,
                    'invoiceable_type' => $invoice->invoiceable_type,
                ],
                'timeline_note' => 'Evento de dominio Famedic (misma fuente que Timeline).',
                'actions' => $this->detailActions(null, false),
            ];
        }

        if (str_starts_with($eventId, 'contact-registered-')) {
            $id = (int) str_replace('contact-registered-', '', $eventId);
            $contact = Contact::query()->with(['customer.user:id,email'])->find($id);
            if (! $contact) {
                return null;
            }
            $name = trim((string) $contact->full_name);
            $email = $contact->customer?->user?->email;
            $row = $this->row(
                id: $eventId,
                at: $contact->created_at,
                type: 'registration',
                typeLabel: 'Registro',
                source: 'famedic',
                sourceLabel: 'Famedic',
                description: 'Paciente registrado en Famedic.',
                status: 'completed',
                statusLabel: 'Completado',
                badge: 'Registro',
                color: 'sky',
                patient: $name !== '' ? $name : ('Contacto #'.$contact->id),
                patientEmail: $email,
                contactId: $contact->id,
            );

            return [
                ...$row,
                'payload' => null,
                'payload_label' => self::UNAVAILABLE,
                'last_error' => null,
                'related_model' => [
                    'label' => 'Contact',
                    'entity_id' => $contact->id,
                ],
                'timeline_note' => 'Evento de dominio Famedic (misma fuente que Timeline).',
                'actions' => $this->detailActions($contact->id, false),
            ];
        }

        return null;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function detailActions(?int $contactId, bool $canRetryHint): array
    {
        return [
            [
                'id' => 'contact',
                'label' => 'Ir a Contacto',
                'enabled' => (bool) $contactId,
                'href' => $contactId
                    ? route('admin.activecampaign.contacts', ['drawer_contact_id' => $contactId])
                    : null,
            ],
            [
                'id' => 'journey',
                'label' => 'Abrir Journey',
                'enabled' => (bool) $contactId,
                'href' => $contactId
                    ? route('admin.activecampaign.customer-journey', ['contact_id' => $contactId])
                    : null,
            ],
            [
                'id' => 'retry',
                'label' => 'Reintentar',
                'enabled' => false,
                'href' => null,
                'hint' => $canRetryHint
                    ? 'Dispatch fallido: no hay endpoint de reintento manual.'
                    : 'No aplica.',
            ],
        ];
    }

    private function modelLabelForType(string $type): string
    {
        return match ($type) {
            'registration' => 'Contact',
            'laboratory_purchase', 'laboratory_results' => 'LaboratoryPurchase',
            'pharmacy_purchase' => 'OnlinePharmacyPurchase',
            'invoice' => 'Invoice',
            'membership' => 'MedicalAttentionSubscription',
            'beneficiary_added' => 'FamilyAccount',
            'coupon_assigned' => 'CouponUser',
            'activecampaign_dispatch' => 'ActiveCampaignDispatch',
            default => self::UNAVAILABLE,
        };
    }

    /**
     * @return array<string, mixed>
     */
    private function row(
        string $id,
        mixed $at,
        string $type,
        string $typeLabel,
        string $source,
        string $sourceLabel,
        string $description,
        string $status,
        string $statusLabel,
        string $badge,
        string $color,
        string $patient,
        ?string $patientEmail,
        ?int $contactId,
        ?string $eventType = null,
    ): array {
        $carbon = $at instanceof Carbon
            ? $at->copy()
            : ($at ? Carbon::parse($at) : Carbon::now(self::TZ));
        $local = $carbon->timezone(self::TZ);

        [$severity, $severityLabel] = $this->severityFor($status);

        return [
            'id' => $id,
            'occurred_at' => $local->toIso8601String(),
            'occurred_at_sort' => $local->timestamp,
            'date' => $local->format('d/m/Y'),
            'time' => $local->format('H:i'),
            'type' => $type,
            'type_label' => $typeLabel,
            'event_type' => $eventType,
            'source' => $source,
            'source_label' => $sourceLabel,
            'description' => $description,
            'status' => $status,
            'status_label' => $statusLabel,
            'badge' => $badge,
            'color' => $color,
            'patient' => $patient,
            'patient_email' => $patientEmail,
            'contact_id' => $contactId,
            'severity' => $severity,
            'severity_label' => $severityLabel,
        ];
    }

    /**
     * @return array{0: string, 1: string}
     */
    private function severityFor(string $status): array
    {
        return match ($status) {
            'failed', 'cancelled' => ['error', 'Crítica'],
            'pending', 'processing' => ['warning', 'Advertencia'],
            default => ['info', 'Informativa'],
        };
    }
}
