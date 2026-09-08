<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

final class OtpSmsDeliveryReceipt extends Model
{
    public const UPDATED_AT = null;

    protected $fillable = [
        'provider_message_id',
        'otp_delivery_operation_id',
        'receipt_status',
        'raw_status',
        'status_rank',
        'failure_code',
        'failure_reason',
        'idempotency_key',
        'received_at',
        'created_at',
    ];

    protected $casts = [
        'received_at' => 'datetime',
        'created_at' => 'datetime',
        'status_rank' => 'integer',
    ];

    public function deliveryOperation(): BelongsTo
    {
        return $this->belongsTo(OtpDeliveryOperation::class, 'otp_delivery_operation_id');
    }

    public function save(array $options = []): bool
    {
        if ($this->exists) {
            throw new LogicException('OtpSmsDeliveryReceipt is append-only.');
        }

        return parent::save($options);
    }

    public function update(array $attributes = [], array $options = []): bool
    {
        throw new LogicException('OtpSmsDeliveryReceipt is append-only.');
    }
}
