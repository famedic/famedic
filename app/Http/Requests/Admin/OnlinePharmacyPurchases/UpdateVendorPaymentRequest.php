<?php

namespace App\Http\Requests\Admin\OnlinePharmacyPurchases;

use Illuminate\Foundation\Http\FormRequest;

class UpdateVendorPaymentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->administrator->hasPermissionTo('online-pharmacy-purchases.manage.vendor-payments');
    }

    public function rules(): array
    {
        return [
            'purchase_ids' => ['required', 'array', 'min:1'],
            'purchase_ids.*' => ['integer', 'exists:online_pharmacy_purchases,id'],
            'proof' => ['nullable', 'file', 'mimes:pdf,png,jpg,jpeg', 'max:10240'],
            'paid_at' => ['required', 'date'],
        ];
    }
}
