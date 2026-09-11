<?php

namespace App\Http\Controllers\Admin;

use App\Enums\LaboratoryBrand;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\LaboratoryStores\IndexLaboratoryStoreRequest;
use App\Http\Requests\Admin\LaboratoryStores\RestoreLaboratoryStoreRequest;
use App\Http\Requests\Admin\LaboratoryStores\ShowLaboratoryStoreRequest;
use App\Http\Requests\Admin\LaboratoryStores\UpdateLaboratoryStoreCapabilitiesRequest;
use App\Http\Requests\Admin\LaboratoryStores\UpdateLaboratoryStoreHoursRequest;
use App\Http\Requests\Admin\LaboratoryStores\UpdateLaboratoryStoreRequest;
use App\Http\Requests\Admin\LaboratoryStores\UpdateLaboratoryStoreServicesRequest;
use App\Models\LaboratoryCapability;
use App\Models\LaboratoryStore;
use App\Models\LaboratoryStoreImportRow;
use App\Models\LaboratoryStoreManualAudit;
use App\Models\LaboratoryStoreService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

class LaboratoryStoreController extends Controller
{
    public function index(IndexLaboratoryStoreRequest $request): Response
    {
        return $this->renderIndex($request, $request->filters());
    }

    public function show(ShowLaboratoryStoreRequest $request, LaboratoryStore $laboratoryStore): Response
    {
        $filters = collect($request->only([
            'search',
            'brand',
            'state',
            'municipality',
            'active_status',
            'location_status',
            'service',
            'capability',
            'data_status',
            'view',
        ]))->filter()->all();

        return $this->renderIndex($request, $filters, $laboratoryStore);
    }

    public function update(UpdateLaboratoryStoreRequest $request, LaboratoryStore $laboratoryStore): RedirectResponse
    {
        $fields = [
            'name',
            'phone',
            'address',
            'street',
            'exterior_number',
            'interior_number',
            'neighborhood',
            'municipality',
            'city',
            'state',
            'postal_code',
            'google_maps_url',
            'latitude',
            'longitude',
            'is_active',
        ];
        $before = $laboratoryStore->only($fields);
        $after = $request->safe()->only($fields);
        $scope = $this->locationFieldsChanged($before, $after) ? 'location' : 'basic_fields';

        DB::transaction(function () use ($request, $laboratoryStore, $before, $after, $scope) {
            $laboratoryStore->update($after);
            $this->recordManualAudit($request, $laboratoryStore, 'updated', $scope, $before, $after);
        });

        return redirect()->route('admin.laboratory-stores.show', [
            'laboratory_store' => $laboratoryStore,
        ])->flashMessage('Sucursal actualizada correctamente.');
    }

    public function updateHours(UpdateLaboratoryStoreHoursRequest $request, LaboratoryStore $laboratoryStore): RedirectResponse
    {
        $before = $this->hoursSnapshot($laboratoryStore);
        $hours = collect($request->validated('hours'))->sortBy('day_of_week')->values();

        DB::transaction(function () use ($request, $laboratoryStore, $hours, $before) {
            foreach ($hours as $hour) {
                $isClosed = (bool) $hour['is_closed'];
                $existing = $laboratoryStore->hours()
                    ->where('day_of_week', $hour['day_of_week'])
                    ->orderByRaw("CASE WHEN source = 'manual' THEN 0 ELSE 1 END")
                    ->first();

                $payload = [
                    'day_of_week' => $hour['day_of_week'],
                    'is_closed' => $isClosed,
                    'opens_at' => $isClosed ? null : $hour['opens_at'],
                    'closes_at' => $isClosed ? null : $hour['closes_at'],
                    'raw_text' => null,
                    'source' => 'manual',
                ];

                $existing
                    ? $existing->update($payload)
                    : $laboratoryStore->hours()->create($payload);
            }

            $laboratoryStore->load('hours');
            $this->recordManualAudit($request, $laboratoryStore, 'updated', 'hours', $before, $this->hoursSnapshot($laboratoryStore));
        });

        return redirect()->route('admin.laboratory-stores.show', [
            'laboratory_store' => $laboratoryStore,
        ])->flashMessage('Horarios actualizados correctamente.');
    }

