<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class OtpStagingDiagnosticRevealAudit extends Model
{
    public $timestamps = false;

    protected $fillable = [
        'administrator_id',
        'challenge_public_id',
        'correlation_id',
        'created_at',
    ];

    protected $casts = [
        'created_at' => 'datetime',
    ];

    public function administrator(): BelongsTo
    {
        return $this->belongsTo(Administrator::class);
    }
}
