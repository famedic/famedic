<?php
// app/Http/Requests/EfevooPay/ProcessPaymentRequest.php

namespace App\Http\Requests\EfevooPay;

use Illuminate\Foundation\Http\FormRequest;

class ProcessPaymentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }
    
    public function rules(): array
    {
        return [
            'token_id' => 'nullable|integer|exists:efevoo_tokens,id',
            'amount' => 'required|numeric|min:0.01',
            'cav' => 'required|string|min:8|max:20|regex:/^[a-zA-Z0-9]+$/',
            'cvv' => 'required_if:token_id,null|string|size:3|regex:/^[0-9]+$/',
            'msi' => 'integer|in:0,3,6,9,12,18',
            'contrato' => 'nullable|string|min:5|max:16|regex:/^[a-zA-Z0-9]+$/',
            'fiid_comercio' => 'nullable|string',
            'referencia' => 'required|string|max:50',
            'description' => 'required|string|max:255',
            
            // Solo si no se usa token
            'card_number' => 'required_if:token_id,null|string|size:16|regex:/^[0-9]+$/',
            'expiration' => 'required_if:token_id,null|string|size:4|regex:/^[0-9]+$/',
            'card_holder' => 'required_if:token_id,null|string|max:100',
        ];
    }
    
    public function messages(): array
    {
        return [
            'cav.regex' => 'El CAV solo puede contener letras y números',
            'cvv.required_if' => 'El CVV es requerido cuando no se usa token',
            'cvv.regex' => 'El CVV solo puede contener dígitos',
            'contrato.regex' => 'El contrato solo puede contener letras y números',
            'card_number.required_if' => 'El número de tarjeta es requerido cuando no se usa token',
            'expiration.required_if' => 'La fecha de expiración es requerida cuando no se usa token',
        ];
    }
    
    public function withValidator($validator): void
    {
        $validator->after(function ($validator) {
            $data = $this->validated();
            
            // Verificar formato MMYY para expiración
            if (isset($data['expiration'])) {
                $month = substr($data['expiration'], 0, 2);
                $year = substr($data['expiration'], 2, 2);
                
                if ($month < 1 || $month > 12) {
                    $validator->errors()->add('expiration', 'El mes de expiración debe estar entre 01 y 12');
                }
                
                $currentYear = date('y');
                if ($year < $currentYear) {
                    $validator->errors()->add('expiration', 'La tarjeta está expirada');
                }
            }
            
            // Advertencia sobre montos altos en test
            if (config('efevoopay.environment') === 'test' && ($data['amount'] ?? 0) > 300) {
                $validator->errors()->add('amount', 
                    '⚠ ADVERTENCIA: En ambiente test se recomienda usar montos ≤ $300 MXN para evitar cargos reales');
            }
        });
    }
}