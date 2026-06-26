<?php

namespace App\Http\Requests\Laboratories\LaboratoryCartMemberships;

use App\Http\Requests\Concerns\ResolvesLaboratoryBrand;
use Illuminate\Foundation\Http\FormRequest;

class StoreLaboratoryCartMembershipRequest extends FormRequest
{
    use ResolvesLaboratoryBrand;

    public function authorize(): bool
    {
        $customer = $this->user()?->customer;
        $brand = $this->resolveLaboratoryBrand();

        if (! $customer || ! $brand) {
            return false;
        }

        if ($customer->medical_attention_subscription_is_active) {
            return false;
        }

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
