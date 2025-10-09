<?php

namespace App\Http\Requests\TaxProfiles;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateTaxProfileRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('update', $this->tax_profile);
    }

    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'rfc' => ['required', 'string', 'max:255'],
            'zipcode' => ['required', 'string', 'max:255'],
            'tax_regime' => ['required', 'string', 'max:255', Rule::in(array_keys(config('taxregimes.regimes')))],
            'cfdi_use' => ['required', 'string', 'max:255', Rule::in(array_keys(config('taxregimes.uses')))],
            'fiscal_certificate' => ['file', 'nullable', 'mimes:pdf,png,jpeg'],
        ];
    }
}
