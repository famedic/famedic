<?php

namespace App\Http\Requests\Api\V1\Cart;

use App\Enums\LaboratoryBrand;
use App\Http\Requests\Api\V1\ApiFormRequest;
use Illuminate\Validation\Rule;

class GetCartRequest extends ApiFormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'service_line' => ['nullable', 'string', 'required_without:brand'],
            'brand' => ['nullable', Rule::enum(LaboratoryBrand::class), 'required_without:service_line'],
        ];
    }
}
