<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;

class OtpChallenge extends Model
{
    use HasFactory;

    public const STATUS_PENDING = 'pending';

    public const STATUS_EXPIRED = 'expired';

    public const STATUS_CONSUMED = 'consumed';

    public const STATUS_INVALIDATED = 'invalidated';

    protected $fillable = [
        'public_id',
        'user_id',
        'subject_type',
        'subject_key',
        'purpose',
        'channel',
        'destination_normalized',
        'destination_masked',
        'code_hash',
        'expires_at',
        'consumed_at',
        'invalidated_at',
        'invalidated_reason',
        'failed_attempts',
        'max_attempts',
        'send_count',
        'last_sent_at',
        'context_type',
        'context_id',
        'meta',
    ];

    protected $hidden = [
        'code_hash',
        'destination_normalized',
    ];

    protected $casts = [
        'expires_at' => 'datetime',
        'consumed_at' => 'datetime',
        'invalidated_at' => 'datetime',
        'last_sent_at' => 'datetime',
        'meta' => 'array',
        'failed_attempts' => 'integer',
        'max_attempts' => 'integer',
        'send_count' => 'integer',
        'context_id' => 'integer',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function registrationIntent(): HasOne
    {
        return $this->hasOne(AkubicaRegistrationIntent::class, 'otp_challenge_id');
    }

    public function isConsumed(): bool
    {
        return $this->consumed_at !== null;
    }

    public function isInvalidated(): bool
    {
        return $this->invalidated_at !== null;
    }

    public function isExpired(): bool
    {
        return $this->expires_at !== null && $this->expires_at->isPast();
    }

    public function isPending(): bool
    {
        return ! $this->isConsumed()
            && ! $this->isInvalidated()
            && ! $this->isExpired();
    }

    /**
     * Derived status precedence: consumed > invalidated > expired > pending.
     * (blocked deferred to P0-A3)
     */
    public function status(): string
    {
        if ($this->isConsumed()) {
            return self::STATUS_CONSUMED;
        }

        if ($this->isInvalidated()) {
            return self::STATUS_INVALIDATED;
        }

        if ($this->isExpired()) {
            return self::STATUS_EXPIRED;
        }

        return self::STATUS_PENDING;
    }

    /**
     * Active = pending, not consumed, not invalidated, expires_at > now().
     */
    public function scopeActiveFor(Builder $query): Builder
    {
        return $query
            ->whereNull('consumed_at')
            ->whereNull('invalidated_at')
            ->where('expires_at', '>', now());
    }
}
