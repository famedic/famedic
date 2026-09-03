<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class LaboratoryStoreImportResolution extends Model
{
    public const DECISION_MATCH_EXISTING = 'MATCH_EXISTING';

    public const DECISION_CREATE_NEW = 'CREATE_NEW';

    public const DECISION_SKIP = 'SKIP';

    protected $guarded = [];

    protected $casts = [
        'resolved_at' => 'datetime',
        'superseded_at' => 'datetime',
    ];

    public function scopeCurrent(Builder $query): Builder
    {
        return $query->whereNull('superseded_at');
    }

    public function matchedStore(): BelongsTo
    {
        return $this->belongsTo(LaboratoryStore::class, 'matched_store_id')->withTrashed();
    }

    public function resolver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'resolved_by');
    }
}
