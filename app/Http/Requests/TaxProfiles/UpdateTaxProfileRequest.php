<?php

namespace App\Http\Requests\TaxProfiles;

use App\Rules\RFC;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateTaxProfileRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('update', $this->route('tax_profile'));
    }

    public function rules(): array
    {
        $cfdiUses = array_keys(config('taxregimes.uses', []));

        $rules = [
            'name' => ['required', 'string', 'max:255'],
            'rfc' => ['required', 'string', 'size:12,13', new RFC()],
            'zipcode' => ['required', 'string', 'size:5'],
            'tax_regime' => ['required', 'string'],
            'cfdi_use' => ['required', 'string', Rule::in($cfdiUses)],
            'extracted_data' => ['nullable', 'array'],
            'confirm_data' => ['required_if:extracted_data,!=,null', 'boolean'],
        ];

        if ($this->hasFile('fiscal_certificate')) {
            $rules['fiscal_certificate'] = ['file', 'mimes:pdf', 'max:5120'];
        } else {
            $rules['fiscal_certificate'] = ['sometimes', 'nullable'];
        }

        return $rules;
    }

    public function messages(): array
    {
        return [
            'cfdi_use.required' => 'El uso de CFDI es obligatorio.',
            'cfdi_use.in' => 'El uso de CFDI seleccionado no es válido.',
        ];
    }
}