<?php

namespace App\Models;

use App\Support\PaymentAuthenticationAttemptEventQueryBuilder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PaymentAuthenticationAttemptEvent extends Model
{
    public $timestamps = false;

    protected $guarded = [];

    protected $hidden = [
        'metadata',
    ];

    protected $casts = [
        'metadata' => 'array',
        'occurred_at' => 'datetime',
        'created_at' => 'datetime',
        'external_call_number' => 'integer',
        'http_status' => 'integer',
        'duration_ms' => 'integer',
    ];

    public function newEloquentBuilder($query): PaymentAuthenticationAttemptEventQueryBuilder
    {
        return new PaymentAuthenticationAttemptEventQueryBuilder($query);
    }

    public function save(array $options = []): bool
    {
        if ($this->exists) {
            throw new \LogicException('Payment authentication attempt events are append-only.');
        }

        return parent::save($options);
    }

    public function update(array $attributes = [], array $options = []): bool
    {
        throw new \LogicException('Payment authentication attempt events are append-only.');
    }

    public function delete(): ?bool
    {
        throw new \LogicException('Payment authentication attempt events are append-only.');
    }

    public function forceDelete(): bool
    {
        throw new \LogicException('Payment authentication attempt events are append-only.');
    }

    public function attempt(): BelongsTo
    {
        return $this->belongsTo(PaymentAuthenticationAttempt::class, 'payment_authentication_attempt_id');
    }

    public function allowlistedMetadata(): array
    {
        $metadata = is_array($this->metadata) ? $this->metadata : [];
        $safe = [];

        foreach (\App\Support\PaymentAuthenticationAttemptRecorder::METADATA_ALLOWLIST as $key) {
            if (! array_key_exists($key, $metadata)) {
                continue;
            }

            $value = $metadata[$key];

            if (is_bool($value) || is_int($value) || is_float($value) || $value === null || is_string($value)) {
                $safe[$key] = $value;
            }
        }

        return $safe;
    }
}
