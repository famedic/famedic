<?php

namespace App\Models;

use App\Enums\LaboratoryBrand;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class LaboratoryStore extends Model
{
    use HasFactory, SoftDeletes;

    protected $guarded = [];

    protected $casts = [
        'brand' => LaboratoryBrand::class,
    ];

    public function scopeOfBrand(Builder $query, LaboratoryBrand $brand): void
    {
        $query->where('brand', $brand->value);
    }

    function scopeFilter(Builder $query, array $filters): Builder
    {
        return $query->when($filters['brand'] ?? null, function ($query, $brand) {
            $query->ofBrand(LaboratoryBrand::from($brand));
        })->when($filters['state'] ?? null, function ($query, $state) {
            $query->where('state', $state);
        });
    }
}
