<?php

namespace App\Http\Requests\PaymentMethods;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StorePaymentMethodRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null && $this->user()->customer !== null;
    }
    
    public function rules(): array
    {
        return [
            'alias' => [
                'nullable',
                'string',
                'max:50',
            ],
            
            // Datos de tarjeta (para tokenización)
            'card_number' => [
                'required',
                'string',
                'regex:/^[0-9]{13,19}$/',
            ],
            'exp_month' => [
                'required',
                'string',
                'regex:/^(0[1-9]|1[0-2])$/',
            ],
            'exp_year' => [
                'required',
                'string',
                'regex:/^20[2-9][0-9]$/',
                function ($attribute, $value, $fail) {
                    $year = (int) $value;
                    $month = (int) $this->exp_month;
                    
                    $expiryDate = \Carbon\Carbon::createFromDate($year, $month, 1)->endOfMonth();
                    
                    if ($expiryDate->isPast()) {
                        $fail('La tarjeta ha expirado.');
                    }
                },
            ],
            'cvc' => [
                'required',
                'string',
                'regex:/^[0-9]{3,4}$/',
            ],
            
            // Opcional: datos del titular
            'cardholder_name' => [
                'nullable',
                'string',
                'max:100',
            ],
        ];
    }
    
    public function messages(): array
    {
        return [
            'card_number.required' => 'El número de tarjeta es requerido.',
            'card_number.regex' => 'El número de tarjeta no es válido.',
            'exp_month.required' => 'El mes de expiración es requerido.',
            'exp_month.regex' => 'El mes de expiración debe ser entre 01 y 12.',
            'exp_year.required' => 'El año de expiración es requerido.',
            'exp_year.regex' => 'El año de expiración no es válido.',
            'cvc.required' => 'El código CVC es requerido.',
            'cvc.regex' => 'El código CVC debe tener 3 o 4 dígitos.',
        ];
    }
    
    public function withValidator($validator): void
    {
        $validator->after(function ($validator) {
            // Validar algoritmo de Luhn para tarjetas
            if ($this->has('card_number') && !$this->validateLuhn($this->card_number)) {
                $validator->errors()->add('card_number', 'El número de tarjeta no es válido.');
            }
        });
    }
    
    private function validateLuhn(string $number): bool
    {
        $number = preg_replace('/\D/', '', $number);
        
        $sum = 0;
        $length = strlen($number);
        $parity = $length % 2;
        
        for ($i = 0; $i < $length; $i++) {
            $digit = (int) $number[$i];
            
            if ($i % 2 === $parity) {
                $digit *= 2;
                if ($digit > 9) {
                    $digit -= 9;
                }
            }
            
            $sum += $digit;
        }
        
        return $sum % 10 === 0;
    }
}