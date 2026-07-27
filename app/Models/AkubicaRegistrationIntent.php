<?php

namespace App\Models;

use App\Enums\AkubicaRegistrationIntentInvalidationReason;
use App\Enums\AkubicaRegistrationIntentStatus;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;

class AkubicaRegistrationIntent extends Model
{
    use HasFactory;

    protected $table = 'akubica_registration_intents';

    /**
     * Mass-assignment limited to schema columns. Public HTTP must not create rows;
     * use AkubicaRegistrationIntentService (forceFill / explicit attributes).
     *
     * @var list<string>
     */
    protected $fillable = [
        'otp_challenge_id',
        'status',
        'encrypted_payload',
        'payload_version',
        'email_fingerprint',
        'expires_at',
        'consumed_at',
        'invalidated_at',
        'invalidation_reason',
        'superseded_by_id',
    ];

    /**
     * @var list<string>
     */
    protected $hidden = [
        'encrypted_payload',
        'email_fingerprint',
    ];

    /**
     * @var array<string, string>
     */
    protected $casts = [
        'status' => AkubicaRegistrationIntentStatus::class,
        'invalidation_reason' => AkubicaRegistrationIntentInvalidationReason::class,
        'payload_version' => 'integer',
        'expires_at' => 'datetime',
        'consumed_at' => 'datetime',
        'invalidated_at' => 'datetime',
        // Intentionally NO encrypted cast — decrypt only via PayloadCipher.
    ];

    public function otpChallenge(): BelongsTo
    {
        return $this->belongsTo(OtpChallenge::class, 'otp_challenge_id');
    }

    public function supersededBy(): BelongsTo
    {
        return $this->belongsTo(self::class, 'superseded_by_id');
    }

    public function supersedes(): HasOne
    {
        return $this->hasOne(self::class, 'superseded_by_id');
    }

    public function isPending(): bool
    {
        return $this->status === AkubicaRegistrationIntentStatus::Pending;
    }

    public function isTerminal(): bool
    {
        return $this->status->isTerminal();
    }

    public function hasUsablePayload(): bool
    {
        return $this->isPending()
            && $this->encrypted_payload !== null
            && $this->encrypted_payload !== ''
            && $this->expires_at !== null
            && $this->expires_at->isFuture();
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        $array = parent::toArray();
        unset($array['encrypted_payload'], $array['email_fingerprint']);

        return $array;
    }
}
