<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ZohoSalesIqEvent extends Model
{
    protected $table = 'zoho_salesiq_events';

    protected $fillable = [
        'event_type',
        'visitor_id',
        'conversation_id',
        'user_id',
        'customer_id',
        'operator_name',
        'department',
        'intent',
        'last_event',
        'page',
        'environment',
        'payload',
        'occurred_at',
    ];

    protected function casts(): array
    {
        return [
            'payload' => 'array',
            'occurred_at' => 'datetime',
            'user_id' => 'integer',
            'customer_id' => 'integer',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }
}
