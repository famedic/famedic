<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

class LaboratoryStudyRequirement extends Model
{
    use SoftDeletes;

    protected $guarded = [];

    protected $casts = [
        'is_required' => 'boolean',
        'evidence' => 'array',
        'is_active' => 'boolean',
    ];

    public function group(): BelongsTo
    {
        return $this->belongsTo(LaboratoryStudyRequirementGroup::class, 'laboratory_study_requirement_group_id');
    }

    public function laboratoryCapability(): BelongsTo
    {
        return $this->belongsTo(LaboratoryCapability::class);
    }
}
