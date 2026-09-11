<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class LaboratoryStoreImportRow extends Model
{
    public const RESOLUTION_SOURCE_AUTO = 'AUTO';

    public const RESOLUTION_SOURCE_MANUAL = 'MANUAL';

    public const RESOLUTION_SOURCE_INVALID = 'INVALID_RESOLUTION';

    public const CLASSIFICATION_MATCHED = 'MATCHED';

    public const CLASSIFICATION_NEW = 'NEW';

    public const CLASSIFICATION_AMBIGUOUS = 'AMBIGUOUS';

    public const CLASSIFICATION_INVALID = 'INVALID';

    public const CLASSIFICATION_SOFT_DELETED_MATCH = 'SOFT_DELETED_MATCH';

    public const ACTION_NONE = 'NONE';

    public const ACTION_CREATE = 'CREATE';

    public const ACTION_UPDATE = 'UPDATE';

    public const ACTION_UPDATE_CANDIDATE = 'UPDATE_CANDIDATE';

    public const ACTION_MANUAL_REVIEW = 'MANUAL_REVIEW';

    public const ACTION_SKIP = 'SKIP';

    public const VALIDATION_VALID = 'VALID';

    public const VALIDATION_WARNING = 'WARNING';

    public const VALIDATION_INVALID_FIELDS = 'INVALID_FIELDS';

    public const APPLY_STATUS_CREATED = 'CREATED';

    public const APPLY_STATUS_UPDATED = 'UPDATED';

    public const APPLY_STATUS_UNCHANGED = 'UNCHANGED';

    public const APPLY_STATUS_SKIPPED = 'SKIPPED';

    public const APPLY_STATUS_FAILED = 'FAILED';

    protected $guarded = [];

    protected $casts = [
        'confidence' => 'integer',
        'diff' => 'array',
        'errors' => 'array',
        'invalid_fields' => 'array',
        'warnings' => 'array',
        'evidence' => 'array',
        'raw_payload' => 'array',
        'planned_payload' => 'array',
        'source_store_snapshot' => 'array',
        'before_snapshot' => 'array',
        'after_snapshot' => 'array',
        'applied_at' => 'datetime',
    ];

    public function run(): BelongsTo
    {
        return $this->belongsTo(LaboratoryStoreImportRun::class, 'run_id');
    }

    public function matchedStore(): BelongsTo
    {
        return $this->belongsTo(LaboratoryStore::class, 'matched_store_id');
    }

    public function manualResolution(): BelongsTo
    {
        return $this->belongsTo(LaboratoryStoreImportResolution::class, 'manual_resolution_id');
    }

    public function autoMatchedStore(): BelongsTo
    {
        return $this->belongsTo(LaboratoryStore::class, 'auto_matched_store_id');
    }

    public function appliedStore(): BelongsTo
    {
        return $this->belongsTo(LaboratoryStore::class, 'applied_store_id');
    }

    public function rowIsAuxiliaryService(): bool
    {
        return in_array($this->excel_sheet, ['HISTORIA CLINICO', 'OPTICAS'], true);
    }
}
