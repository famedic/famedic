<?php

namespace App\Http\Requests\Api\V1\Auth;

use App\Http\Requests\Api\V1\ApiFormRequest;
use App\Services\Otp\AkubicaLoginOtpService;
use Illuminate\Contracts\Validation\Validator;

class LoginRequestCodeRequest extends ApiFormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        if (AkubicaLoginOtpService::isEnabled()) {
            return [
                'phone' => ['required', 'string', 'max:32'],
                'phone_country' => ['sometimes', 'nullable', 'string', 'size:2'],
                'conversation_id' => ['sometimes', 'string', 'max:128'],
                'channel_event_id' => ['sometimes', 'string', 'max:128'],
                'channel' => ['sometimes', 'string', 'max:32'],
                'personalization' => ['sometimes', 'array'],
                'personalization.allowed' => ['sometimes', 'boolean'],
            ];
        }

        return [
            'email' => ['required', 'string', 'email', 'max:255'],
        ];
    }

    protected function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            if (! AkubicaLoginOtpService::isEnabled()) {
                return;
            }

            $contextFields = ['conversation_id', 'channel_event_id', 'channel'];
            $present = collect($contextFields)->filter(fn (string $field): bool => $this->filled($field))->count();

            if ($present === 0) {
                return;
            }

            foreach ($contextFields as $field) {
                if (! $this->filled($field)) {
                    $validator->errors()->add($field, 'El contexto conversacional requiere todos sus identificadores.');
                }
            }
        });
    }
}
