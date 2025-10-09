<?php

namespace App\Http\Requests\InvoiceRequests;

use Illuminate\Foundation\Http\FormRequest;

class ShowInvoiceRequestRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('view', $this->invoice_request);
    }

    public function rules(): array
    {
        return [
            //
        ];
    }
}
