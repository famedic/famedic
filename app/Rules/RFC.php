<?php

namespace App\Rules;

use Illuminate\Contracts\Validation\Rule;

class RFC implements Rule
{
    public function passes($attribute, $value)
    {
        $value = strtoupper(trim($value));
        
        // Validar formato básico de RFC
        if (!preg_match('/^[A-ZÑ&]{3,4}\d{6}[A-V1-9][A-Z1-9][0-9A]$/', $value)) {
            return false;
        }
        
        // Validar homoclave
        return $this->validarHomoclave($value);
    }
    
    private function validarHomoclave($rfc): bool
    {
        // Extraer los últimos 3 caracteres (homoclave)
        $homoclave = substr($rfc, -3);
        
        // La homoclave debe cumplir con ciertas reglas
        // Puedes agregar validaciones más específicas aquí
        
        return true;
    }
    
    public function message()
    {
        return 'El RFC no tiene un formato válido.';
    }
}