    public function updateCapabilities(UpdateLaboratoryStoreCapabilitiesRequest $request, LaboratoryStore $laboratoryStore): RedirectResponse
    {
        $before = $laboratoryStore->capabilities()->orderBy('id')->pluck('laboratory_capabilities.id')->all();
        $capabilityIds = collect($request->validated('capability_ids'))->map(fn ($id) => (int) $id)->sort()->values()->all();

        DB::transaction(function () use ($request, $laboratoryStore, $before, $capabilityIds) {
            $laboratoryStore->capabilities()->sync($capabilityIds);
            $this->recordManualAudit($request, $laboratoryStore, 'updated', 'capabilities', $before, $capabilityIds);
        });

        return redirect()->route('admin.laboratory-stores.show', [
            'laboratory_store' => $laboratoryStore,
        ])->flashMessage('Capacidades actualizadas correctamente.');
    }

    public function updateServices(UpdateLaboratoryStoreServicesRequest $request, LaboratoryStore $laboratoryStore): RedirectResponse
    {
        $before = $this->servicesSnapshot($laboratoryStore);
        $services = collect($request->validated('services'))->keyBy('service_type');

        DB::transaction(function () use ($request, $laboratoryStore, $services, $before) {
            foreach ($this->editableServiceTypes() as $serviceType => $label) {
                $service = $laboratoryStore->services()->firstOrNew(['service_type' => $serviceType]);
                $service->fill([
                    'name' => $service->name ?: $label,
                    'is_active' => (bool) data_get($services, "{$serviceType}.is_active", false),
                    'source' => 'manual',
                ]);
                $service->save();
            }

            $laboratoryStore->load('services');
            $this->recordManualAudit($request, $laboratoryStore, 'updated', 'services', $before, $this->servicesSnapshot($laboratoryStore));
        });

        return redirect()->route('admin.laboratory-stores.show', [
            'laboratory_store' => $laboratoryStore,
        ])->flashMessage('Servicios actualizados correctamente.');
    }

    public function destroy(ShowLaboratoryStoreRequest $request, LaboratoryStore $laboratoryStore): RedirectResponse
    {
        $this->authorize('delete', $laboratoryStore);

        DB::transaction(function () use ($request, $laboratoryStore) {
            $before = ['deleted_at' => null];
            $laboratoryStore->delete();
            $this->recordManualAudit($request, $laboratoryStore, 'deactivated', 'status', $before, [
                'deleted_at' => $laboratoryStore->deleted_at?->toDateTimeString(),
            ]);
        });

        return redirect()->route('admin.laboratory-stores.index')
            ->flashMessage('Sucursal desactivada correctamente.');
    }

    public function restore(RestoreLaboratoryStoreRequest $request, int $laboratoryStore): RedirectResponse
    {
        $store = LaboratoryStore::withTrashed()->findOrFail($laboratoryStore);

        $this->authorize('restore', $store);

        DB::transaction(function () use ($request, $store) {
            $before = ['deleted_at' => $store->deleted_at?->toDateTimeString()];
            $store->restore();
            $this->recordManualAudit($request, $store, 'reactivated', 'status', $before, ['deleted_at' => null]);
        });

        return redirect()->route('admin.laboratory-stores.show', [
            'laboratory_store' => $store,
        ])->flashMessage('Sucursal reactivada correctamente.');
    }

