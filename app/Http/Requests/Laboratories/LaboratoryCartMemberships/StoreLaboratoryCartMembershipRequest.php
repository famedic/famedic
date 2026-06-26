<?php

namespace App\Http\Requests\Laboratories\LaboratoryCartMemberships;

use Illuminate\Foundation\Http\FormRequest;

class StoreLaboratoryCartMembershipRequest extends FormRequest
{
    public function authorize(): bool
    {
        $customer = $this->user()?->customer;

        if (! $customer) {
            return false;
        }

        if ($customer->medical_attention_subscription_is_active) {
            return false;
        }

        $brand = $this->route('laboratory_brand');

        return $customer->laboratoryCartItems()
            ->ofBrand($brand)
            ->exists();
    }

    public function rules(): array
    {
        return [];
    }

    public function messages(): array
    {
        return [
            'authorize' => 'No puedes agregar la membresía en este momento.',
        ];
    }
}
