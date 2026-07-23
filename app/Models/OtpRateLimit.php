<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class OtpRateLimit extends Model
{
    public const BUCKET_IDENTITY = 'identity';

    public const BUCKET_IP = 'ip';

    protected $fillable = [
        'bucket_type',
        'bucket_key_hash',
        'purpose',
        'window_started_at',
        'request_count',
        'last_allowed_at',
        'blocked_until',
        'last_challenge_id',
    ];

    protected $hidden = [
        'bucket_key_hash',
    ];

    protected $casts = [
        'window_started_at' => 'datetime',
        'last_allowed_at' => 'datetime',
        'blocked_until' => 'datetime',
        'request_count' => 'integer',
        'last_challenge_id' => 'integer',
    ];

    public function lastChallenge(): BelongsTo
    {
        return $this->belongsTo(OtpChallenge::class, 'last_challenge_id');
    }

    public function isBlocked(): bool
    {
        return $this->blocked_until !== null && $this->blocked_until->isFuture();
    }
}
