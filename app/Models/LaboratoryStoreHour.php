<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class LaboratoryStoreHour extends Model
{
    protected $guarded = [];

    protected $casts = [
        'day_of_week' => 'integer',
        'is_closed' => 'boolean',
        'opens_at' => 'datetime:H:i:s',
        'closes_at' => 'datetime:H:i:s',
    ];

    public function store(): BelongsTo
    {
        return $this->belongsTo(LaboratoryStore::class, 'laboratory_store_id');
    }
}
