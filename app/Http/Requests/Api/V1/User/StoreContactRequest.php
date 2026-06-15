<?php

namespace App\Http\Requests\Api\V1\User;

use App\Enums\Gender;
use App\Http\Requests\Api\V1\ApiFormRequest;
use Illuminate\Validation\Rule;
use Propaganistas\LaravelPhone\PhoneNumber;

class StoreContactRequest extends ApiFormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'paternal_lastname' => ['required', 'string', 'max:255'],
            'maternal_lastname' => ['required', 'string', 'max:255'],
            'phone' => ['required', 'phone'],
            'phone_country' => ['required', 'string'],
            'birth_date' => ['required', 'date', 'before:today'],
            'gender' => ['required', Rule::enum(Gender::class)],
        ];
    }

    protected function prepareForValidation(): void
    {
        if ($this->gender === 'male') {
            $this->merge(['gender' => Gender::MALE->value]);
        } elseif ($this->gender === 'female') {
            $this->merge(['gender' => Gender::FEMALE->value]);
        }

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

        if ($this->phone) {
            $this->merge([
                'phone' => preg_replace('/\s+/', '', $this->phone),
            ]);
        }
    }
}
