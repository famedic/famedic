<?php

namespace App\Http\Requests\Api\V1\Auth;

use App\Http\Requests\Api\V1\ApiFormRequest;
use Propaganistas\LaravelPhone\PhoneNumber;

class RegisterRequest extends ApiFormRequest
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

            $phoneCountry = $this->input('phone_country', 'MX');

            try {
                new PhoneNumber($this->input('phone'), $phoneCountry);
            } catch (\Throwable) {
                $validator->errors()->add('phone', 'El teléfono no tiene un formato válido.');
            }
        });
    }

    protected function prepareForValidation(): void
    {
        if ($this->phone && str_starts_with((string) $this->phone, '+')) {
            try {
                $phoneNumber = PhoneNumber::make($this->phone);
                $this->merge([
                    'phone_country' => $phoneNumber->getCountry(),
                    'phone' => preg_replace('/\s+/', '', $this->phone),
                ]);
            } catch (\Throwable) {
                // La validación posterior reportará el error.
            }

            return;
        }

        if (empty($this->phone_country)) {
            $this->merge(['phone_country' => 'MX']);
        }

        if ($this->phone) {
            $this->merge([
                'phone' => preg_replace('/\s+/', '', $this->phone),
            ]);
        }
    }
}
