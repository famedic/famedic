<?php

namespace App\Http\Requests\Api\V1\Auth;

use App\Http\Requests\Api\V1\ApiFormRequest;
use App\Services\Otp\AkubicaLoginOtpService;

class LoginVerifyCodeRequest extends ApiFormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        if (AkubicaLoginOtpService::isEnabled()) {
            return [
                'challenge_id' => ['required', 'uuid'],
                'code' => ['required', 'string', 'size:6'],
            ];
        }

        return [
            'email' => ['required', 'string', 'email', 'max:255'],
            'code' => ['required', 'string', 'size:6'],
        ];
    }
}
