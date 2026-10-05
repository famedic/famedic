<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class BenavidesCodeImport extends Model
{
    use HasFactory;

    public const STATUS_PREVIEWED = 'previewed';
    public const STATUS_IMPORTING = 'importing';
    public const STATUS_COMPLETED = 'completed';
    public const STATUS_FAILED = 'failed';

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'total_rows' => 'integer',
            'valid_rows' => 'integer',
            'empty_rows' => 'integer',
            'duplicate_file_rows' => 'integer',
            'duplicate_database_rows' => 'integer',
            'assigned_conflict_rows' => 'integer',
            'imported_rows' => 'integer',
            'rejected_rows' => 'integer',
            'summary_json' => 'array',
            'confirmed_at' => 'datetime',
        ];
    }

    public function uploadedByUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'uploaded_by_user_id');
    }

    public function confirmedByUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'confirmed_by_user_id');
    }

    public function codes(): HasMany
    {
        return $this->hasMany(BenavidesCode::class, 'import_batch_id');
    }
}
