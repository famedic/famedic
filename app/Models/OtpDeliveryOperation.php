<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

final class OtpDeliveryOperation extends Model
{
    protected $fillable = [
        'operation_key', 'otp_challenge_id', 'purpose', 'status', 'primary_channel',
        'fallback_used', 'provider_alias', 'result_class', 'attempt_count', 'correlation_id',
        'provider_message_id', 'sms_delivery_status', 'sms_delivery_status_at',
        'sms_failure_code', 'sms_failure_reason',
    ];

    protected $casts = [
        'fallback_used' => 'boolean',
        'attempt_count' => 'integer',
        'sms_delivery_status_at' => 'datetime',
    ];

    public function receipts(): HasMany
    {
        return $this->hasMany(OtpSmsDeliveryReceipt::class, 'otp_delivery_operation_id');
    }

    public function challenge(): BelongsTo
    {
        return $this->belongsTo(OtpChallenge::class, 'otp_challenge_id');
    }
}
