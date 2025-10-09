<?php

namespace App\Http\Requests\OnlinePharmacy\OnlinePharmacyCartItems;

use Illuminate\Foundation\Http\FormRequest;

class UpdateOnlinePharmacyCartItemRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('update', $this->route('online_pharmacy_cart_item'));
    }

    public function rules(): array
    {
        return [
            'quantity' => ['required', 'integer', 'min:1'],
        ];
    }
}
