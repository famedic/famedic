<?php

namespace App\Http\Requests\TaxProfiles;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreTaxProfileRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'rfc' => ['required', 'string', 'max:255'],
            'zipcode' => ['required', 'string', 'max:255'],
            'tax_regime' => ['required', 'string', 'max:255', Rule::in(array_keys(config('taxregimes.regimes')))],
            'cfdi_use' => ['required', 'string', 'max:255', Rule::in(array_keys(config('taxregimes.uses')))],
            'fiscal_certificate' => ['required', 'file', 'mimes:pdf,png,jpeg'],
        ];
    }
}
