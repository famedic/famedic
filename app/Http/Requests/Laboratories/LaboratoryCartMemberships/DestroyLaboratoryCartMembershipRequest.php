<?php

namespace App\Http\Requests\Laboratories\LaboratoryCartMemberships;

use Illuminate\Foundation\Http\FormRequest;

class DestroyLaboratoryCartMembershipRequest extends FormRequest
{
    public function authorize(): bool
    {
        $customer = $this->user()?->customer;

        if (! $customer) {
            return false;
        }

        $brand = $this->route('laboratory_brand');

        return $customer->laboratoryCartMemberships()
            ->ofBrand($brand)
            ->exists();
    }

    public function rules(): array
    {
        return [];
    }
}
