<?php

namespace App\Models;

use Carbon\Carbon;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Propaganistas\LaravelPhone\Casts\RawPhoneNumberCast;

class OnlinePharmacyPurchase extends Model
{
    use HasFactory, SoftDeletes;

    protected $guarded = [];

    protected $appends = [
        'formatted_created_at',
        'formatted_expected_delivery_date',
        'formatted_subtotal',
        'formatted_shipping_price',
        'formatted_tax',
        'formatted_discount',
        'formatted_total',
        'formatted_commission',
        'full_name',
        'full_phone',
    ];

    protected function casts(): array
    {
        return [
            'expected_delivery_date' => 'date',
            'phone' => RawPhoneNumberCast::class.':country_field',
        ];
    }

    public function scopeFilter(Builder $query, array $filters): Builder
    {
        if (! isset($filters['deleted']) || $filters['deleted'] === '') {
            $query->withTrashed();
        } elseif ($filters['deleted'] === 'true') {
            $query->onlyTrashed();
        }

        return $query->when($filters['search'] ?? null, function ($query, $search) {
            $query->where(function ($query) use ($search) {
                $query->orWhere('vitau_order_id', 'LIKE', "%$search%")
                    ->orWhere('name', 'LIKE', "%$search%")
                    ->orWhere('paternal_lastname', 'LIKE', "%$search%")
                    ->orWhere('maternal_lastname', 'LIKE', "%$search%");

                $query->orWhereHas('customer.user', function ($query) use ($search) {
                    $query->where('name', 'LIKE', "%$search%")
                        ->orWhere('email', 'LIKE', "%$search%")
                        ->orWhere('phone', 'LIKE', "%$search%")
                        ->orWhere('maternal_lastname', 'LIKE', "%$search%")
                        ->orWhere('paternal_lastname', 'LIKE', "%$search%");
                });

                $query->orWhereHas('transactions', function ($query) use ($search) {
                    $query->where('reference_id', 'LIKE', "%$search%");
                });

                $query->orWhereHas('onlinePharmacyPurchaseItems', function ($query) use ($search) {
                    $query->where('name', 'LIKE', "%$search%");
                });
            });
        })
            ->when(isset($filters['payment_method']) && $filters['payment_method'] !== '', function ($query) use ($filters) {
                $query->whereHas('transactions', function ($query) use ($filters) {
                    $query->where('payment_method', $filters['payment_method']);
                });
            })
            ->when($filters['start_date'] ?? null, function ($query, $startDate) {
                $query->where('created_at', '>=', Carbon::parse($startDate, 'America/Monterrey')->setTimezone('UTC'));
            })
            ->when($filters['end_date'] ?? null, function ($query, $endDate) {
                $query->where('created_at', '<=', Carbon::parse($endDate, 'America/Monterrey')->endOfDay()->setTimezone('UTC'));
            })
            ->when(isset($filters['dev_assistance']) && $filters['dev_assistance'] !== '', function ($query) use ($filters) {
                if ($filters['dev_assistance'] === 'with_requests') {
                    $query->whereHas('devAssistanceRequests');
                } elseif ($filters['dev_assistance'] === 'with_open_requests') {
                    $query->whereHas('devAssistanceRequests', function ($query) {
                        $query->whereNull('resolved_at');
                    });
                } elseif ($filters['dev_assistance'] === 'no_requests') {
                    $query->whereDoesntHave('devAssistanceRequests');
                }
            });
    }

    public function customer()
    {
        return $this->belongsTo(Customer::class)->withTrashed();
    }

    public function transactions()
    {
        return $this->morphToMany(Transaction::class, 'transactionable')->withTrashed();
    }

    public function vendorPayments()
    {
        return $this->morphToMany(VendorPayment::class, 'vendor_paymentable');
    }

    public function invoice()
    {
        return $this->morphOne(Invoice::class, 'invoiceable')->withTrashed();
    }

    public function invoiceRequest()
    {
        return $this->morphOne(InvoiceRequest::class, 'invoice_requestable')->withTrashed();
    }

    public function onlinePharmacyPurchaseItems()
    {
        return $this->hasMany(OnlinePharmacyPurchaseItem::class)->withTrashed();
    }

    public function devAssistanceRequests()
    {
        return $this->morphMany(DevAssistanceRequest::class, 'dev_assistance_requestable');
    }

    protected function formattedExpectedDeliveryDate(): Attribute
    {
        return Attribute::make(
            get: fn () => $this->expected_delivery_date?->isoFormat('ddd D MMM Y'),
        );
    }

    protected function formattedCreatedAt(): Attribute
    {
        return Attribute::make(
            get: fn () => localizedDate($this->created_at)?->isoFormat('D MMM Y h:mm a')
        );
    }

    protected function formattedSubtotal(): Attribute
    {
        return Attribute::make(
            get: fn () => formattedCentsPrice($this->subtotal_cents),
        );
    }

    protected function formattedShippingPrice(): Attribute
    {
        return Attribute::make(
            get: fn () => formattedCentsPrice($this->shipping_price_cents),
        );
    }

    protected function formattedTax(): Attribute
    {
        return Attribute::make(
            get: fn () => formattedCentsPrice($this->tax_cents),
        );
    }

    protected function formattedDiscount(): Attribute
    {
        return Attribute::make(
            get: fn () => formattedCentsPrice($this->discount_cents),
        );
    }

    protected function formattedTotal(): Attribute
    {
        return Attribute::make(
            get: fn () => formattedCentsPrice($this->total_cents),
        );
    }

    protected function total(): Attribute
    {
        return Attribute::make(
            get: fn () => numberCents($this->total_cents)
        );
    }

    protected function fullName(): Attribute
    {
        return Attribute::make(
            get: fn () => $this->name.' '.$this->paternal_lastname.' '.$this->maternal_lastname
        );
    }

    protected function fullPhone(): Attribute
    {
        return Attribute::make(
            get: fn () => $this->phone?->formatE164()
        );
    }

    protected function formattedCommission(): Attribute
    {
        return Attribute::make(
            get: fn () => $this->transactions->first()?->formatted_commission ?? formattedCentsPrice(0)
        );
    }
}
