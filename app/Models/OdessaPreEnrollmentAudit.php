<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class OdessaPreEnrollmentAudit extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'before_json' => 'array',
            'after_json' => 'array',
            'performed_at' => 'datetime',
        ];
    }

    public function preEnrollment(): BelongsTo
    {
        return $this->belongsTo(OdessaPreEnrollment::class, 'odessa_pre_enrollment_id');
    }

    public function performer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'performed_by');
    }
}
