<?php

namespace App\Http\Requests\Api\V1\Orders;

use App\Http\Requests\Api\V1\ApiFormRequest;

class InvoicesStepUpVerifyRequest extends ApiFormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'challenge_id' => ['required', 'uuid'],
            'code' => ['required', 'string', 'size:6'],
            'phone' => ['prohibited'],
            'user_id' => ['prohibited'],
            'customer_id' => ['prohibited'],
            'purpose' => ['prohibited'],
            'order_id' => ['prohibited'],
            'invoice_id' => ['prohibited'],
            'resource_id' => ['prohibited'],
            'resource_type' => ['prohibited'],
            'grant_id' => ['prohibited'],
        ];
    }
}
