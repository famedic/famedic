<?php

namespace App\Http\Requests\ArcoSolicitudes;

use Illuminate\Foundation\Http\FormRequest;

class StoreArcoSolicitudRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $rules = [
            // Información personal
            'nombre_completo' => 'required|string|max:255',
            'fecha_nacimiento' => 'nullable|date|before:today',
            'rfc' => 'nullable|string|max:13|regex:/^[A-Z&Ñ]{3,4}[0-9]{6}[A-Z0-9]{3}$/',
            
            // Dirección
            'calle' => 'nullable|string|max:255',
            'numero_exterior' => 'nullable|string|max:10',
            'numero_interior' => 'nullable|string|max:10',
            'colonia' => 'nullable|string|max:255',
            'municipio_estado' => 'nullable|string|max:255',
            'codigo_postal' => 'nullable|string|max:5|regex:/^[0-9]{5}$/',
            
            // Contacto
            'telefono_fijo' => 'nullable|string|max:15|regex:/^[0-9]{10}$/',
            'telefono_celular' => 'required|string|max:15|regex:/^[0-9]{10}$/',
            
            // Derechos ARCO (al menos uno debe ser seleccionado)
            'derechos_arco' => 'required|array|min:1',
            'derechos_arco.*' => 'in:acceso,rectificacion,cancelacion,oposicion,revocacion',
            
            // Información de la solicitud
            'razon_solicitud' => 'required|string|min:20|max:2000',
            'solicitado_por' => 'required|in:titular,representante',
            
            // Identificación (opcional por ahora)
            'es_usuario' => 'required|in:si,no',
        ];

        return $rules;
    }

    public function messages(): array
    {
        return [
            'nombre_completo.required' => 'El nombre completo es obligatorio.',
            'telefono_celular.required' => 'El teléfono celular es obligatorio.',
            'telefono_celular.regex' => 'El teléfono celular debe tener 10 dígitos.',
            'telefono_fijo.regex' => 'El teléfono fijo debe tener 10 dígitos.',
            'codigo_postal.regex' => 'El código postal debe tener 5 dígitos.',
            'rfc.regex' => 'El RFC no tiene un formato válido.',
            'derechos_arco.required' => 'Debe seleccionar al menos un derecho ARCO.',
            'derechos_arco.min' => 'Debe seleccionar al menos un derecho ARCO.',
            'razon_solicitud.required' => 'La razón de la solicitud es obligatoria.',
            'razon_solicitud.min' => 'La razón de la solicitud debe tener al menos 20 caracteres.',
            'solicitado_por.required' => 'Debe especificar quién presenta la solicitud.',
            'es_usuario.required' => 'Debe indicar si es usuario FAMEDIC.',
        ];
    }

    public function attributes(): array
    {
        return [
            'nombre_completo' => 'nombre completo',
            'fecha_nacimiento' => 'fecha de nacimiento',
            'telefono_celular' => 'teléfono celular',
            'telefono_fijo' => 'teléfono fijo',
            'codigo_postal' => 'código postal',
            'rfc' => 'RFC',
            'derechos_arco' => 'derechos ARCO',
            'razon_solicitud' => 'razón de la solicitud',
            'solicitado_por' => 'solicitado por',
            'es_usuario' => 'es usuario FAMEDIC',
        ];
    }

    public function prepareForValidation()
    {
        // Limpiar espacios en blanco de los teléfonos
        if ($this->has('telefono_celular')) {
            $this->merge([
                'telefono_celular' => preg_replace('/\D/', '', $this->telefono_celular),
            ]);
        }
        
        if ($this->has('telefono_fijo')) {
            $this->merge([
                'telefono_fijo' => preg_replace('/\D/', '', $this->telefono_fijo),
            ]);
        }
        
        // Limpiar RFC (quitar espacios y convertir a mayúsculas)
        if ($this->has('rfc')) {
            $this->merge([
                'rfc' => strtoupper(preg_replace('/\s+/', '', $this->rfc)),
            ]);
        }
    }
}