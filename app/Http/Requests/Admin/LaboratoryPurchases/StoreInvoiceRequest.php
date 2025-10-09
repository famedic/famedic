<?php

namespace App\Http\Requests\Admin\LaboratoryPurchases;

use Illuminate\Foundation\Http\FormRequest;

class StoreInvoiceRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('update', $this->laboratory_purchase);
    }

    public function rules(): array
    {
        return [
            'invoice' => 'required|file|mimes:pdf|max:10240',
        ];
    }
}
