<?php

namespace App\Http\Requests\Api\V1\Cart;

use App\Enums\LaboratoryBrand;
use App\Http\Requests\Api\V1\ApiFormRequest;
use Illuminate\Validation\Rule;

class AddCartItemRequest extends ApiFormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'laboratory_test_id' => ['required', 'integer', 'min:1'],
            'brand' => ['required', Rule::enum(LaboratoryBrand::class)],
        ];
    }
}
