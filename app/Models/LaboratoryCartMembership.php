<?php

namespace App\Models;

use App\Enums\LaboratoryBrand;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class LaboratoryCartMembership extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'laboratory_brand' => LaboratoryBrand::class,
        ];
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function scopeOfBrand($query, LaboratoryBrand $brand)
    {
        return $query->where('laboratory_brand', $brand->value);
    }
}
