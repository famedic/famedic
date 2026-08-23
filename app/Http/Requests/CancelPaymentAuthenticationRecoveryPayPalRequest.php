<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class CancelPaymentAuthenticationRecoveryPayPalRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'recovery_context_uuid' => ['required', 'uuid'],
            'transaction_id' => ['nullable', 'integer', 'exists:transactions,id'],
            'provider_order_id' => ['nullable', 'string', 'max:255'],
        ];
    }
}
