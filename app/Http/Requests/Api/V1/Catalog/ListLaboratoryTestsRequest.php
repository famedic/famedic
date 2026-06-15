<?php

namespace App\Http\Requests\Api\V1\Catalog;

use App\Enums\LaboratoryBrand;
use App\Http\Requests\Api\V1\ApiFormRequest;
use Illuminate\Validation\Rule;

class ListLaboratoryTestsRequest extends ApiFormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'brand' => ['nullable', 'string', Rule::enum(LaboratoryBrand::class)],
            'search' => ['nullable', 'string', 'max:255'],
            'category_id' => ['nullable', 'integer', 'min:1'],
            'page' => ['nullable', 'integer', 'min:1'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:50'],
            'requires_appointment' => ['nullable', 'boolean'],
        ];
    }
}
