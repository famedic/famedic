<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StartPaymentAuthenticationRecoveryPayPalRequest extends FormRequest
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
            'session_id' => ['required', 'integer'],
            'recovery_context_uuid' => ['required', 'uuid'],
        ];
    }
}
