<?php

namespace App\Http\Requests\Api\V1\Orders;

use App\Http\Requests\Api\V1\ApiFormRequest;

class InvoicesSecureLinkRequest extends ApiFormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'grant_id' => ['required', 'uuid'],
            'phone' => ['prohibited'],
            'user_id' => ['prohibited'],
            'customer_id' => ['prohibited'],
            'purpose' => ['prohibited'],
            'resource_type' => ['prohibited'],
            'resource_id' => ['prohibited'],
            'order_id' => ['prohibited'],
            'invoice_id' => ['prohibited'],
            'token' => ['prohibited'],
        ];
    }
}
