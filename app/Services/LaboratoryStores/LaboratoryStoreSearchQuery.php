<?php

namespace App\Services\LaboratoryStores;

use App\Enums\LaboratoryBrand;
use App\Models\LaboratoryCapability;
use App\Models\LaboratoryStore;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class LaboratoryStoreSearchQuery
{
    /**
     * @param  array<string, string|null>  $filters
     * @param  array<string, string>  $serviceTypes
     */
    public function __construct(
        private readonly array $filters,
        private readonly array $serviceTypes,
    ) {}

    public function stores(): Collection
    {
        return $this->filteredQuery(withDistanceSelect: true)
            ->with([
                'hours' => fn ($query) => $query->orderBy('day_of_week'),
                'capabilities' => fn ($query) => $query->where('is_active', true)->orderBy('sort_order')->orderBy('name'),
                'services' => fn ($query) => $query->where('is_active', true)->orderBy('service_type'),
            ])
            ->tap(fn (Builder $query) => $this->applySort($query))
            ->get();
    }

    public function total(): int
    {
        return $this->baseQuery()->count();
    }

    public function filteredTotal(): int
    {
        return $this->filteredQuery()->count();
    }

    public function states(): array
    {
        return $this->baseQuery()
            ->whereNotNull('state')
            ->select('state')
            ->distinct()
            ->orderBy('state')
            ->pluck('state')
            ->filter(fn (?string $state) => filled($state))
            ->values()
            ->all();
    }

    public function municipalities(): array
    {
        return $this->baseQuery()
            ->when($this->filters['state'] ?? null, fn (Builder $query, string $state) => $query->where('state', $state))
            ->whereNotNull('municipality')
            ->select('municipality')
            ->distinct()
            ->orderBy('municipality')
            ->pluck('municipality')
            ->filter(fn (?string $municipality) => filled(trim(str_replace("\u{00A0}", '', (string) $municipality))))
            ->values()
            ->all();
    }

    public function capabilities(): array
    {
        $storeScope = $this->optionScopedStoreIds();

        return LaboratoryCapability::query()
            ->where('is_active', true)
            ->whereHas('stores', fn (Builder $query) => $query->whereIn('laboratory_stores.id', $storeScope))
            ->withCount(['stores as stores_count' => fn (Builder $query) => $query->whereIn('laboratory_stores.id', $storeScope)])
            ->orderBy('sort_order')
            ->orderBy('name')
            ->get(['id', 'slug', 'name', 'sort_order'])
            ->map(fn (LaboratoryCapability $capability) => [
                'slug' => $capability->slug,
                'name' => $capability->name,
                'stores_count' => $capability->stores_count,
            ])
            ->all();
    }

    public function services(): array
    {
        $storeScope = $this->optionScopedStoreIds();

        return collect($this->serviceTypes)
            ->map(function (string $databaseType, string $publicType) use ($storeScope) {
                $count = DB::table('laboratory_store_services')
                    ->where('service_type', $databaseType)
                    ->where('is_active', true)
                    ->whereIn('laboratory_store_id', $storeScope)
                    ->distinct('laboratory_store_id')
                    ->count('laboratory_store_id');

                return [
                    'type' => $publicType,
                    'name' => $publicType === 'historia_clinica' ? 'Historia Clinica' : 'Optica',
                    'stores_count' => $count,
                ];
            })
            ->filter(fn (array $service) => $service['stores_count'] > 0)
            ->values()
            ->all();
    }

    private function baseQuery(): Builder
    {
        return LaboratoryStore::query()
            ->where('is_active', true)
            ->when($this->filters['brand'] ?? null, fn (Builder $query, string $brand) => $query->ofBrand(LaboratoryBrand::from($brand)));
    }

    private function filteredQuery(bool $withDistanceSelect = false): Builder
    {
        $query = $this->baseQuery()
            ->when($this->filters['state'] ?? null, fn (Builder $query, string $state) => $query->where('state', $state))
            ->when($this->filters['municipality'] ?? null, fn (Builder $query, string $municipality) => $query->where('municipality', $municipality))
            ->when($this->filters['postal_code'] ?? null, fn (Builder $query, string $postalCode) => $query->where('postal_code', $postalCode))
            ->when($this->filters['capability'] ?? null, fn (Builder $query, string $capability) => $query->whereHas(
                'capabilities',
                fn (Builder $query) => $query->where('slug', $capability)->where('is_active', true),
            ))
            ->when($this->filters['service'] ?? null, fn (Builder $query, string $service) => $query->whereHas(
                'services',
                fn (Builder $query) => $query->where('service_type', $this->serviceTypes[$service])->where('is_active', true),
            ))
            ->when($this->filters['search'] ?? null, fn (Builder $query, string $search) => $this->applySearch($query, $search));

        if ($withDistanceSelect && $this->hasLocation()) {
            $query->select('laboratory_stores.*');
            $this->addDistanceSelect($query);
        }

        if ($this->hasLocation()) {
            $this->applyDistanceFilter($query);
        }

        return $query;
    }

    private function applySearch(Builder $query, string $search): void
    {
        $like = '%'.$search.'%';
        $capability = $this->capabilitySlugForSearch($search);

        $query->where(function (Builder $query) use ($like, $capability) {
            foreach (['name', 'address', 'street', 'neighborhood', 'municipality', 'city', 'state', 'postal_code'] as $column) {
                $query->orWhere($column, 'like', $like);
            }

            if ($capability !== null) {
                $query->orWhereHas('capabilities', fn (Builder $query) => $query->where('slug', $capability)->where('is_active', true));
            }
        });
    }

    private function applySort(Builder $query): void
    {
        if (($this->filters['sort'] ?? 'name') === 'distance' && $this->hasLocation()) {
            $query->orderBy('distance_km');

            return;
        }

        if (($this->filters['sort'] ?? 'name') === 'relevance' && filled($this->filters['search'] ?? null)) {
            $search = (string) $this->filters['search'];
            $query->orderByRaw(
                'CASE WHEN name = ? THEN 0 WHEN name LIKE ? THEN 1 WHEN postal_code = ? THEN 2 ELSE 3 END',
                [$search, $search.'%', $search],
            );
        }

        $query->orderBy('name');
    }

    private function capabilitySlugForSearch(string $search): ?string
    {
        $normalized = str($search)->ascii()->lower()->replaceMatches('/[^a-z0-9]+/', '_')->trim('_')->toString();

        return [
            'rayos_x' => 'rayos_x',
            'rayos' => 'rayos_x',
            'resonancia' => 'resonancia_magnetica',
            'resonancia_magnetica' => 'resonancia_magnetica',
            'mastografia' => 'mastografia',
            'tomografia' => 'tomografia',
            'ultrasonido' => 'ultrasonido_convencional',
            'ultrasonido_convencional' => 'ultrasonido_convencional',
        ][$normalized] ?? null;
    }

    /**
     * @return array<int>
     */
    private function optionScopedStoreIds(): array
    {
        return $this->baseQuery()
            ->when($this->filters['state'] ?? null, fn (Builder $query, string $state) => $query->where('state', $state))
            ->when($this->filters['municipality'] ?? null, fn (Builder $query, string $municipality) => $query->where('municipality', $municipality))
            ->pluck('id')
            ->all();
    }

    private function hasLocation(): bool
    {
        return ($this->filters['latitude'] ?? null) !== null
            && ($this->filters['longitude'] ?? null) !== null;
    }

    private function addDistanceSelect(Builder $query): void
    {
        $query->selectRaw($this->distanceExpression().' as distance_km', $this->distanceBindings());
    }

    private function applyDistanceFilter(Builder $query): void
    {
        $query
            ->whereNotNull('latitude')
            ->whereNotNull('longitude');

        if (($this->filters['radius'] ?? null) !== null) {
            $this->applyBoundingBox($query, (float) $this->filters['radius']);

            $query->whereRaw(
                $this->distanceExpression().' <= ?',
                [...$this->distanceBindings(), (float) $this->filters['radius']],
            );
        }
    }

    private function applyBoundingBox(Builder $query, float $radius): void
    {
        $latitude = (float) $this->filters['latitude'];
        $longitude = (float) $this->filters['longitude'];
        $latitudeDelta = $radius / 111;
        $longitudeDelta = $radius / max(cos(deg2rad($latitude)) * 111, 0.0001);

        $query
            ->whereBetween('latitude', [$latitude - $latitudeDelta, $latitude + $latitudeDelta])
            ->whereBetween('longitude', [$longitude - $longitudeDelta, $longitude + $longitudeDelta]);
    }

    private function distanceExpression(): string
    {
        return '6371 * 2 * asin(sqrt(pow(sin((radians(latitude) - radians(?)) / 2), 2) + cos(radians(?)) * cos(radians(latitude)) * pow(sin((radians(longitude) - radians(?)) / 2), 2)))';
    }

    /**
     * @return array<float>
     */
    private function distanceBindings(): array
    {
        $latitude = (float) $this->filters['latitude'];
        $longitude = (float) $this->filters['longitude'];

        return [$latitude, $latitude, $longitude];
    }
}
