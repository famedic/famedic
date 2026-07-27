<?php

namespace App\Http\Requests\Api\V1\Auth;

use App\Http\Requests\Api\V1\ApiFormRequest;
use App\Services\Otp\Registration\EmailNormalizer;
use App\Services\Otp\Registration\MexicoPhoneNormalizer;
use App\Services\Otp\Registration\RegistrationIdentity;
use Propaganistas\LaravelPhone\PhoneNumber;

/**
 * Future secure-register start request (P0-A5). Not wired while flag is OFF.
 * Fields mirror RegisterAkubicaCustomerAction: email, phone, full_name, phone_country.
 */
class SecureRegisterRequest extends ApiFormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'email' => ['required', 'string', 'email', 'max:255'],
            'phone' => ['required', 'string', 'max:30'],
            'full_name' => ['required', 'string', 'min:3', 'max:255'],
            'phone_country' => ['sometimes', 'string', 'size:2'],
        ];
    }

    public function withValidator($validator): void
    {
        $validator->after(function ($validator) {
            if ($validator->errors()->isNotEmpty()) {
                return;
            }

            try {
                app(EmailNormalizer::class)->normalize((string) $this->input('email'));
            } catch (\Throwable) {
                $validator->errors()->add('email', 'El correo electrónico no es válido.');
            }

            $phoneCountry = $this->input('phone_country', MexicoPhoneNormalizer::DEFAULT_COUNTRY);

            try {
                app(MexicoPhoneNormalizer::class)->normalize(
                    (string) $this->input('phone'),
                    is_string($phoneCountry) ? $phoneCountry : MexicoPhoneNormalizer::DEFAULT_COUNTRY,
                );
            } catch (\Throwable) {
                $validator->errors()->add('phone', 'El teléfono no tiene un formato válido.');
            }
        });
    }

    protected function prepareForValidation(): void
    {
        if ($this->has('email')) {
            $this->merge([
                'email' => trim((string) $this->input('email')),
            ]);
        }

        if ($this->phone && str_starts_with((string) $this->phone, '+')) {
            try {
                $phoneNumber = PhoneNumber::make($this->phone);
                $this->merge([
                    'phone_country' => $phoneNumber->getCountry() ?: MexicoPhoneNormalizer::DEFAULT_COUNTRY,
                    'phone' => preg_replace('/\s+/', '', (string) $this->phone),
                ]);
            } catch (\Throwable) {
                // Still strip spaces for digit-first normalizer.
                $this->merge([
                    'phone' => preg_replace('/\s+/', '', (string) $this->phone),
                    'phone_country' => $this->input('phone_country') ?: MexicoPhoneNormalizer::DEFAULT_COUNTRY,
                ]);
            }

            return;
        }

        if (empty($this->phone_country)) {
            $this->merge(['phone_country' => MexicoPhoneNormalizer::DEFAULT_COUNTRY]);
        }

        if ($this->phone) {
            $this->merge([
                'phone' => preg_replace('/\s+/', '', (string) $this->phone),
            ]);
        }
    }

    public function registrationIdentity(): RegistrationIdentity
    {
        $email = app(EmailNormalizer::class)->normalize((string) $this->validated('email'));
        $phone = app(MexicoPhoneNormalizer::class)->normalize(
            (string) $this->validated('phone'),
            (string) ($this->validated('phone_country') ?? MexicoPhoneNormalizer::DEFAULT_COUNTRY),
        );

        return new RegistrationIdentity(
            email: $email,
            phone: $phone,
            fullName: trim((string) $this->validated('full_name')),
        );
    }
}