    private function renderIndex(
        IndexLaboratoryStoreRequest|ShowLaboratoryStoreRequest $request,
        array $filters,
        ?LaboratoryStore $selectedStore = null,
    ): Response {
        $queryFilters = $this->queryFilters($filters);

        $stores = LaboratoryStore::query()
            ->withTrashed()
            ->withCount($this->storeCounts())
            ->filter($queryFilters)
            ->orderBy('name')
            ->paginate(15)
            ->withQueryString()
            ->through(fn (LaboratoryStore $store) => $this->storeListData($store));

        $mapStores = LaboratoryStore::query()
            ->withTrashed()
            ->withCount($this->mapStoreCounts())
            ->filter($queryFilters)
            ->orderBy('name')
            ->get([
                'id',
                'name',
                'brand',
                'state',
                'municipality',
                'city',
                'postal_code',
                'latitude',
                'longitude',
                'google_maps_url',
                'is_active',
                'deleted_at',
                'source_missing_at',
            ])
            ->map(fn (LaboratoryStore $store) => $this->mapStoreData($store));
        $mapLocatedCount = $mapStores
            ->filter(fn (array $store) => $store['has_coordinates'])
            ->count();

        $activeStoresCount = LaboratoryStore::query()
            ->where('is_active', true)
            ->whereNull('deleted_at')
            ->count();
        $dataAlertsCount = LaboratoryStore::query()
            ->withTrashed()
            ->where(function ($query) {
                $query->whereNotNull('source_missing_at')
                    ->orWhereHas('importRows', fn ($query) => $query
                        ->whereNotNull('warnings')
                        ->orWhereNotNull('planned_payload->field_conflicts')
                        ->orWhereNotNull('planned_payload->skipped_fields'));
            })
            ->count();

        return Inertia::render('Admin/LaboratoryStores', [
            'laboratoryStores' => $stores,
            'mapStores' => $mapStores,
            'mapSummary' => [
                'total' => $mapStores->count(),
                'with_coordinates' => $mapLocatedCount,
                'missing_coordinates' => $mapStores->count() - $mapLocatedCount,
            ],
            'storeDetail' => $selectedStore ? $this->storeDetailData($selectedStore->load([
                'hours' => fn ($query) => $query->orderBy('day_of_week'),
                'capabilities' => fn ($query) => $query->orderBy('sort_order')->orderBy('name'),
                'services' => fn ($query) => $query->orderBy('service_type')->orderBy('name'),
            ])->loadCount([
                'capabilities',
                'services as active_services_count' => fn ($query) => $query->where('is_active', true),
                'services as clinical_history_services_count' => fn ($query) => $query->where('service_type', 'clinical_history')->where('is_active', true),
                'services as optical_services_count' => fn ($query) => $query->where('service_type', 'optical')->where('is_active', true),
                'hours',
                'importRows as field_conflicts_count' => fn ($query) => $query
                    ->where(function ($query) {
                        $query->whereNotNull('planned_payload->field_conflicts')
                            ->orWhereNotNull('planned_payload->skipped_fields');
                    }),
                'importRows as gda_warnings_count' => fn ($query) => $query->whereNotNull('warnings'),
                'laboratoryAppointments',
            ])) : null,
            'filters' => $filters,
            'brands' => LaboratoryBrand::brandsData(),
            'filterOptions' => $this->filterOptions(),
            'summary' => [
                'active_stores_count' => $activeStoresCount,
                'inactive_stores_count' => LaboratoryStore::query()->where('is_active', false)->whereNull('deleted_at')->count(),
                'brands_count' => LaboratoryStore::withTrashed()->select('brand')->distinct()->count('brand'),
                'data_alerts_count' => $dataAlertsCount,
                'total_stores_count' => LaboratoryStore::withTrashed()->count(),
            ],
            'can' => [
                'update' => $request->user()->can('update', $selectedStore ?? new LaboratoryStore),
            ],
            'drawerMode' => $request->boolean('edit') ? 'edit' : 'detail',
            'gdaWarning' => 'Los cambios manuales pueden ser sobrescritos por futuras importaciones GDA.',
        ]);
    }

