<?php

namespace App\Models;

use App\Enums\CartEventType;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CartEvent extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'event' => CartEventType::class,
            'metadata' => 'array',
            'occurred_at' => 'datetime',
        ];
    }

    public function cart(): BelongsTo
    {
        return $this->belongsTo(Cart::class);
    }
}
