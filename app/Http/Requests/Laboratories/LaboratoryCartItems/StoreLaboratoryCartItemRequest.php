<?php

namespace App\Http\Requests\Laboratories\LaboratoryCartItems;

use Illuminate\Foundation\Http\FormRequest;

class StoreLaboratoryCartItemRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'laboratory_test' => ['required', 'exists:laboratory_tests,id'],
        ];
    }
}
