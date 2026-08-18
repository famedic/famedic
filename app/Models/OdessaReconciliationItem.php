<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class OdessaReconciliationItem extends Model
{
    use HasFactory;

    public const REVIEW_PENDING = 'PENDING';

    public const REVIEW_REVIEWED = 'REVIEWED';

    public const REVIEW_CONFIRMED = 'CONFIRMED';

    public const REVIEW_REJECTED = 'REJECTED';

    public const REVIEW_FOLLOW_UP = 'FOLLOW_UP';

    public const REVIEW_NOT_APPLICABLE = 'NOT_APPLICABLE';

    public const RESOLUTION_UNRESOLVED = 'UNRESOLVED';

    public const RESOLUTION_PARTIALLY_RESOLVED = 'PARTIALLY_RESOLVED';

    public const RESOLUTION_RESOLVED = 'RESOLVED';

    public const ACTION_STATUS_NO_ACTION = 'NO_ACTION';

    public const ACTION_STATUS_PENDING_ACTIVATION = 'PENDING_ACTIVATION';

    public const ACTION_STATUS_PENDING_DEACTIVATION = 'PENDING_DEACTIVATION';

    public const ACTION_STATUS_ALREADY_ACTIVE = 'ALREADY_ACTIVE';

    public const ACTION_STATUS_ALREADY_INACTIVE = 'ALREADY_INACTIVE';

    public const ACTION_STATUS_ACTIVATED = 'ACTIVATED';

    public const ACTION_STATUS_DEACTIVATED = 'DEACTIVATED';

    public const ACTION_STATUS_BLOCKED = 'BLOCKED';

    public const ACTION_STATUS_FAILED = 'FAILED';

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'birth_date_excel' => 'date',
            'birth_date_db' => 'date',
            'subscription_start_date' => 'datetime',
            'subscription_end_date' => 'datetime',
            'last_murguia_sync_at' => 'datetime',
            'data_quality_flags_json' => 'array',
            'review_notes_json' => 'array',
            'evidence_json' => 'array',
            'snapshot_json' => 'array',
            'reviewed_at' => 'datetime',
            'resolved_flags_json' => 'array',
        ];
    }

    public function run(): BelongsTo
    {
        return $this->belongsTo(OdessaReconciliationRun::class, 'run_id');
    }

    public function reviewedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewed_by');
    }

    public function notes(): HasMany
    {
        return $this->hasMany(OdessaReconciliationItemNote::class, 'item_id')->latest();
    }

    public function audits(): HasMany
    {
        return $this->hasMany(OdessaReconciliationItemAudit::class, 'item_id')->latest('created_at');
    }

    public function actions(): HasMany
    {
        return $this->hasMany(OdessaReconciliationItemAction::class, 'item_id')->latest('created_at');
    }

    public function scopeRequiresAttention(Builder $query): Builder
    {
        return $query->where('review_status', self::REVIEW_PENDING);
    }

    public static function reviewStatuses(): array
    {
        return [
            self::REVIEW_PENDING,
            self::REVIEW_REVIEWED,
            self::REVIEW_CONFIRMED,
            self::REVIEW_REJECTED,
            self::REVIEW_FOLLOW_UP,
            self::REVIEW_NOT_APPLICABLE,
        ];
    }

    public static function requiresManualReviewFromSnapshot(array $row): bool
    {
        $flags = (string) ($row['data_quality_flags'] ?? '');

        return in_array($row['match_type'] ?? null, ['MATCH_PROBABLE_IDENTITY', 'MATCH_AMBIGUOUS', 'MATCH_DELETED_RECORD'], true)
            || in_array($row['final_status'] ?? null, ['NO_REGISTRADO_EN_FAMEDIC', 'REVISAR_MANUALMENTE', 'DISCREPANCIA', 'REGISTRO_ELIMINADO'], true)
            || str_contains($flags, 'DISCREPANCIA_IDENTITY');
    }
}
