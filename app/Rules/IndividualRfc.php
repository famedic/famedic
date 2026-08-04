<?php

namespace App\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

class IndividualRfc implements ValidationRule
{
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! is_string($value) || $value === '') {
            $fail('El RFC es obligatorio.');

            return;
        }

        $rfc = strtoupper(trim($value));

        if (strlen($rfc) === 12) {
            $fail('El RFC corresponde a una persona moral. Famedic solo permite perfiles fiscales de personas físicas.');

            return;
        }

        if (strlen($rfc) !== 13 || ! preg_match('/^[A-ZÑ&]{4}[0-9]{6}[A-Z0-9]{3}$/', $rfc)) {
            $fail('El RFC debe tener 13 caracteres con formato válido de persona física (XXXX999999XXX).');
        }
    }
}
