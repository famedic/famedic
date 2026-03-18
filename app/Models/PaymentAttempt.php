<?php

namespace App\Models;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PaymentAttempt extends Model
{
    protected $fillable = [
        'customer_id',
        'token_id',
        'amount_cents',
        'gateway',
        'reference',
        'status',
        'processor_code',
        'processor_message',
        'processor_transaction_id',
        'raw_response',
        'retry_count',
        'processed_at'
    ];

    protected $casts = [
        'raw_response' => 'array',
        'processed_at' => 'datetime'
    ];

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }
}
