<?php

namespace App\Models;

use App\Enums\LaboratoryBrand;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class LaboratoryCheckoutDraft extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'laboratory_brand' => LaboratoryBrand::class,
            'selected_laboratory_store_validated_at' => 'datetime',
        ];
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function contact(): BelongsTo
    {
        return $this->belongsTo(Contact::class);
    }

    public function address(): BelongsTo
    {
        return $this->belongsTo(Address::class);
    }

    public function coupon(): BelongsTo
    {
        return $this->belongsTo(Coupon::class);
    }

    public function laboratoryStore(): BelongsTo
    {
        return $this->belongsTo(LaboratoryStore::class, 'selected_laboratory_store_id');
    }

    public function forCheckout(): array
    {
        return [
            'contact_id' => $this->contact_id ? (string) $this->contact_id : null,
            'address_id' => $this->address_id ? (string) $this->address_id : null,
            'payment_method' => $this->payment_method,
            'coupon_id' => $this->coupon_id,
            'promo_validation_token' => $this->promo_validation_token,
            'checkout_step' => $this->checkout_step,
            'postal_code' => $this->postal_code,
            'selected_laboratory_store_id' => $this->selected_laboratory_store_id
                ? (string) $this->selected_laboratory_store_id
                : null,
        ];
    }
}
