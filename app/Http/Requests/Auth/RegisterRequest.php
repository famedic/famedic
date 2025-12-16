<?php

namespace App\Http\Requests\Auth;

use App\Enums\Gender;
use App\Models\User;
use App\Rules\Recaptcha;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules;

class RegisterRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'name' => 'required|string|max:255',
            'paternal_lastname' => 'required|string|max:255',
            'maternal_lastname' => 'required|string|max:255',
            'email' => 'required|string|lowercase|email|max:255|unique:' . User::class,
            'phone' => 'required|phone|max:255|unique:' . User::class,
            'birth_date' => 'required|date|before:today',
            'gender' => ['required', Rule::enum(Gender::class)],
            'password' => ['required', 'confirmed', Rules\Password::defaults()],
            'referrer_id' => 'nullable|exists:users,id',
            'phone_country' => 'required|string|size:2', 
            'g_recaptcha_response' => ['required', new Recaptcha], 
        ];
    }

    /**
     * Get custom messages for validator errors.
     */
    public function messages(): array
    {
        return [
            'g_recaptcha_response.required' => 'Por favor, completa la verificación de seguridad.',
            'birth_date.before' => 'Debes ser mayor de 18 años para registrarte.',
            'phone.unique' => 'Este número de teléfono ya está registrado.',
            'email.unique' => 'Este correo electrónico ya está registrado.',
        ];
    }

    /**
     * Prepare the data for validation.
     */
    protected function prepareForValidation(): void
    {
        // Asegurarse de que phone_country siempre sea MX si está vacío
        if (empty($this->phone_country)) {
            $this->merge([
                'phone_country' => 'MX',
            ]);
        }

        // Limpiar espacios del teléfono
        if ($this->phone) {
            $this->merge([
                'phone' => preg_replace('/\s+/', '', $this->phone),
            ]);
        }
    }

    /**
     * Get custom attributes for validator errors.
     */
    public function attributes(): array
    {
        return [
            'name' => 'nombre',
            'paternal_lastname' => 'apellido paterno',
            'maternal_lastname' => 'apellido materno',
            'email' => 'correo electrónico',
            'phone' => 'teléfono',
            'birth_date' => 'fecha de nacimiento',
            'gender' => 'sexo',
            'password' => 'contraseña',
            'g_recaptcha_response' => 'verificación de seguridad',
        ];
    }
}