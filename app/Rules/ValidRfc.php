<?php

namespace App\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

class ValidRfc implements ValidationRule
{
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (!$this->isValidRfc($value)) {
            $fail('El RFC no tiene un formato válido.');
        }
    }
    
    private function isValidRfc($rfc): bool
    {
        if (!is_string($rfc) || empty($rfc)) {
            return false;
        }
        
        $rfc = strtoupper(trim($rfc));
        
        // Patrones para RFC
        // Persona física: 4 letras + 6 números + 3 caracteres alfanuméricos
        // Persona moral: 3 letras + 6 números + 3 caracteres alfanuméricos
        $patternFisica = '/^[A-ZÑ&]{4}[0-9]{6}[A-Z0-9]{3}$/';
        $patternMoral = '/^[A-ZÑ&]{3}[0-9]{6}[A-Z0-9]{3}$/';
        
        // Validar longitud (12 para moral, 13 para física)
        if (strlen($rfc) !== 12 && strlen($rfc) !== 13) {
            return false;
        }
        
        return preg_match($patternFisica, $rfc) || preg_match($patternMoral, $rfc);
    }
}