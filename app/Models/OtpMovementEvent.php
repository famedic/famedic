<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

/**
 * Append-only OTP movement audit for admin monitoring.
 */
final class OtpMovementEvent extends Model
{
    public const UPDATED_AT = null;

    protected $fillable = [
        'occurred_at',
        'movement_key',
        'flow',
        'operation',
        'stage',
        'status',
        'channel',
        'destination_masked',
        'user_id',
        'customer_id',
        'challenge_public_id',
        'correlation_id',
        'idempotency_key_fingerprint',
        'provider_alias',
        'provider_result_class',
        'http_status',
        'attempt_number',
        'is_resend',
        'is_decoy',
        'is_replay',
        'is_idempotency_conflict',
        'endpoint',
        'error_code',
        'technical_message',
        'otp_challenge_id',
        'otp_delivery_operation_id',
        'meta',
        'created_at',
    ];

    protected $casts = [
        'occurred_at' => 'datetime',
        'created_at' => 'datetime',
        'meta' => 'array',
        'is_resend' => 'boolean',
        'is_decoy' => 'boolean',
        'is_replay' => 'boolean',
        'is_idempotency_conflict' => 'boolean',
        'attempt_number' => 'integer',
        'http_status' => 'integer',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function challenge(): BelongsTo
    {
        return $this->belongsTo(OtpChallenge::class, 'otp_challenge_id');
    }

    public function deliveryOperation(): BelongsTo
    {
        return $this->belongsTo(OtpDeliveryOperation::class, 'otp_delivery_operation_id');
    }

    public function save(array $options = []): bool
    {
        if ($this->exists) {
            throw new LogicException('OtpMovementEvent is append-only.');
        }

        return parent::save($options);
    }

    public function update(array $attributes = [], array $options = []): bool
    {
        throw new LogicException('OtpMovementEvent is append-only.');
    }

    public function delete(): ?bool
    {
        throw new LogicException('OtpMovementEvent is append-only.');
    }
}
