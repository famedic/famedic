<?php

namespace App\Http\Requests\Laboratories\LaboratoryPurchases;

use Illuminate\Foundation\Http\FormRequest;

class StoreInvoiceRequestRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('update', $this->laboratory_purchase);
    }

    public function rules(): array
    {
        return [
            'tax_profile' => ['required', 'exists:tax_profiles,id,customer_id,' . auth()->user()->customer->id],
        ];
    }
}
