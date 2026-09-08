<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

final class OtpSmsDeliveryReceiptApplication extends Model
{
    public const UPDATED_AT = null;

    public const CREATED_AT = null;

    public $timestamps = false;

    protected $fillable = [
        'otp_sms_delivery_receipt_id',
        'otp_delivery_operation_id',
        'applied_at',
    ];

    protected $casts = [
        'applied_at' => 'datetime',
    ];

    public function receipt(): BelongsTo
    {
        return $this->belongsTo(OtpSmsDeliveryReceipt::class, 'otp_sms_delivery_receipt_id');
    }

    public function deliveryOperation(): BelongsTo
    {
        return $this->belongsTo(OtpDeliveryOperation::class, 'otp_delivery_operation_id');
    }

    public function save(array $options = []): bool
    {
        if ($this->exists) {
            throw new LogicException('OtpSmsDeliveryReceiptApplication is append-only.');
        }

        return parent::save($options);
    }

    public function update(array $attributes = [], array $options = []): bool
    {
        throw new LogicException('OtpSmsDeliveryReceiptApplication is append-only.');
    }
}
