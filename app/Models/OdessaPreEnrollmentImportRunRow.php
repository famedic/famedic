<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class OdessaPreEnrollmentImportRunRow extends Model
{
    protected $fillable = [
        'import_run_id',
        'source_row',
        'diagnostic_status',
        'ready_to_preload',
        'source_row_hash',
    ];

    protected $hidden = [
        'source_row_hash',
        'importRun',
    ];

    protected function casts(): array
    {
        return [
            'ready_to_preload' => 'boolean',
            'source_row' => 'integer',
        ];
    }

    public function importRun(): BelongsTo
    {
        return $this->belongsTo(OdessaPreEnrollmentImportRun::class, 'import_run_id');
    }
}
