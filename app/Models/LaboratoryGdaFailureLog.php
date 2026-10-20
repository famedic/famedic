<?php

namespace App\Models;

use App\Enums\LaboratoryBrand;
use App\Enums\LaboratoryGdaFailureOperation;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class LaboratoryGdaFailureLog extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'operation' => LaboratoryGdaFailureOperation::class,
            'brand' => LaboratoryBrand::class,
            'response_summary' => 'array',
            'context' => 'array',
        ];
    }

    public function laboratoryPurchase(): BelongsTo
    {
        return $this->belongsTo(LaboratoryPurchase::class);
    }

    public function sourceLaboratoryPurchase(): BelongsTo
    {
        return $this->belongsTo(LaboratoryPurchase::class, 'source_laboratory_purchase_id');
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function administratorUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'administrator_user_id');
    }

    public function scopeAdminIndex(Builder $query, array $filters = []): Builder
    {
        return $query
            ->with([
                'laboratoryPurchase.customer.user',
                'sourceLaboratoryPurchase',
                'administratorUser',
            ])
            ->when($filters['search'] ?? null, function (Builder $query, string $search) {
                $search = trim($search);
                if ($search === '') {
                    return;
                }

                $query->where(function (Builder $inner) use ($search) {
                    $inner->where('message', 'like', "%{$search}%")
                        ->orWhere('gda_description', 'like', "%{$search}%")
                        ->orWhere('failure_reason', 'like', "%{$search}%")
                        ->orWhere('requisition_value', 'like', "%{$search}%");

                    if (ctype_digit($search)) {
                        $inner->orWhere('laboratory_purchase_id', (int) $search)
                            ->orWhere('source_laboratory_purchase_id', (int) $search);
                    }

                    $inner->orWhereHas('customer.user', function (Builder $userQuery) use ($search) {
                        $userQuery->where('email', 'like', "%{$search}%")
                            ->orWhere('name', 'like', "%{$search}%");
                    });
                });
            })
            ->when($filters['operation'] ?? null, fn (Builder $q, string $operation) => $q->where('operation', $operation))
            ->when($filters['brand'] ?? null, fn (Builder $q, string $brand) => $q->where('brand', $brand))
            ->orderByDesc('id');
    }
}
