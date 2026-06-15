<?php

namespace App\Http\Requests\Api\V1\Checkout;

use App\Enums\LaboratoryBrand;
use App\Http\Requests\Api\V1\ApiFormRequest;
use Illuminate\Validation\Rule;

class GeneratePaymentLinkRequest extends ApiFormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $min = config('akubica.payment_link.min_expires_minutes', 5);
        $max = config('akubica.payment_link.max_expires_minutes', 1440);

        return [
            'brand' => ['required', Rule::enum(LaboratoryBrand::class)],
            'expires_in_minutes' => ['nullable', 'integer', "min:{$min}", "max:{$max}"],
        ];
    }

    protected function prepareForValidation(): void
    {
        if (! $this->has('expires_in_minutes')) {
            $this->merge([
                'expires_in_minutes' => config('akubica.payment_link.default_expires_minutes', 60),
            ]);
        }
    }
}
