<?php

namespace App\Http\Requests\Api\V1\Orders;

use App\Http\Requests\Api\V1\ApiFormRequest;

class CreateInvoiceRequestRequest extends ApiFormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'tax_profile_id' => ['required', 'integer', 'min:1'],
            'cfdi_use' => ['nullable', 'string', 'max:10'],
            'email' => ['nullable', 'email', 'max:255'],
            'notes' => ['nullable', 'string', 'max:1000'],
        ];
    }
}
