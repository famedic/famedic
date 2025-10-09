<?php

namespace App\Http\Requests\OnlinePharmacy;

use Illuminate\Foundation\Http\FormRequest;

class OnlinePharmacySearchRequest extends FormRequest
{
    public function authorize()
    {
        return true;
    }

    public function rules()
    {
        return [
            'search' => 'nullable|string',
            'category' => 'nullable|string',
            'page' => 'nullable|integer|min:1',
        ];
    }
}
