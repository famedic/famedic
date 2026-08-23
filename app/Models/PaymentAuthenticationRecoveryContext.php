<?php

namespace App\Models;

use App\Enums\PaymentAuthenticationRecoveryContextStatus;
use App\Enums\PaymentAuthenticationRecoveryContextType;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class PaymentAuthenticationRecoveryContext extends Model
{
    use HasFactory;

    protected $guarded = [];

    protected $hidden = [
        'id',
        'customer_id',
        'context_data',
        'cart_id',
        'root_authentication_attempt_id',
        'recovered_by_authentication_attempt_id',
        'recovered_transaction_id',
        'recovery_method',
    ];

    protected $casts = [
        'context_type' => PaymentAuthenticationRecoveryContextType::class,
        'status' => PaymentAuthenticationRecoveryContextStatus::class,
        'context_data' => 'array',
        'started_at' => 'datetime',
        'expires_at' => 'datetime',
        'card_verified_at' => 'datetime',
        'recovered_at' => 'datetime',
        'cancelled_at' => 'datetime',
    ];

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function cart(): BelongsTo
    {
        return $this->belongsTo(Cart::class);
    }

    public function authenticationAttempts(): HasMany
    {
        return $this->hasMany(PaymentAuthenticationAttempt::class, 'recovery_context_id');
    }

    public function rootAuthenticationAttempt(): BelongsTo
    {
        return $this->belongsTo(PaymentAuthenticationAttempt::class, 'root_authentication_attempt_id');
    }

    public function recoveredByAuthenticationAttempt(): BelongsTo
    {
        return $this->belongsTo(PaymentAuthenticationAttempt::class, 'recovered_by_authentication_attempt_id');
    }

    public function recoveredTransaction(): BelongsTo
    {
        return $this->belongsTo(Transaction::class, 'recovered_transaction_id');
    }

    public function recoveryTransaction(): BelongsTo
    {
        return $this->belongsTo(Transaction::class, 'recovery_transaction_id');
    }

    public function isExpired(): bool
    {
        return $this->status === PaymentAuthenticationRecoveryContextStatus::Expired
            || ($this->expires_at !== null && $this->expires_at->isPast());
    }

    public function isReusable(): bool
    {
        if ($this->isExpired()) {
            return false;
        }

        return in_array(
            $this->status?->value ?? $this->status,
            PaymentAuthenticationRecoveryContextStatus::reusableValues(),
            true
        );
    }

    public function canAttachAttempt(): bool
    {
        if ($this->isExpired()) {
            return false;
        }

        return in_array(
            $this->status?->value ?? $this->status,
            PaymentAuthenticationRecoveryContextStatus::attachableValues(),
            true
        );
    }

    public function allowlistedContextData(): array
    {
        return is_array($this->context_data) ? $this->context_data : [];
    }

    public function contextDataValue(string $key, mixed $default = null): mixed
    {
        return $this->allowlistedContextData()[$key] ?? $default;
    }
}
