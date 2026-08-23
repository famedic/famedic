<?php

namespace App\Models;

use App\Enums\PaymentAuthenticationAttemptStatus;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class PaymentAuthenticationAttempt extends Model
{
    use HasFactory;

    public const OPERATION_CARD_VERIFICATION_3DS = 'card_verification_3ds';

    public const PROVIDER_EFEVOOPAY = 'efevoopay';

    protected $guarded = [];

    protected $casts = [
        'started_at' => 'datetime',
        'last_provider_call_at' => 'datetime',
        'finished_at' => 'datetime',
        'expires_at' => 'datetime',
        'duplicate_request_count' => 'integer',
        'external_call_count' => 'integer',
        'provider_link_call_count' => 'integer',
        'status_poll_call_count' => 'integer',
        'tokenization_call_count' => 'integer',
        'attempt_number' => 'integer',
    ];

    public function durationSeconds(): ?int
    {
        if (! $this->started_at || ! $this->finished_at) {
            return null;
        }

        return max(0, (int) $this->started_at->diffInSeconds($this->finished_at));
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function efevoo3dsSession(): BelongsTo
    {
        return $this->belongsTo(Efevoo3dsSession::class, 'efevoo_3ds_session_id');
    }

    public function retryOfAttempt(): BelongsTo
    {
        return $this->belongsTo(self::class, 'retry_of_attempt_id');
    }

    public function retryAttempts(): HasMany
    {
        return $this->hasMany(self::class, 'retry_of_attempt_id');
    }

    public function events(): HasMany
    {
        return $this->hasMany(PaymentAuthenticationAttemptEvent::class)
            ->orderBy('occurred_at')
            ->orderBy('id');
    }

    public function recoveryContext(): BelongsTo
    {
        return $this->belongsTo(PaymentAuthenticationRecoveryContext::class, 'recovery_context_id');
    }

    public function displayContextType(): string
    {
        return $this->recoveryContext?->context_type?->value
            ?? \App\Enums\PaymentAuthenticationRecoveryContextType::UNKNOWN;
    }

    public function scopeForCustomer($query, int $customerId)
    {
        return $query->where('customer_id', $customerId);
    }

    public function scopeFailedBetween($query, $from, $to)
    {
        return $query->whereIn('status', [
            PaymentAuthenticationAttemptStatus::Declined->value,
            PaymentAuthenticationAttemptStatus::Cancelled->value,
            PaymentAuthenticationAttemptStatus::Expired->value,
            PaymentAuthenticationAttemptStatus::TechnicalError->value,
        ])->whereBetween('started_at', [$from, $to]);
    }

    public function scopeWithFailureCategory($query, string $category)
    {
        return $query->where('failure_category', $category);
    }

    public function isActive(): bool
    {
        if (! in_array($this->status, PaymentAuthenticationAttemptStatus::activeValues(), true)) {
            return false;
        }

        return $this->expires_at === null || $this->expires_at->isFuture();
    }

    public function isRecoverableTerminal(): bool
    {
        return in_array($this->status, PaymentAuthenticationAttemptStatus::recoverableTerminalValues(), true);
    }
}
