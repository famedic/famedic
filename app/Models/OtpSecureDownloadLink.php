<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Laravel\Sanctum\PersonalAccessToken;

class OtpSecureDownloadLink extends Model
{
    protected $fillable = [
        'public_id',
        'token_hash',
        'user_id',
        'personal_access_token_id',
        'otp_step_up_grant_id',
        'purpose',
        'resource_type',
        'resource_id',
        'expires_at',
        'max_opens',
        'open_count',
        'consumed_at',
        'revoked_at',
        'last_opened_at',
    ];

    protected $hidden = [
        'token_hash',
    ];

    protected function casts(): array
    {
        return [
            'user_id' => 'integer',
            'personal_access_token_id' => 'integer',
            'otp_step_up_grant_id' => 'integer',
            'resource_id' => 'integer',
            'max_opens' => 'integer',
            'open_count' => 'integer',
            'expires_at' => 'datetime',
            'consumed_at' => 'datetime',
            'revoked_at' => 'datetime',
            'last_opened_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function grant(): BelongsTo
    {
        return $this->belongsTo(OtpStepUpGrant::class, 'otp_step_up_grant_id');
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

    public function isExhausted(): bool
    {
        return $this->consumed_at !== null
            || (int) $this->open_count >= (int) $this->max_opens;
    }
}
