<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

class LaboratoryCapability extends Model
{
    protected $guarded = [];

    protected $casts = [
        'is_active' => 'boolean',
    ];

    public function stores(): BelongsToMany
    {
        return $this->belongsToMany(LaboratoryStore::class, 'laboratory_store_capability')
            ->withTimestamps();
    }

    public function studyRequirements(): HasMany
    {
        return $this->hasMany(LaboratoryStudyRequirement::class);
    }
}
