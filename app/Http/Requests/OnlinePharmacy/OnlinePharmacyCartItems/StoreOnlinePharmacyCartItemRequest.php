<?php

namespace App\Http\Requests\OnlinePharmacy\OnlinePharmacyCartItems;

use Illuminate\Foundation\Http\FormRequest;

class StoreOnlinePharmacyCartItemRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'vitau_product' => ['required', 'integer'],
        ];
    }
}
