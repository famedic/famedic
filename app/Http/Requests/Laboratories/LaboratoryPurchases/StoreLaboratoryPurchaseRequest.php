<?php

namespace App\Http\Requests\Laboratories\LaboratoryPurchases;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreLaboratoryPurchaseRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Prepara los datos antes de la validación
     */
    protected function prepareForValidation(): void
    {
        // Convertir payment_method a string si viene como integer
        $this->normalizePaymentMethod();
    }

    public function rules(): array
    {
        $allowedMethods = $this->getAllowedPaymentMethods();
        
        \Log::info('Validando compra de laboratorio', [
            'customer_id' => auth()->id(),
            'allowed_methods' => $allowedMethods,
            'payment_method_received' => $this->input('payment_method'),
            'payment_method_type' => gettype($this->input('payment_method')),
        ]);

        return [
            'total' => 'required|numeric|min:0',
            'address' => [
                'required',
                'exists:addresses,id',
                function ($attribute, $value, $fail) {
                    if ($value) {
                        $address = auth()->user()->customer->addresses()->find($value);
                        if (!$address) {
                            $fail('La dirección seleccionada no es válida.');
                        }
                    }
                },
            ],
            'contact' => [
                'nullable',
                'exists:contacts,id',
                function ($attribute, $value, $fail) {
                    if ($value) {
                        $contact = auth()->user()->customer->contacts()->find($value);
                        if (!$contact) {
                            $fail('El contacto seleccionado no es válido.');
                        }
                    }
                },
            ],
            'laboratory_appointment' => [
                'nullable', 
                'exists:laboratory_appointments,id,customer_id,' . auth()->user()->customer->id
            ],
            'payment_method' => [
                'required', 
                Rule::in($allowedMethods) // QUITAR 'string' de aquí
            ],
        ];
    }

    /**
     * Normaliza el campo payment_method a string
     */
    private function normalizePaymentMethod(): void
    {
        if ($this->has('payment_method')) {
            $value = $this->input('payment_method');
            
            // Si es numérico, convertirlo a string
            if (is_numeric($value)) {
                $this->merge([
                    'payment_method' => (string) $value,
                ]);
                
                \Log::debug('Payment method normalizado', [
                    'original' => $value,
                    'normalized' => (string) $value,
                    'customer_id' => auth()->id(),
                ]);
            }
        }
    }

    private function getAllowedPaymentMethods(): array
    {
        $customer = auth()->user()->customer;
        $allowedPaymentMethods = [];

        // Obtener tokens activos de EfevooPay
        $tokens = $customer->efevooTokens()
            ->active()
            ->get();
        
        foreach ($tokens as $token) {
            $allowedPaymentMethods[] = (string) $token->id;
            
            \Log::debug('Token permitido', [
                'token_id' => $token->id,
                'token_id_string' => (string) $token->id,
                'card_last_four' => $token->card_last_four,
                'alias' => $token->alias,
            ]);
        }

        // Agregar Odessa si está disponible
        if ($customer->has_odessa_afiliate_account) {
            $allowedPaymentMethods[] = 'odessa';
            
            \Log::debug('Odessa permitido', [
                'customer_id' => $customer->id,
                'has_odessa_account' => true,
            ]);
        }

        // Log final
        \Log::info('Métodos de pago permitidos generados', [
            'customer_id' => $customer->id,
            'total_methods' => count($allowedPaymentMethods),
            'methods' => $allowedPaymentMethods,
            'has_tokens' => $tokens->count(),
            'has_odessa' => $customer->has_odessa_afiliate_account,
        ]);

        return $allowedPaymentMethods;
    }
    
    /**
     * Mensajes de error personalizados
     */
    public function messages(): array
    {
        return [
            'payment_method.required' => 'Debes seleccionar un método de pago.',
            'payment_method.in' => 'El método de pago seleccionado no es válido o ha expirado.',
            'address.required' => 'Debes seleccionar una dirección de envío.',
            'address.exists' => 'La dirección seleccionada no existe.',
            'total.required' => 'El total es requerido.',
            'total.numeric' => 'El total debe ser un número válido.',
            'total.min' => 'El total debe ser mayor a 0.',
        ];
    }
}