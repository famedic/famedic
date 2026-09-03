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
        return $query->when($filters['brand'] ?? null, function ($query, $brand) {
            $query->ofBrand(LaboratoryBrand::from($brand));
        })->when($filters['state'] ?? null, function ($query, $state) {
            $query->where('state', $state);
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

    public function importRows(): HasMany
    {
        return $this->hasMany(LaboratoryStoreImportRow::class, 'matched_store_id');
    }
}
