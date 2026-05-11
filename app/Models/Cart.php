<?php

namespace App\Models;

use App\Enums\MonitoringCartStatus;
use App\Enums\MonitoringCartType;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Cart extends Model
{
    public const ABANDONED_AFTER_MINUTES = 30;

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'type' => MonitoringCartType::class,
            'status' => MonitoringCartStatus::class,
            'total' => 'decimal:2',
            'completed_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function items(): HasMany
    {
        return $this->hasMany(CartItem::class);
    }

    /**
     * Activo en carrito, abandonado (sin actividad), o comprado.
     */
    public function displayStatus(): string
    {
        if ($this->status === MonitoringCartStatus::Completed) {
            return 'completed';
        }

        if ($this->updated_at->lt(now()->subMinutes(self::ABANDONED_AFTER_MINUTES))) {
            return 'abandoned';
        }

        return 'active';
    }

    public function scopeDisplayStatusFilter($query, string $status): void
    {
        if ($status === 'completed') {
            $query->where('status', MonitoringCartStatus::Completed->value);
        } elseif ($status === 'abandoned') {
            $query->where('status', MonitoringCartStatus::Active->value)
                ->where('updated_at', '<', now()->subMinutes(self::ABANDONED_AFTER_MINUTES));
        } elseif ($status === 'active') {
            $query->where('status', MonitoringCartStatus::Active->value)
                ->where('updated_at', '>=', now()->subMinutes(self::ABANDONED_AFTER_MINUTES));
        }
    }
}
