<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class OdessaPreEnrollmentImportRunAudit extends Model
{
    protected $fillable = [
        'import_run_id',
        'performed_by',
        'event',
        'counts_json',
        'result_code',
        'performed_at',
    ];

    protected $hidden = [
        'importRun',
    ];

    protected function casts(): array
    {
        return [
            'counts_json' => 'array',
            'performed_at' => 'datetime',
        ];
    }

    public function importRun(): BelongsTo
    {
        return $this->belongsTo(OdessaPreEnrollmentImportRun::class, 'import_run_id');
    }

    public function performer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'performed_by');
    }
}
