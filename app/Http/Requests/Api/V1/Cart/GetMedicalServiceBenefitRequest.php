<?php

namespace App\Http\Requests\Api\V1\Cart;

use App\Http\Requests\Api\V1\ApiFormRequest;

class GetMedicalServiceBenefitRequest extends ApiFormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'service_line' => ['required', 'string', 'in:medical_services'],
        ];
    }
}