    private function storeListData(LaboratoryStore $store): array
    {
        return [
            'id' => $store->id,
            'name' => $store->name,
            'brand' => $store->brand?->value ?? $store->brand,
            'brand_label' => $store->brand?->label() ?? strtoupper((string) $store->brand),
            'state' => $store->state,
            'municipality' => $store->municipality,
            'city' => $store->city,
            'postal_code' => $store->postal_code,
            'latitude' => $store->latitude,
            'longitude' => $store->longitude,
            'phone' => $store->phone,
            'is_active' => $store->is_active,
            'deleted_at' => $store->deleted_at?->toISOString(),
            'source_missing_at' => $store->source_missing_at?->toISOString(),
            'status_label' => $this->statusLabel($store),
            'data_status_label' => $store->source_missing_at ? 'Histórica / No presente en fuente' : 'Sin alertas',
            'data_quality' => $this->dataQuality($store),
            'capabilities_count' => $store->capabilities_count,
            'active_services_count' => $store->active_services_count,
            'clinical_history_services_count' => $store->clinical_history_services_count,
            'optical_services_count' => $store->optical_services_count,
            'hours_count' => $store->hours_count,
            'field_conflicts_count' => $store->field_conflicts_count,
            'gda_warnings_count' => $store->gda_warnings_count,
            'laboratory_appointments_count' => $store->laboratory_appointments_count,
            'show_url' => route('admin.laboratory-stores.show', ['laboratory_store' => $store]),
            'public_url' => route('laboratory-stores.index', ['brand' => $store->brand?->value ?? $store->brand]),
        ];
    }

    private function queryFilters(array $filters): array
    {
        return collect($filters)
            ->except(['view', 'store'])
            ->all();
    }

    private function storeCounts(): array
    {
        return [
            'capabilities',
            'services as active_services_count' => fn ($query) => $query->where('is_active', true),
            'services as clinical_history_services_count' => fn ($query) => $query->where('service_type', 'clinical_history')->where('is_active', true),
            'services as optical_services_count' => fn ($query) => $query->where('service_type', 'optical')->where('is_active', true),
            'hours',
            'importRows as field_conflicts_count' => fn ($query) => $query
                ->where(function ($query) {
                    $query->whereNotNull('planned_payload->field_conflicts')
                        ->orWhereNotNull('planned_payload->skipped_fields');
                }),
            'importRows as gda_warnings_count' => fn ($query) => $query->whereNotNull('warnings'),
            'laboratoryAppointments',
        ];
    }

    private function mapStoreCounts(): array
    {
        return [
            'importRows as field_conflicts_count' => fn ($query) => $query
                ->where(function ($query) {
                    $query->whereNotNull('planned_payload->field_conflicts')
                        ->orWhereNotNull('planned_payload->skipped_fields');
                }),
            'importRows as gda_warnings_count' => fn ($query) => $query->whereNotNull('warnings'),
        ];
    }

    private function mapStoreData(LaboratoryStore $store): array
    {
        return [
            'id' => $store->id,
            'name' => $store->name,
            'brand' => $store->brand?->value ?? $store->brand,
            'brand_label' => $store->brand?->label() ?? strtoupper((string) $store->brand),
            'state' => $store->state,
            'municipality' => $store->municipality,
            'city' => $store->city,
            'postal_code' => $store->postal_code,
            'latitude' => $store->latitude,
            'longitude' => $store->longitude,
            'google_maps_url' => $store->google_maps_url,
            'has_coordinates' => $this->hasValidCoordinates($store),
            'is_active' => $store->is_active,
            'status_label' => $this->statusLabel($store),
            'data_quality' => $this->dataQuality($store),
            'show_url' => route('admin.laboratory-stores.show', ['laboratory_store' => $store]),
            'public_url' => route('laboratory-stores.index', ['brand' => $store->brand?->value ?? $store->brand]),
        ];
    }

