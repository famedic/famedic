<?php

namespace App\Http\Requests\PaymentMethods;

use App\Support\PaymentAuthenticationRecoveryPolicy;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StartPaymentAuthenticationRecoveryRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->customer !== null;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'session_id' => ['required', 'integer'],
            'recovery_context_uuid' => ['required', 'uuid'],
            'recovery_action' => ['required', 'string', Rule::in([
                PaymentAuthenticationRecoveryPolicy::ACTION_RETRY,
                PaymentAuthenticationRecoveryPolicy::ACTION_DIFFERENT_CARD,
            ])],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'recovery_action.in' => 'La acción de recuperación no es válida.',
        ];
    }
}
