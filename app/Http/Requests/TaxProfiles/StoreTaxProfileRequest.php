<?php

namespace App\Http\Requests\TaxProfiles;

use Illuminate\Foundation\Http\FormRequest;
use App\Rules\ValidRfc;
use Illuminate\Validation\Rule;

class StoreTaxProfileRequest extends FormRequest
{
    public function authorize()
    {
        return true;
    }

    public function rules()
    {
        $customerId = auth()->user()->customer->id;

        return [
            'name' => ['required', 'string', 'max:255'],
            //'rfc' => ['required', 'string', 'min:12', 'max:13', 'regex:/^[A-Z&Ñ]{3,4}[0-9]{6}[A-Z0-9]{3}$/'],
            /*'rfc' => [
                'required', 
                'string', 
                'max:13',
                // Opción A: Usar la regla personalizada
                new ValidRfc(),
                // Opción B: Usar regex directamente
                // 'regex:/^[A-ZÑ&]{3,4}[0-9]{6}[A-Z0-9]{3}$/',
                
                // Validar unicidad
                Rule::unique('tax_profiles')->where(function ($query) use ($customerId) {
                    return $query->where('customer_id', $customerId);
                }),
            ],*/
            'zipcode' => ['required', 'string', 'regex:/^\d{5}$/'],
            'tax_regime' => ['required', 'string'], # 'exists:tax_regimes,code'
            'fiscal_certificate' => ['required', 'file', 'mimes:pdf', 'max:5120'], // 5MB
            'confirm_data' => ['sometimes', 'boolean'],
            'extracted_data' => ['sometimes', 'string', 'json'],
        ];
    }

    public function messages()
    {
        return [
            'rfc.required' => 'El RFC es obligatorio.',
            'rfc.min' => 'El RFC debe tener al menos 12 caracteres.',
            'rfc.max' => 'El RFC no debe exceder 13 caracteres.',
            'rfc.regex' => 'El RFC tiene un formato inválido. Debe seguir el formato: XAXX010101XXX',
            'rfc.unique' => 'Ya tienes un perfil fiscal registrado con este RFC.',
            'zipcode.required' => 'El código postal es obligatorio.',
            'zipcode.regex' => 'El código postal debe tener exactamente 5 dígitos.',
            'tax_regime.required' => 'El régimen fiscal es obligatorio.',
            'tax_regime.exists' => 'El régimen fiscal seleccionado no es válido.',
            'fiscal_certificate.required' => 'La constancia fiscal es obligatoria.',
            'fiscal_certificate.file' => 'El archivo de constancia fiscal debe ser un archivo válido.',
            'fiscal_certificate.mimes' => 'La constancia fiscal debe ser un archivo PDF.',
            'fiscal_certificate.max' => 'La constancia fiscal no debe exceder 5MB.',
        ];
    }

    public function withValidator($validator)
    {
        $validator->after(function ($validator) {
            if ($this->has('rfc')) {
                $rfc = $this->input('rfc');
                
                // Validar longitud según tipo de persona
                if (strlen($rfc) === 12) {
                    // Persona Moral: 3 letras + 6 números + 3 alfanuméricos
                    if (!preg_match('/^[A-Z&Ñ]{3}[0-9]{6}[A-Z0-9]{3}$/', $rfc)) {
                        $validator->errors()->add('rfc', 'Para persona moral, el RFC debe tener formato: XXX999999XXX (12 caracteres)');
                    }
                } elseif (strlen($rfc) === 13) {
                    // Persona Física: 4 letras + 6 números + 3 alfanuméricos
                    if (!preg_match('/^[A-Z&Ñ]{4}[0-9]{6}[A-Z0-9]{3}$/', $rfc)) {
                        $validator->errors()->add('rfc', 'Para persona física, el RFC debe tener formato: XXXX999999XXX (13 caracteres)');
                    }
                } else {
                    $validator->errors()->add('rfc', 'El RFC debe tener 12 caracteres (persona moral) o 13 caracteres (persona física)');
                }
            }
        });
    }

    protected function prepareForValidation()
    {
        // Convertir RFC a mayúsculas y eliminar espacios
        if ($this->has('rfc')) {
            $this->merge([
                'rfc' => strtoupper(trim($this->rfc)),
            ]);
        }
    }
}