<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class OtpAbuseEvent extends Model
{
    public $timestamps = false;

    protected $fillable = [
        'decision',
        'error_code',
        'purpose',
        'identity_key_hash',
        'ip_key_hash',
        'scope',
        'retry_after_seconds',
        'available_at',
        'otp_challenge_id',
        'meta',
        'created_at',
    ];

    protected $hidden = [
        'identity_key_hash',
        'ip_key_hash',
    ];

    protected $casts = [
        'available_at' => 'datetime',
        'created_at' => 'datetime',
        'meta' => 'array',
        'retry_after_seconds' => 'integer',
        'otp_challenge_id' => 'integer',
    ];

    public function challenge(): BelongsTo
    {
        return $this->belongsTo(OtpChallenge::class, 'otp_challenge_id');
    }
}
