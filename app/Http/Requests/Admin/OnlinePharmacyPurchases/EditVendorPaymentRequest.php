<?php

namespace App\Http\Requests\Admin\OnlinePharmacyPurchases;

use Illuminate\Foundation\Http\FormRequest;

class EditVendorPaymentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('update', $this->route('vendor_payment'));
    }

    public function rules(): array
    {
        return [];
    }
}
