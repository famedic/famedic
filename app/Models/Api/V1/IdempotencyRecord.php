<?php

namespace App\Models\Api\V1;

use Illuminate\Database\Eloquent\Model;

/**
 * Durable HTTP idempotency record for selected API v1 write routes.
 *
 * Stores encrypted response JSON only — never OTP, Authorization, or request bodies.
 */
class IdempotencyRecord extends Model
{
    public const STATUS_PROCESSING = 'processing';

    public const STATUS_COMPLETED = 'completed';

    public const STATUS_FAILED_FINAL = 'failed_final';

    public const STATUS_FAILED_UNCERTAIN = 'failed_uncertain';

    protected $table = 'api_v1_idempotency_records';

    protected $fillable = [
        'actor_key',
        'method',
        'path',
        'key_hash',
        'request_hash',
        'status',
        'http_status',
        'response_body',
        'response_headers',
        'correlation_id',
        'lease_expires_at',
        'expires_at',
    ];

    protected $hidden = [
        'response_body',
        'key_hash',
        'request_hash',
    ];

    protected function casts(): array
    {
        return [
            'http_status' => 'integer',
            'response_body' => 'encrypted',
            'response_headers' => 'array',
            'lease_expires_at' => 'datetime',
            'expires_at' => 'datetime',
        ];
    }

    public function isProcessing(): bool
    {
        return $this->status === self::STATUS_PROCESSING;
    }

    public function isCompleted(): bool
    {
        return $this->status === self::STATUS_COMPLETED;
    }

    public function isFailedFinal(): bool
    {
        return $this->status === self::STATUS_FAILED_FINAL;
    }

    public function isFailedUncertain(): bool
    {
        return $this->status === self::STATUS_FAILED_UNCERTAIN;
    }

    public function leaseIsActive(?\DateTimeInterface $now = null): bool
    {
        $now ??= now();

        return $this->lease_expires_at !== null
            && $this->lease_expires_at->greaterThan($now);
    }
}
