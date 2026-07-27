<?php

namespace App\Http\Requests\Api\V1\Auth;

use App\Http\Requests\Api\V1\ApiFormRequest;
use App\Services\Otp\Registration\AkubicaRegistrationPolicy;

/**
 * Future secure-register verify (P0-A5). Accepts only challenge_id + code.
 * Rejects identity substitution fields with VALIDATION_ERROR.
 */
class SecureRegisterVerifyCodeRequest extends ApiFormRequest
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
        $length = AkubicaRegistrationPolicy::codeLength();

        return [
            'challenge_id' => ['required', 'uuid'],
            'code' => ['required', 'string', 'size:'.$length],
        ];
    }

    public function withValidator($validator): void
    {
        $validator->after(function ($validator) {
            foreach (self::FORBIDDEN_FIELDS as $field) {
                if ($this->exists($field)) {
                    $validator->errors()->add(
                        $field,
                        'Este campo no esta permitido en la verificacion.',
                    );
                }
            }
        });
    }
}
