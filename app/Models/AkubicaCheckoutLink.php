<?php

namespace App\Models;

use App\Enums\LaboratoryBrand;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AkubicaCheckoutLink extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'laboratory_brand' => LaboratoryBrand::class,
            'expires_at' => 'datetime',
            'used_at' => 'datetime',
            'metadata' => 'array',
        ];
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public static function findByPlainToken(string $plainToken): ?self
    {
        return static::query()
            ->where('token_hash', hash('sha256', $plainToken))
            ->first();
    }

    public function isExpired(): bool
    {
        return $this->expires_at->isPast();
    }

    public function markUsed(): void
    {
        if ($this->used_at === null) {
            $this->update(['used_at' => now()]);
        }
    }
}
