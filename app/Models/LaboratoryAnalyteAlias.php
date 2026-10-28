<?php

namespace App\Models;

use App\Enums\LaboratoryAnalyteAliasSource;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class LaboratoryAnalyteAlias extends Model
{
    use HasFactory;

    protected $fillable = [
        'laboratory_analyte_id',
        'alias_normalized',
        'alias_raw',
        'source',
        'confidence',
    ];

    protected function casts(): array
    {
        return [
            'source' => LaboratoryAnalyteAliasSource::class,
            'confidence' => 'decimal:4',
        ];
    }

    public function analyte(): BelongsTo
    {
        return $this->belongsTo(LaboratoryAnalyte::class, 'laboratory_analyte_id');
    }
}
