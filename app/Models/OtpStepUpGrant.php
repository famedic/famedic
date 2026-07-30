<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Laravel\Sanctum\PersonalAccessToken;

class OtpStepUpGrant extends Model
{
    public const RESOURCE_LABORATORY_PURCHASE = 'laboratory_purchase';

    public const RESOURCE_INVOICE = 'invoice';

    protected $fillable = [
        'public_id',
        'user_id',
        'personal_access_token_id',
        'otp_challenge_id',
        'purpose',
        'resource_type',
        'resource_id',
        'granted_at',
        'expires_at',
        'revoked_at',
    ];

    protected function casts(): array
    {
        return [
            'user_id' => 'integer',
            'personal_access_token_id' => 'integer',
            'otp_challenge_id' => 'integer',
            'resource_id' => 'integer',
            'granted_at' => 'datetime',
            'expires_at' => 'datetime',
            'revoked_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function challenge(): BelongsTo
    {
        return $this->belongsTo(OtpChallenge::class, 'otp_challenge_id');
    }

    public function personalAccessToken(): BelongsTo
    {
        return $this->belongsTo(PersonalAccessToken::class, 'personal_access_token_id');
    }

    public function isRevoked(): bool
    {
        return $this->revoked_at !== null;
    }

    public function isExpired(?\DateTimeInterface $now = null): bool
    {
        $now ??= now();

        return $this->expires_at !== null && $this->expires_at->lessThanOrEqualTo($now);
    }

    public function isActive(?\DateTimeInterface $now = null): bool
    {
        return ! $this->isRevoked() && ! $this->isExpired($now);
    }

    /**
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    public function scopeActive(Builder $query, ?\DateTimeInterface $now = null): Builder
    {
        $now ??= now();

        return $query
            ->whereNull('revoked_at')
            ->where('expires_at', '>', $now);
    }
}
