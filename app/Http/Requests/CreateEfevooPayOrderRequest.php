<?php

namespace App\Http\Requests;

use App\Enums\LaboratoryBrand;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class CreateEfevooPayOrderRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }
    
    public function rules(): array
    {
        return [
            'laboratory_brand' => [
                'required',
                Rule::enum(LaboratoryBrand::class),
            ],
            'address' => [
                'required',
                'integer',
                'exists:addresses,id',
                function ($attribute, $value, $fail) {
                    // Verificar que la dirección pertenece al usuario
                    if (!$this->user()->customer->addresses()->where('id', $value)->exists()) {
                        $fail('La dirección seleccionada no es válida.');
                    }
                },
            ],
            'contact' => [
                'nullable',
                'integer',
                'exists:contacts,id',
                function ($attribute, $value, $fail) {
                    if ($value && !$this->user()->customer->contacts()->where('id', $value)->exists()) {
                        $fail('El contacto seleccionado no es válido.');
                    }
                },
            ],
            'payment_method' => [
                'required',
                'string',
                'in:efevoopay,odessa,stripe', // Mantener compatibilidad
            ],
            'total' => [
                'required',
                'integer',
                'min:100', // Mínimo $1.00 MXN
                'max:10000000', // Máximo $100,000 MXN
            ],
        ];
    }
    
    public function messages(): array
    {
        return [
            'laboratory_brand.required' => 'Debes seleccionar una marca de laboratorio.',
            'laboratory_brand.enum' => 'La marca de laboratorio seleccionada no es válida.',
            'address.required' => 'Debes seleccionar una dirección de envío.',
            'address.exists' => 'La dirección seleccionada no existe.',
            'contact.exists' => 'El contacto seleccionado no existe.',
            'payment_method.required' => 'Debes seleccionar un método de pago.',
            'payment_method.in' => 'El método de pago seleccionado no es válido.',
            'total.required' => 'El total es requerido.',
            'total.integer' => 'El total debe ser un número entero.',
            'total.min' => 'El total debe ser de al menos $1.00 MXN.',
            'total.max' => 'El total no puede exceder $100,000.00 MXN.',
        ];
    }
    
    public function withValidator($validator): void
    {
        $validator->after(function ($validator) {
            // Verificar que el carrito no esté vacío
            $customer = $this->user()->customer;
            $laboratoryBrand = LaboratoryBrand::tryFrom($this->input('laboratory_brand'));
            
            if ($laboratoryBrand) {
                $cartItemsCount = $customer->laboratoryCartItems()
                    ->ofBrand($laboratoryBrand)
                    ->count();
                
                if ($cartItemsCount === 0) {
                    $validator->errors()->add('cart', 'Tu carrito está vacío. Agrega productos antes de continuar.');
                }
            }
        });
    }
}