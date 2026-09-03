<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

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
}
