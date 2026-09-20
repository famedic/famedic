<?php

namespace App\Models;

use App\Enums\LaboratoryAnalyteValueKind;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class LaboratoryAnalyte extends Model
{
    use HasFactory;

    protected $fillable = [
        'code',
        'canonical_name',
        'loinc_code',
        'default_unit',
        'value_kind',
        'category',
        'is_active',
    ];

    protected function casts(): array
    {
        return [
            'value_kind' => LaboratoryAnalyteValueKind::class,
            'is_active' => 'boolean',
        ];
    }

    public function aliases(): HasMany
    {
        return $this->hasMany(LaboratoryAnalyteAlias::class);
    }

    public function observations(): HasMany
    {
        return $this->hasMany(LaboratoryResultObservation::class);
    }
}