    private function storeDetailData(LaboratoryStore $store): array
    {
        $fieldConflicts = $this->fieldConflicts($store);

        return [
            ...$this->storeListData($store),
            'source' => $store->source,
            'external_key' => $store->external_key,
            'address' => $store->address,
            'street' => $store->street,
            'exterior_number' => $store->exterior_number,
            'interior_number' => $store->interior_number,
            'neighborhood' => $store->neighborhood,
            'latitude' => $store->latitude,
            'longitude' => $store->longitude,
            'google_maps_url' => $store->google_maps_url,
            'created_at' => $store->created_at?->toDateTimeString(),
            'updated_at' => $store->updated_at?->toDateTimeString(),
            'hours' => $this->normalizedHours($store)->map(fn ($hour) => [
                'id' => $hour->id,
                'day_of_week' => $hour->day_of_week,
                'day_label' => $this->dayLabel($hour->day_of_week),
                'opens_at' => $this->formatTime($hour->opens_at),
                'closes_at' => $this->formatTime($hour->closes_at),
                'input_opens_at' => $this->formatTime24($hour->opens_at),
                'input_closes_at' => $this->formatTime24($hour->closes_at),
                'is_closed' => $hour->is_closed,
                'raw_text' => $hour->raw_text,
                'source' => $hour->source,
            ])->values(),
            'capabilities' => $store->capabilities->map(fn ($capability) => [
                'id' => $capability->id,
                'slug' => $capability->slug,
                'name' => $capability->name,
                'category' => $capability->category,
            ])->values(),
            'services' => $store->services->map(fn ($service) => [
                'id' => $service->id,
                'service_type' => $service->service_type,
                'name' => $service->name,
                'schedule_raw' => $service->schedule_raw,
                'phone' => $service->phone,
                'address' => $service->address,
                'is_active' => $service->is_active,
            ])->values(),
            'field_conflicts' => $fieldConflicts,
            'history' => $this->history($store),
            'can_restore' => $store->trashed(),
            'update_url' => route('admin.laboratory-stores.update', ['laboratory_store' => $store]),
            'update_hours_url' => route('admin.laboratory-stores.hours.update', ['laboratory_store' => $store]),
            'update_capabilities_url' => route('admin.laboratory-stores.capabilities.update', ['laboratory_store' => $store]),
            'update_services_url' => route('admin.laboratory-stores.services.update', ['laboratory_store' => $store]),
            'delete_url' => route('admin.laboratory-stores.destroy', ['laboratory_store' => $store]),
            'restore_url' => route('admin.laboratory-stores.restore', ['laboratory_store' => $store->id]),
        ];
    }

    private function filterOptions(): array
    {
        return [
            'states' => LaboratoryStore::withTrashed()->select('state')->whereNotNull('state')->distinct()->orderBy('state')->pluck('state'),
            'municipalities' => LaboratoryStore::withTrashed()->select('municipality')->whereNotNull('municipality')->distinct()->orderBy('municipality')->pluck('municipality'),
            'services' => LaboratoryStoreService::query()->select('service_type')->distinct()->orderBy('service_type')->pluck('service_type'),
            'capabilities' => LaboratoryCapability::query()->where('is_active', true)->orderBy('sort_order')->orderBy('name')->get(['slug', 'name']),
            'all_capabilities' => LaboratoryCapability::query()->orderBy('sort_order')->orderBy('name')->get(['id', 'slug', 'name', 'category', 'is_active']),
            'editable_services' => collect($this->editableServiceTypes())->map(fn ($label, $type) => [
                'service_type' => $type,
                'name' => $label,
            ])->values(),
            'data_statuses' => [
                ['value' => 'ok', 'label' => 'OK'],
                ['value' => 'warning', 'label' => 'Advertencia'],
                ['value' => 'conflict', 'label' => 'Conflicto GDA'],
                ['value' => 'historical', 'label' => 'Histórica'],
            ],
        ];
    }

