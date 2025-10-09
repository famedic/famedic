<?php

namespace App\Http\Requests\Admin\LaboratoryPurchases;

use Illuminate\Foundation\Http\FormRequest;

class CreateVendorPaymentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->administrator->hasPermissionTo('laboratory-purchases.manage.vendor-payments');
    }

    public function rules(): array
    {
        return [];
    }
}
