<?php

namespace App\Http\Requests\Api\V1\Checkout;

use App\Enums\LaboratoryBrand;
use App\Http\Requests\Api\V1\ApiFormRequest;
use Illuminate\Validation\Rule;

class SyncCheckoutDraftRequest extends ApiFormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'brand' => ['required', Rule::enum(LaboratoryBrand::class)],
            'contact_id' => ['nullable', 'integer', 'min:1'],
            'address_id' => ['nullable', 'integer', 'min:1'],
            'requires_invoice' => ['nullable', 'boolean'],
            'tax_profile_id' => ['nullable', 'integer', 'min:1'],
            'notes' => ['nullable', 'string', 'max:1000'],
            'appointment_id' => ['nullable', 'integer', 'min:1'],
        ];
    }
}
