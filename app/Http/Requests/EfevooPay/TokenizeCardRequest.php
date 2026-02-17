<?php
// app/Http/Requests/EfevooPay/TokenizeCardRequest.php

namespace App\Http\Requests\EfevooPay;

use Illuminate\Foundation\Http\FormRequest;

class TokenizeCardRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }
    
    public function rules(): array
    {
        return [
            'card_number' => 'required|string|size:16|regex:/^[0-9]+$/',
            'expiration' => 'required|string|size:4|regex:/^[0-9]+$/',
            'card_holder' => 'required|string|max:100',
            'amount' => 'required|numeric|min:0.01|max:300', // Máx $3.00 para pruebas
            'save_token' => 'boolean',
        ];
    }
    
    public function messages(): array
    {
        return [
            'card_number.size' => 'El número de tarjeta debe tener 16 dígitos',
            'card_number.regex' => 'El número de tarjeta solo puede contener dígitos',
            'expiration.size' => 'La fecha de expiración debe tener 4 dígitos (MMYY)',
            'expiration.regex' => 'La fecha de expiración solo puede contener dígitos',
            'amount.max' => 'El monto máximo para tokenización es de $300 MXN',
        ];
    }
}