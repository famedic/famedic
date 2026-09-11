<?php

namespace App\Models;

use App\Enums\LaboratoryBrand;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class LaboratoryStore extends Model
{
    use HasFactory, SoftDeletes;

    protected $guarded = [];

    protected $casts = [
        'brand' => LaboratoryBrand::class,
        'is_active' => 'boolean',
        'source_missing_at' => 'datetime',
        'latitude' => 'decimal:7',
        'longitude' => 'decimal:7',
        'raw_import_payload' => 'array',
    ];

    public function scopeOfBrand(Builder $query, LaboratoryBrand $brand): void
    {
        $query->where('brand', $brand->value);
    }

    public function scopeFilter(Builder $query, array $filters): Builder
    {
        return $query
            ->when($filters['brand'] ?? null, function ($query, $brand) {
                $query->ofBrand(LaboratoryBrand::from($brand));
            })
            ->when($filters['search'] ?? null, function (Builder $query, string $search) {
                $query->where(function (Builder $query) use ($search) {
                    $query->where('name', 'like', "%{$search}%")
                        ->orWhere('address', 'like', "%{$search}%")
                        ->orWhere('street', 'like', "%{$search}%")
                        ->orWhere('neighborhood', 'like', "%{$search}%")
                        ->orWhere('municipality', 'like', "%{$search}%")
                        ->orWhere('postal_code', 'like', "%{$search}%")
                        ->orWhere('phone', 'like', "%{$search}%");
                });
            })
            ->when($filters['state'] ?? null, function ($query, $state) {
                $query->where('state', $state);
            })
            ->when($filters['municipality'] ?? null, function ($query, $municipality) {
                $query->where('municipality', $municipality);
            })
            ->when($filters['location_status'] ?? null, function (Builder $query, string $status) {
                if ($status === 'with_coordinates') {
                    $query->whereBetween('latitude', [-90, 90])
                        ->whereBetween('longitude', [-180, 180]);
                }

                if ($status === 'missing_coordinates') {
                    $query->where(function (Builder $query) {
                        $query->whereNull('latitude')
                            ->orWhereNull('longitude')
                            ->orWhereNotBetween('latitude', [-90, 90])
                            ->orWhereNotBetween('longitude', [-180, 180]);
                    });
                }
            })
            ->when($filters['active_status'] ?? null, function (Builder $query, string $status) {
                if ($status === 'active') {
                    $query->where('is_active', true)->whereNull('deleted_at');
                }

                if ($status === 'inactive') {
                    $query->where('is_active', false)->whereNull('deleted_at');
                }

                if ($status === 'historical') {
                    $query->whereNotNull('deleted_at');
                }
            })
            ->when($filters['data_status'] ?? null, function (Builder $query, string $status) {
                if ($status === 'historical') {
                    $query->whereNotNull('source_missing_at');
                }

                if ($status === 'conflict') {
                    $query->whereNull('source_missing_at')
                        ->whereHas('importRows', fn (Builder $query) => $query
                            ->whereNotNull('planned_payload->field_conflicts')
                            ->orWhereNotNull('planned_payload->skipped_fields'));
                }

                if ($status === 'warning') {
                    $query->whereNull('source_missing_at')
                        ->whereDoesntHave('importRows', fn (Builder $query) => $query
                            ->whereNotNull('planned_payload->field_conflicts')
                            ->orWhereNotNull('planned_payload->skipped_fields'))
                        ->whereHas('importRows', fn (Builder $query) => $query->whereNotNull('warnings'));
                }

                if ($status === 'ok') {
                    $query->whereNull('source_missing_at')
                        ->whereDoesntHave('importRows', fn (Builder $query) => $query
                            ->whereNotNull('warnings')
                            ->orWhereNotNull('planned_payload->field_conflicts')
                            ->orWhereNotNull('planned_payload->skipped_fields'));
                }
            })
            ->when($filters['capability'] ?? null, function (Builder $query, string $capability) {
                $query->whereHas('capabilities', fn (Builder $query) => $query->where('slug', $capability));
            })
            ->when($filters['service'] ?? null, function (Builder $query, string $service) {
                $query->whereHas('services', fn (Builder $query) => $query
                    ->where('service_type', $service)
                    ->where('is_active', true));
            });
    }

    public function hours(): HasMany
    {
        return $this->hasMany(LaboratoryStoreHour::class);
    }

    public function capabilities(): BelongsToMany
    {
        return $this->belongsToMany(LaboratoryCapability::class, 'laboratory_store_capability')
            ->orderBy('sort_order')
            ->orderBy('name')
            ->withTimestamps();
    }

    public function services(): HasMany
    {
        return $this->hasMany(LaboratoryStoreService::class);
    }

    public function laboratoryAppointments(): HasMany
    {
        return $this->hasMany(LaboratoryAppointment::class);
    }

    public function manualAudits(): HasMany
    {
        return $this->hasMany(LaboratoryStoreManualAudit::class);
    }

    public function importRows(): HasMany
    {
        return $this->hasMany(LaboratoryStoreImportRow::class, 'matched_store_id');
    }
}