    private function fieldConflicts(LaboratoryStore $store): array
    {
        return LaboratoryStoreImportRow::query()
            ->where(function ($query) use ($store) {
                $query->where('applied_store_id', $store->id)
                    ->orWhere('matched_store_id', $store->id)
                    ->orWhere('auto_matched_store_id', $store->id);
            })
            ->where(function ($query) {
                $query->whereNotNull('planned_payload->field_conflicts')
                    ->orWhereNotNull('planned_payload->skipped_fields');
            })
            ->latest('id')
            ->limit(3)
            ->get(['id', 'planned_payload', 'applied_at', 'created_at'])
            ->flatMap(function (LaboratoryStoreImportRow $row) {
                $conflicts = collect($row->planned_payload['field_conflicts'] ?? []);
                $skippedFields = collect($row->planned_payload['skipped_fields'] ?? [])
                    ->mapWithKeys(fn ($field) => [
                        $field => [
                            'reason' => 'skipped_field',
                            'action' => 'SKIPPED_CONFLICT',
                        ],
                    ]);

                return $conflicts
                    ->merge($skippedFields)
                    ->map(fn ($conflict, $field) => [
                        'row_id' => $row->id,
                        'field' => $field,
                        'reason' => $conflict['reason'] ?? null,
                        'source_value' => $conflict['source_value'] ?? null,
                        'existing_value' => $conflict['existing_value'] ?? null,
                        'action' => $conflict['action'] ?? 'SKIPPED_CONFLICT',
                        'detected_at' => ($row->applied_at ?? $row->created_at)?->toDateTimeString(),
                    ]);
            })
            ->values()
            ->all();
    }

    private function statusLabel(LaboratoryStore $store): string
    {
        if ($store->trashed()) {
            return 'Histórica';
        }

        return $store->is_active ? 'Activa' : 'Inactiva';
    }

    private function dayLabel(int $day): string
    {
        return [
            1 => 'Lunes',
            2 => 'Martes',
            3 => 'Miércoles',
            4 => 'Jueves',
            5 => 'Viernes',
            6 => 'Sábado',
            7 => 'Domingo',
        ][$day] ?? 'Día';
    }

    private function formatTime(mixed $time): ?string
    {
        if (! $time) {
            return null;
        }

        return now()->setTimeFromTimeString((string) $time)->format('g:i A');
    }

    private function formatTime24(mixed $time): ?string
    {
        if (! $time) {
            return null;
        }

        return now()->setTimeFromTimeString((string) $time)->format('H:i');
    }

    private function normalizedHours(LaboratoryStore $store)
    {
        return collect(range(1, 7))->map(function (int $day) use ($store) {
            return $store->hours
                ->where('day_of_week', $day)
                ->sortBy(fn ($hour) => $hour->source === 'manual' ? 0 : 1)
                ->first() ?? new \App\Models\LaboratoryStoreHour([
                    'day_of_week' => $day,
                    'is_closed' => true,
                    'source' => null,
                ]);
        });
    }

    private function dataQuality(LaboratoryStore $store): array
    {
        if ($store->source_missing_at) {
            return ['value' => 'historical', 'label' => 'Histórica', 'color' => 'amber'];
        }

        if (($store->field_conflicts_count ?? 0) > 0) {
            return ['value' => 'conflict', 'label' => 'Conflicto GDA', 'color' => 'red'];
        }

        if (($store->gda_warnings_count ?? 0) > 0) {
            return ['value' => 'warning', 'label' => 'Advertencia', 'color' => 'amber'];
        }

        return ['value' => 'ok', 'label' => 'OK', 'color' => 'green'];
    }

    private function hasValidCoordinates(LaboratoryStore $store): bool
    {
        if ($store->latitude === null || $store->longitude === null) {
            return false;
        }

        $latitude = (float) $store->latitude;
        $longitude = (float) $store->longitude;

        return $latitude >= -90
            && $latitude <= 90
            && $longitude >= -180
            && $longitude <= 180;
    }

