<?php

namespace App\Http\Requests\OnlinePharmacy\OnlinePharmacyCartItems;

use Illuminate\Foundation\Http\FormRequest;

class DestroyOnlinePharmacyCartItemRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('delete', $this->route('online_pharmacy_cart_item'));
    }

    public function rules(): array
    {
        return [];
    }
}
