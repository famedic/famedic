<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class LaboratoryStoreManualAudit extends Model
{
    protected $guarded = [];

    protected $casts = [
        'before' => 'array',
        'after' => 'array',
    ];

    public function store(): BelongsTo
    {
        return $this->belongsTo(LaboratoryStore::class, 'laboratory_store_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function administrator(): BelongsTo
    {
        return $this->belongsTo(Administrator::class);
    }
}
