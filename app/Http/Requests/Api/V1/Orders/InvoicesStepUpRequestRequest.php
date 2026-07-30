<?php

namespace App\Http\Requests\Api\V1\Orders;

use App\Http\Requests\Api\V1\ApiFormRequest;

class InvoicesStepUpRequestRequest extends ApiFormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'phone' => ['prohibited'],
            'phone_country' => ['prohibited'],
            'user_id' => ['prohibited'],
            'customer_id' => ['prohibited'],
            'purpose' => ['prohibited'],
            'code' => ['prohibited'],
            'challenge_id' => ['prohibited'],
            'order_id' => ['prohibited'],
            'invoice_id' => ['prohibited'],
            'resource_id' => ['prohibited'],
            'resource_type' => ['prohibited'],
        ];
    }
}
