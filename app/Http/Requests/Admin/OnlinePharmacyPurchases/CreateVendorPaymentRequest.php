<?php

namespace App\Http\Requests\Admin\OnlinePharmacyPurchases;

use Illuminate\Foundation\Http\FormRequest;

class CreateVendorPaymentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->administrator->hasPermissionTo('online-pharmacy-purchases.manage.vendor-payments');
    }

    public function rules(): array
    {
        return [];
    }
}
