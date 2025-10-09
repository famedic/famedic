<?php

namespace App\Http\Requests\Admin\OnlinePharmacyPurchases;

use Illuminate\Foundation\Http\FormRequest;

class DestroyVendorPaymentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('delete', $this->route('vendor_payment'));
    }

    public function rules(): array
    {
        return [];
    }
}
