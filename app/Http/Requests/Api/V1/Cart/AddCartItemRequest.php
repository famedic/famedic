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
            'service_line' => ['nullable', 'string', 'required_without:brand'],
            'item_type' => ['nullable', 'string'],
            'membership_plan_code' => ['nullable', 'string'],
            'quantity' => ['nullable', 'integer', 'min:1'],
            'laboratory_test_id' => ['nullable', 'integer', 'min:1', 'required_without:service_line'],
            'brand' => ['nullable', Rule::enum(LaboratoryBrand::class), 'required_without:service_line'],
            'price_cents' => ['prohibited'],
            'unit_price_cents' => ['prohibited'],
            'subtotal_cents' => ['prohibited'],
            'total_cents' => ['prohibited'],
        ];
    }
}
