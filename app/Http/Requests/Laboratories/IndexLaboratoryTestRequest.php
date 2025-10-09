<?php

namespace App\Http\Requests\Laboratories;

use Illuminate\Foundation\Http\FormRequest;

class IndexLaboratoryTestRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'query' => 'nullable|string',
            'category' => 'nullable|exists:laboratory_test_categories,name',
            'page' => 'nullable|integer|min:1',
        ];
    }
}
