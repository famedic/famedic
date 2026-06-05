<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class PaymentMethod extends Model
{
    protected $fillable = [
        'user_id',
        'provider',
        'provider_token',
        'brand',
        'last4',
        'exp_month',
        'exp_year',
        'affiliation_id',
        'media_id',
        'status',
        'alias',
        'card_holder',
        'created_from_transaction_id',
        'last_used_at',
    ];

    protected function casts(): array
    {
        return [
            'last_used_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function transactions(): HasMany
    {
        return $this->hasMany(PaymentTransaction::class);
    }

    public function createdFromTransaction(): BelongsTo
    {
        return $this->belongsTo(PaymentTransaction::class, 'created_from_transaction_id');
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('status', 'active');
    }

    public function scopeForProvider(Builder $query, string $provider): Builder
    {
        return $query->where('provider', $provider);
    }

    public function isExpired(): bool
    {
        if (! $this->exp_month || ! $this->exp_year) {
            return false;
        }

        $year = (int) (strlen((string) $this->exp_year) === 2
            ? ('20' . $this->exp_year)
            : $this->exp_year);
        $month = (int) $this->exp_month;

        return now()->greaterThan(
            now()->setDate($year, $month, 1)->endOfMonth()
        );
    }

    public function publicId(): string
    {
        return $this->provider . ':' . $this->id;
    }
}
