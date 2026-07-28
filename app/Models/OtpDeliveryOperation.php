<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

final class OtpDeliveryOperation extends Model
{
    protected $fillable = [
        'operation_key', 'otp_challenge_id', 'purpose', 'status', 'primary_channel',
        'fallback_used', 'provider_alias', 'result_class', 'attempt_count', 'correlation_id',
    ];

    protected $casts = [
        'fallback_used' => 'boolean',
        'attempt_count' => 'integer',
    ];

    public function challenge(): BelongsTo
    {
        return $this->belongsTo(OtpChallenge::class, 'otp_challenge_id');
    }
}