    private function history(LaboratoryStore $store): array
    {
        $manualEvents = collect(LaboratoryStoreManualAudit::query()
            ->with('user:id,name,paternal_lastname,maternal_lastname,email')
            ->where('laboratory_store_id', $store->id)
            ->latest()
            ->limit(10)
            ->get()
            ->map(fn (LaboratoryStoreManualAudit $audit) => [
                'id' => 'manual-'.$audit->id,
                'source' => 'manual',
                'label' => $this->manualActionLabel($audit->action, $audit->scope),
                'date' => $audit->created_at?->toDateTimeString(),
                'actor' => $audit->user?->full_name ?? $audit->user?->email ?? 'Admin',
                'scope' => $audit->scope,
            ])
            ->all());

        $gdaEvents = collect(LaboratoryStoreImportRow::query()
            ->with('run:id,file_path,status,completed_at')
            ->where(function ($query) use ($store) {
                $query->where('applied_store_id', $store->id)
                    ->orWhere('matched_store_id', $store->id)
                    ->orWhere('auto_matched_store_id', $store->id);
            })
            ->latest()
            ->limit(10)
            ->get()
            ->map(fn (LaboratoryStoreImportRow $row) => [
                'id' => 'gda-'.$row->id,
                'source' => 'gda',
                'label' => $row->planned_payload['field_conflicts'] ?? null
                    ? 'Campo omitido por conflicto'
                    : 'Sincronizado desde GDA',
                'date' => ($row->applied_at ?? $row->updated_at ?? $row->created_at)?->toDateTimeString(),
                'actor' => 'GDA',
                'scope' => $row->action,
            ])
            ->all());

        return $manualEvents
            ->merge($gdaEvents)
            ->sortByDesc('date')
            ->take(20)
            ->values()
            ->all();
    }

    private function manualActionLabel(string $action, string $scope): string
    {
        return match ($action) {
            'deactivated' => 'Desactivada manualmente',
            'reactivated' => 'Reactivada manualmente',
            default => match ($scope) {
                'hours' => 'Horarios actualizados manualmente',
                'capabilities' => 'Capacidades actualizadas manualmente',
                'services' => 'Servicios actualizados manualmente',
                default => 'Actualizado manualmente',
            },
        };
    }

    private function locationFieldsChanged(array $before, array $after): bool
    {
        foreach ([
            'address',
            'street',
            'exterior_number',
            'interior_number',
            'neighborhood',
            'municipality',
            'city',
            'state',
            'postal_code',
            'google_maps_url',
            'latitude',
            'longitude',
        ] as $field) {
            if (($before[$field] ?? null) != ($after[$field] ?? null)) {
                return true;
            }
        }

        return false;
    }

    private function recordManualAudit(
        mixed $request,
        LaboratoryStore $store,
        string $action,
        string $scope,
        array $before,
        array $after,
    ): void {
        LaboratoryStoreManualAudit::query()->create([
            'laboratory_store_id' => $store->id,
            'user_id' => $request->user()?->id,
            'administrator_id' => $request->user()?->administrator?->id,
            'action' => $action,
            'scope' => $scope,
            'before' => $before,
            'after' => $after,
        ]);
    }

    private function hoursSnapshot(LaboratoryStore $store): array
    {
        $store->loadMissing('hours');

        return $this->normalizedHours($store)
            ->map(fn ($hour) => [
                'day_of_week' => $hour->day_of_week,
                'is_closed' => (bool) $hour->is_closed,
                'opens_at' => $this->formatTime24($hour->opens_at),
                'closes_at' => $this->formatTime24($hour->closes_at),
                'source' => $hour->source,
            ])
            ->values()
            ->all();
    }

    private function servicesSnapshot(LaboratoryStore $store): array
    {
        $store->loadMissing('services');

        return collect($this->editableServiceTypes())
            ->map(fn ($label, $type) => [
                'service_type' => $type,
                'is_active' => (bool) $store->services->firstWhere('service_type', $type)?->is_active,
                'source' => $store->services->firstWhere('service_type', $type)?->source,
            ])
            ->values()
            ->all();
    }

    private function editableServiceTypes(): array
    {
        return [
            'clinical_history' => 'Historia Clínica',
            'optical' => 'Óptica',
        ];
    }
}
