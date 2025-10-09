<?php

namespace App\Models;

use App\Enums\VendorPaymentPurchaseType;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class VendorPayment extends Model
{
    use SoftDeletes;

    protected $guarded = [];

    protected $appends = [
        'formatted_paid_at',
        'paid_at_string',
        'formatted_total',
    ];

    protected function casts(): array
    {
        return [
            'paid_at' => 'date',
            'purchase_type' => VendorPaymentPurchaseType::class,
        ];
    }

    public function purchase()
    {
        return $this->morphTo();
    }

    public function onlinePharmacyPurchases()
    {
        return $this->morphedByMany(OnlinePharmacyPurchase::class, 'vendor_paymentable', 'vendor_paymentables');
    }

    public function laboratoryPurchases()
    {
        return $this->morphedByMany(LaboratoryPurchase::class, 'vendor_paymentable', 'vendor_paymentables');
    }

    protected function paidAtString(): Attribute
    {
        return Attribute::make(
            get: fn() => $this->paid_at?->format('Y-m-d'),
        );
    }

    protected function formattedPaidAt(): Attribute
    {
        return Attribute::make(
            get: fn() => $this->paid_at?->isoFormat('D [de] MMM [de] YYYY'),
        );
    }

    protected function formattedTotal(): Attribute
    {
        return Attribute::make(
            get: function () {
                $this->loadMissing([
                    'laboratoryPurchases.transactions',
                    'onlinePharmacyPurchases.transactions'
                ]);

                $purchases = $this->purchase_type === VendorPaymentPurchaseType::LABORATORY
                    ? $this->laboratoryPurchases
                    : $this->onlinePharmacyPurchases;

                if ($purchases->isEmpty()) {
                    return formattedCentsPrice(0);
                }

                $subtotalCents = $purchases->sum('total_cents');
                $commissionCents = 0;

                foreach ($purchases as $purchase) {
                    $transaction = $purchase->transactions->first();

                    if ($transaction) {
                        $commissionCents += $transaction->commission_cents;
                    }
                }

                $totalCents = $subtotalCents - $commissionCents;

                return formattedCentsPrice($totalCents);
            }
        );
    }

    public function scopeFilter(Builder $query, array $filters): Builder
    {
        return $query
            ->when($filters['search'] ?? null, function ($query, $search) {
                $query->where(function ($query) use ($search) {
                    $query
                        ->where(function ($q) use ($search) {
                            $q->where('purchase_type', VendorPaymentPurchaseType::LABORATORY)
                                ->whereHas('laboratoryPurchases', function ($r) use ($search) {
                                    $r->where('gda_order_id', 'like', "%{$search}%");
                                });
                        })
                        ->orWhere(function ($q) use ($search) {
                            $q->where('purchase_type', VendorPaymentPurchaseType::ONLINE_PHARMACY)
                                ->whereHas('onlinePharmacyPurchases', function ($r) use ($search) {
                                    $r->where('vitau_order_id', 'like', "%{$search}%");
                                });
                        });
                });
            })
            ->when($filters['start_date'] ?? null, function ($query, $startDate) {
                $query->whereDate('paid_at', '>=', $startDate);
            })
            ->when($filters['end_date'] ?? null, function ($query, $endDate) {
                $query->whereDate('paid_at', '<=', $endDate);
            });
    }
}
