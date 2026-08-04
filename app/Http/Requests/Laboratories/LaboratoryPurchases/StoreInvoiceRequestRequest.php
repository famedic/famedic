<?php

namespace App\Http\Requests\Laboratories\LaboratoryPurchases;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreInvoiceRequestRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('update', $this->laboratory_purchase);
    }

    public function rules(): array
    {
        $customerId = $this->user()->customer->id;
        $cfdiUses = array_keys(config('taxregimes.uses', []));

        return [
            'tax_profile' => [
                'required',
                Rule::exists('tax_profiles', 'id')->where(fn ($query) => $query
                    ->where('customer_id', $customerId)
                    ->whereNull('deleted_at')),
            ],
            'cfdi_use' => ['required', 'string', Rule::in($cfdiUses)],
        ];
    }

    public function messages(): array
    {
        return [
            'tax_profile.required' => 'Debes seleccionar un perfil fiscal.',
            'tax_profile.exists' => 'El perfil fiscal seleccionado no es válido.',
            'cfdi_use.required' => 'El uso de CFDI es obligatorio.',
            'cfdi_use.in' => 'El uso de CFDI seleccionado no es válido.',
        ];
    }
}
