<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PaymentAttempt extends Model
{
    public const STATUS_PENDING = 'pending';

    public const STATUS_PROCESSING = 'processing';

    public const STATUS_APPROVED = 'approved';

    public const STATUS_DECLINED = 'declined';

    public const STATUS_ERROR = 'error';

    /**
     * Reserved for post-approval refunds (automatic or manual).
     * Not written by the charge flow yet — kept so transitions can preserve it later.
     */
    public const STATUS_REFUNDED = 'refunded';

    protected $fillable = [
        'customer_id',
        'token_id',
        'cart_id',
        'amount_cents',
        'gateway',
        'reference',
        'status',
        'processor_code',
        'processor_message',
        'processor_transaction_id',
        'raw_response',
        'retry_count',
        'processed_at',
    ];

    protected $casts = [
        'raw_response' => 'array',
        'processed_at' => 'datetime',
    ];

    /**
     * Outcomes that must not be overwritten by a later catch block.
     *
     * @return list<string>
     */
    public static function finalizedStatuses(): array
    {
        return [
            self::STATUS_APPROVED,
            self::STATUS_DECLINED,
            self::STATUS_ERROR,
            self::STATUS_REFUNDED,
        ];
    }

    public function isFinalized(): bool
    {
        return in_array($this->status, self::finalizedStatuses(), true);
    }

    /**
     * Prepared for future refund automation after an approved charge.
     * Not called from the charge flow yet.
     */
    public function markAsRefunded(?string $message = null): bool
    {
        if ($this->status !== self::STATUS_APPROVED && $this->status !== self::STATUS_REFUNDED) {
            return false;
        }

        $this->update([
            'status' => self::STATUS_REFUNDED,
            'processor_message' => $message ?? $this->processor_message,
            'processed_at' => now(),
        ]);

        return true;
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function cart(): BelongsTo
    {
        return $this->belongsTo(Cart::class);
    }
}
