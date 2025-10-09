<?php

namespace App\Http\Requests\Invoices;

use Illuminate\Foundation\Http\FormRequest;

class ShowInvoiceRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('view', $this->invoice->invoiceable);
    }

    public function rules(): array
    {
        return [
            //
        ];
    }
}
