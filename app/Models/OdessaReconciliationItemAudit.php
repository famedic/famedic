<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class OdessaReconciliationItemAudit extends Model
{
    public const ACTION_RUN_CREATED = 'RUN_CREATED';

    public const ACTION_RUN_COMPLETED = 'RUN_COMPLETED';

    public const ACTION_REVIEW_STATUS_CHANGED = 'REVIEW_STATUS_CHANGED';

    public const ACTION_NOTE_ADDED = 'NOTE_ADDED';

    public const ACTION_RUN_ARCHIVED = 'RUN_ARCHIVED';

    public $timestamps = false;

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'metadata_json' => 'array',
            'created_at' => 'datetime',
        ];
    }

    public function item(): BelongsTo
    {
        return $this->belongsTo(OdessaReconciliationItem::class, 'item_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
