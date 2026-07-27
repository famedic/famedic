<?php

namespace App\Http\Requests\Api\V1\Auth;

use App\Http\Requests\Api\V1\ApiFormRequest;

/**
 * Future secure-register resend (P0-A5). Accepts only challenge_id.
 */
class SecureRegisterResendCodeRequest extends ApiFormRequest
{
    /** @var list<string> */
    private const FORBIDDEN_FIELDS = [
        'email',
        'phone',
        'full_name',
        'phone_country',
        'purpose',
        'user_id',
        'account_type',
        'code',
        'destination',
        'subject',
        'context',
    ];

    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'challenge_id' => ['required', 'uuid'],
        ];
    }

    public function withValidator($validator): void
    {
        $validator->after(function ($validator) {
            foreach (self::FORBIDDEN_FIELDS as $field) {
                if ($this->exists($field)) {
                    $validator->errors()->add(
                        $field,
                        'Este campo no esta permitido en el reenvio.',
                    );
                }
            }
        });
    }
}
