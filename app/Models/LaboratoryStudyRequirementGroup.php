<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class LaboratoryStudyRequirementGroup extends Model
{
    use SoftDeletes;

    protected $guarded = [];

    protected $casts = [
        'is_required' => 'boolean',
        'evidence' => 'array',
        'is_active' => 'boolean',
    ];

    public function laboratoryTest(): BelongsTo
    {
        return $this->belongsTo(LaboratoryTest::class);
    }

    public function requirements(): HasMany
    {
        return $this->hasMany(LaboratoryStudyRequirement::class);
    }
}
