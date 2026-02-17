<?php

namespace App\Http\Requests\PaymentMethods;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StorePaymentMethodRequest extends FormRequest
{
    public function authorize()
    {
        return true;
    }

    public function rules()
    {
        $currentYear = date('y');
        $currentMonth = date('m');

        return [
            'card_number' => [
                'required',
                'string',
                'size:16',
                'regex:/^[0-9]{16}$/',
            ],
            'exp_month' => [
                'required',
                'string',
                'size:2',
                'regex:/^(0[1-9]|1[0-2])$/',
            ],
            'exp_year' => [
                'required',
                'string',
                'size:2',
                'regex:/^[0-9]{2}$/',
                function ($attribute, $value, $fail) use ($currentYear, $currentMonth) {
                    $expMonth = request()->input('exp_month');

                    // Validar que no sea expirada
                    if (
                        $value < $currentYear ||
                        ($value == $currentYear && $expMonth < $currentMonth)
                    ) {
                        $fail('La tarjeta está expirada.');
                    }

                    // Validar que no sea muy futura (máximo 20 años)
                    if ($value > ($currentYear + 20)) {
                        $fail('El año de expiración es muy futuro.');
                    }
                },
            ],
            'cvv' => [
                'required',
                'string',
                'size:3',
                'regex:/^[0-9]{3}$/',
            ],
            'card_holder' => [
                'required',
                'string',
                'max:100',
                // CORREGIDO: Regex simplificado para evitar problemas con caracteres especiales
                function ($attribute, $value, $fail) {
                    // Validar manualmente en lugar de usar regex complejo
                    $cleaned = trim(strtoupper($value));
                    
                    // Verificar que solo contenga letras, espacios y algunos caracteres especiales
                    if (!preg_match('/^[A-Z\s\.\-\']+$/u', $cleaned)) {
                        $fail('El nombre solo puede contener letras, espacios, puntos, guiones y apóstrofes.');
                    }
                    
                    // Verificar longitud mínima
                    if (strlen($cleaned) < 2) {
                        $fail('El nombre debe tener al menos 2 caracteres.');
                    }
                },
            ],
            'alias' => 'nullable|string|max:50',
        ];
    }

    public function messages()
    {
        return [
            'card_number.required' => 'El número de tarjeta es requerido',
            'card_number.size' => 'El número de tarjeta debe tener 16 dígitos',
            'card_number.regex' => 'El número de tarjeta solo puede contener dígitos',

            'exp_month.required' => 'El mes de expiración es requerido',
            'exp_month.size' => 'El mes debe tener 2 dígitos',
            'exp_month.regex' => 'El mes debe estar entre 01 y 12',

            'exp_year.required' => 'El año de expiración es requerido',
            'exp_year.size' => 'El año debe tener 2 dígitos',
            'exp_year.regex' => 'El año solo puede contener dígitos',

            'cvv.required' => 'El CVV es requerido',
            'cvv.size' => 'El CVV debe tener 3 dígitos',
            'cvv.regex' => 'El CVV solo puede contener dígitos',

            'card_holder.required' => 'El nombre del titular es requerido',
            'card_holder.max' => 'El nombre no puede exceder 100 caracteres',
            'alias.max' => 'El alias no puede exceder 50 caracteres',
        ];
    }

    public function prepareForValidation()
    {
        // Combinar exp_month y exp_year para formato MMYY
        $expMonth = str_pad($this->exp_month, 2, '0', STR_PAD_LEFT);
        $expYear = str_pad($this->exp_year, 2, '0', STR_PAD_LEFT);

        $this->merge([
            'card_number' => preg_replace('/\s+/', '', $this->card_number),
            'card_holder' => strtoupper(trim($this->card_holder)),
            'exp_month' => $expMonth,
            'exp_year' => $expYear,
            'exp_year_short' => $expYear, // Añadir campo exp_year_short
            'expiration' => $expMonth . $expYear, // MMYY para el servicio
            'amount' => 1.50, // Monto fijo para tokenización
        ]);
    }
}