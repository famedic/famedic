<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class OdessaPreEnrollmentImportRun extends Model
{
    use HasUuids;

    public const STATUS_PREVIEWED = 'PREVIEWED';
    public const STATUS_IMPORTING = 'IMPORTING';
    public const STATUS_COMPLETED = 'COMPLETED';
    public const STATUS_FAILED = 'FAILED';
    public const STATUS_EXPIRED = 'EXPIRED';

    protected $fillable = [
        'source_file_hash',
        'source_sheet',
        'total_rows',
        'ready_rows',
        'excluded_rows',
        'existing_user_rows',
        'other_email_rows',
        'possible_duplicate_rows',
        'blocked_rows',
        'status',
        'previewed_by',
        'imported_by',
        'previewed_at',
        'imported_at',
        'expires_at',
        'failure_code',
        'row_hmac_key_encrypted',
    ];

    protected $hidden = [
        'source_file_hash',
        'row_hmac_key_encrypted',
        'rows',
        'audits',
    ];

    protected function casts(): array
    {
        return [
            'previewed_at' => 'datetime',
            'imported_at' => 'datetime',
            'expires_at' => 'datetime',
            'total_rows' => 'integer',
            'ready_rows' => 'integer',
            'excluded_rows' => 'integer',
            'existing_user_rows' => 'integer',
            'other_email_rows' => 'integer',
            'possible_duplicate_rows' => 'integer',
            'blocked_rows' => 'integer',
        ];
    }

    public function uniqueIds(): array
    {
        return ['uuid'];
    }

    public function previewer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'previewed_by');
    }

    public function importer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'imported_by');
    }

    public function audits(): HasMany
    {
        return $this->hasMany(OdessaPreEnrollmentImportRunAudit::class, 'import_run_id');
    }

    public function rows(): HasMany
    {
        return $this->hasMany(OdessaPreEnrollmentImportRunRow::class, 'import_run_id');
    }
}
