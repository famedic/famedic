<?php

namespace App\Models;

use App\Enums\MedicalSubscriptionType;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

class MedicalAttentionSubscription extends Model
{
    use HasFactory, SoftDeletes;

    protected $guarded = [];

    protected $appends = [
        'formatted_price',
        'formatted_start_date',
        'formatted_end_date',
        'is_active',
    ];

    protected $casts = [
        'start_date' => 'date',
        'end_date' => 'date',
        'type' => MedicalSubscriptionType::class,
        'synced_with_murguia_at' => 'datetime',
    ];

    protected function formattedPrice(): Attribute
    {
        return Attribute::make(
            get: fn() => formattedCentsPrice($this->price_cents)
        );
    }

    protected function formattedStartDate(): Attribute
    {
        return Attribute::make(
            get: fn() => $this->start_date?->isoFormat('D [de] MMM [de] YYYY')
        );
    }

    protected function formattedEndDate(): Attribute
    {
        return Attribute::make(
            get: fn() => $this->end_date?->isoFormat('D [de] MMM [de] YYYY')
        );
    }

    protected function isActive(): Attribute
    {
        return Attribute::make(
            get: fn() => $this->start_date <= now() && $this->end_date >= now()
        );
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class)->withTrashed();
    }

    public function transactions()
    {
        return $this->morphToMany(Transaction::class, 'transactionable')->withTrashed();
    }

    public function parentSubscription(): BelongsTo
    {
        return $this->belongsTo(MedicalAttentionSubscription::class, 'parent_subscription_id');
    }

    public function scopeActive(Builder $query, $date = null): void
    {
        $date = $date ?? now();
        $query->where('start_date', '<=', $date)
            ->where('end_date', '>=', $date);
    }

    public function scopeFilter(Builder $query, array $filters): Builder
    {
        return $query
            ->when($filters['search'] ?? null, function ($query, $search) {
                $query->where(function ($query) use ($search) {
                    $query->whereHas('customer', function ($query) use ($search) {
                        $query->whereHas('user', function ($query) use ($search) {
                            $query->where('name', 'like', '%' . $search . '%')
                                ->orWhere('email', 'like', '%' . $search . '%')
                                ->orWhere('paternal_lastname', 'like', '%' . $search . '%')
                                ->orWhere('maternal_lastname', 'like', '%' . $search . '%');
                        });
                    })
                        ->orWhereHas('customer', function ($query) use ($search) {
                            $query->where('medical_attention_identifier', 'like', '%' . $search . '%');
                        });
                });
            })
            ->when($filters['status'] ?? null, function ($query, $status) {
                if ($status === 'active') {
                    $query->active();
                } elseif ($status === 'inactive') {
                    $query->where(function ($query) {
                        $query->where('end_date', '<', now())
                            ->orWhere('start_date', '>', now());
                    });
                }
            })
            ->when($filters['start_date'] ?? null, function ($query, $startDate) {
                $query->where('created_at', '>=', $startDate);
            })
            ->when($filters['end_date'] ?? null, function ($query, $endDate) {
                $query->where('created_at', '<=', $endDate . ' 23:59:59');
            })
            ->when(isset($filters['payment_method']) && $filters['payment_method'] !== '', function ($query) use ($filters) {
                $query->whereHas('transactions', function ($query) use ($filters) {
                    $query->where('payment_method', $filters['payment_method']);
                });
            });
    }
}
