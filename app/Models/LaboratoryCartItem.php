<?php

namespace App\Models;

use App\Enums\LaboratoryBrand;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

class LaboratoryCartItem extends Model
{
    use SoftDeletes;

    protected $guarded = [];

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function laboratoryTest(): BelongsTo
    {
        return $this->belongsTo(LaboratoryTest::class);
    }

    public function scopeOfBrand(Builder $query, LaboratoryBrand $brand): void
    {
        $query->whereHas('laboratoryTest', function ($query) use ($brand) {
            $query->where('brand', $brand->value);
        });
    }

    public function scopeRequiringAppointment(Builder $query): void
    {
        $query->whereHas('laboratoryTest', function ($query) {
            $query->where('requires_appointment', true);
        });
    }
}
