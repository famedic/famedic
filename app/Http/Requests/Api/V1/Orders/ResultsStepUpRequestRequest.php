<?php

namespace App\Http\Requests\Api\V1\Orders;

use App\Http\Requests\Api\V1\ApiFormRequest;

/**
 * Step-up request body is intentionally empty of identity fields.
 * Phone is resolved from the authenticated user server-side.
 */
class ResultsStepUpRequestRequest extends ApiFormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            // Reject client-controlled identity / purpose substitution.
            'phone' => ['prohibited'],
            'phone_country' => ['prohibited'],
            'user_id' => ['prohibited'],
            'customer_id' => ['prohibited'],
            'purpose' => ['prohibited'],
            'code' => ['prohibited'],
            'challenge_id' => ['prohibited'],
            'order_id' => ['prohibited'],
            'resource_id' => ['prohibited'],
        ];
    }
}
