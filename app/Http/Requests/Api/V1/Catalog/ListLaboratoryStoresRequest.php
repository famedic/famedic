<?php

namespace App\Http\Requests\Api\V1\Catalog;

use App\Enums\LaboratoryBrand;
use App\Http\Requests\Api\V1\ApiFormRequest;
use Illuminate\Validation\Rule;

class ListLaboratoryStoresRequest extends ApiFormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'brand' => ['nullable', 'string', Rule::enum(LaboratoryBrand::class)],
            'state' => ['nullable', 'string', 'max:100'],
        ];
    }
}
