<?php

namespace App\Http\Requests\LaboratoryCheckout;

use Illuminate\Foundation\Http\FormRequest;

class StoreSelectedLaboratoryStoreRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'laboratory_store_id' => ['required', 'integer', 'exists:laboratory_stores,id'],
        ];
    }
}
