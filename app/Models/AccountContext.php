<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AccountContext extends Model
{
    use HasFactory;

    protected $guarded = [];

    protected $hidden = [
        'token_hash',
        'phone_hash',
        'conversation_hash',
        'channel_event_hash',
    ];

    protected function casts(): array
    {
        return [
            'expires_at' => 'datetime',
            'verified_at' => 'datetime',
            'invalidated_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function isActive(): bool
    {
        return $this->invalidated_at === null
            && $this->expires_at !== null
            && $this->expires_at->isFuture();
    }
}
