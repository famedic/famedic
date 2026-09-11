<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class OdessaReconciliationItemAction extends Model
{
    use HasFactory;

    public const STATUS_PENDING = 'PENDING';

    public const STATUS_COMPLETED = 'COMPLETED';

    public const STATUS_FAILED = 'FAILED';

    public const STATUS_CANCELLED = 'CANCELLED';

    public const TYPE_UPDATE_EMAIL = 'UPDATE_EMAIL';

    public const TYPE_LINK_ODESSA_ACCOUNT = 'LINK_ODESSA_ACCOUNT';

    public const TYPE_CREATE_MEMBERSHIP = 'CREATE_MEMBERSHIP';

    public const TYPE_RETRY_MURGUIA_SYNC = 'RETRY_MURGUIA_SYNC';

    public const TYPE_ACTIVATE_MURGUIA_MEMBERSHIP = 'ACTIVATE_MURGUIA_MEMBERSHIP';

    public const TYPE_DEACTIVATE_MURGUIA_MEMBERSHIP = 'DEACTIVATE_MURGUIA_MEMBERSHIP';

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'before_json' => 'array',
            'after_json' => 'array',
            'request_json' => 'array',
            'result_json' => 'array',
            'performed_at' => 'datetime',
        ];
    }

    public function item(): BelongsTo
    {
        return $this->belongsTo(OdessaReconciliationItem::class, 'item_id');
    }

    public function run(): BelongsTo
    {
        return $this->belongsTo(OdessaReconciliationRun::class, 'run_id');
    }

    public function performedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'performed_by');
    }
}
