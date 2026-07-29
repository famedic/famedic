<?php

namespace App\Http\Requests\Api\V1\Auth;

use App\Http\Requests\Api\V1\ApiFormRequest;
use App\Services\Otp\AkubicaLoginOtpService;

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
            ];
        }

        return [
            'email' => ['required', 'string', 'email', 'max:255'],
        ];
    }
}
