<?php

namespace App\Http\Requests\Auth;

use App\Enums\Gender;
use App\Models\User;
use App\Rules\Recaptcha;
use App\Data\StatesMexico;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules;
use Illuminate\Support\Facades\Log;

class RegisterRequest extends FormRequest
{
    /**
     * Determina si el usuario está autorizado para hacer esta solicitud.
     */
    public function authorize(): bool
    {
        Log::info('🔐 RegisterRequest: authorize() iniciado', [
            'ip' => $this->ip(),
            'user_agent' => $this->userAgent(),
            'method' => $this->method(),
            'url' => $this->url(),
        ]);
        
        return true; // Todos pueden registrar
    }

    public function rules(): array
    {
        Log::info('📋 RegisterRequest: rules() iniciado', [
            'tiene_datos' => !empty($this->all()),
            'campos_recibidos' => array_keys($this->all()),
            'recaptcha_presente' => isset($this->g_recaptcha_response),
        ]);

        $rules = [
            'name' => 'required|string|max:255',
            'paternal_lastname' => 'required|string|max:255',
            'maternal_lastname' => 'required|string|max:255',
            'email' => 'required|string|lowercase|email|max:255|unique:' . User::class,
            'phone' => 'required|phone|max:255|unique:' . User::class,
            'birth_date' => 'required|date|before:today',
            'gender' => ['required', Rule::enum(Gender::class)],
            'state' => ['nullable', 'string', 'size:2', 'in:' . implode(',', StatesMexico::claves())],
            'password' => ['required', 'confirmed', Rules\Password::defaults()],
            'referrer_id' => 'nullable|exists:users,id',
            'phone_country' => 'required|string|size:2',             
            // 'g_recaptcha_response' => ['required', new Recaptcha], // COMENTADO TEMPORALMENTE
            'g_recaptcha_response' => 'nullable', 
        ];

        Log::debug('📝 RegisterRequest: Reglas definidas', [
            'total_reglas' => count($rules),
            'campos_con_reglas' => array_keys($rules),
        ]);

        return $rules;
    }

    /**
     * Get custom messages for validator errors.
     */
    public function messages(): array
    {
        return [
            'g_recaptcha_response.required' => 'Por favor, completa la verificación de seguridad.',
            'birth_date.before' => 'Debes ser mayor de 18 años para registrarte.',
            'phone.unique' => 'Este número de teléfono ya está registrado.',
            'email.unique' => 'Este correo electrónico ya está registrado.',
            'state.in' => 'El estado seleccionado no es válido.',
            'name.required' => 'El nombre es obligatorio.',
            'paternal_lastname.required' => 'El apellido paterno es obligatorio.',
            'maternal_lastname.required' => 'El apellido materno es obligatorio.',
            'email.required' => 'El correo electrónico es obligatorio.',
            'email.email' => 'El correo electrónico no tiene un formato válido.',
            'phone.required' => 'El teléfono es obligatorio.',
            'birth_date.required' => 'La fecha de nacimiento es obligatoria.',
            'birth_date.date' => 'La fecha de nacimiento no tiene un formato válido.',
            'gender.required' => 'El sexo es obligatorio.',
            'password.required' => 'La contraseña es obligatoria.',
            'password.confirmed' => 'Las contraseñas no coinciden.',
        ];
    }

    /**
     * Prepare the data for validation.
     */
    protected function prepareForValidation(): void
    {
        Log::info('🔧 RegisterRequest: prepareForValidation() iniciado', [
            'datos_originales' => $this->all(),
            'phone_country_original' => $this->phone_country ?? 'NO_PRESENTE',
            'phone_original' => $this->phone ?? 'NO_PRESENTE',
        ]);

        // Asegurarse de que phone_country siempre sea MX si está vacío
        if (empty($this->phone_country)) {
            Log::debug('🌎 RegisterRequest: phone_country vacío, asignando MX por defecto');
            $this->merge([
                'phone_country' => 'MX',
            ]);
        }

        // Limpiar espacios del teléfono
        if ($this->phone) {
            $phoneOriginal = $this->phone;
            $phoneLimpio = preg_replace('/\s+/', '', $this->phone);
            
            Log::debug('📞 RegisterRequest: Limpiando teléfono', [
                'original' => $phoneOriginal,
                'limpio' => $phoneLimpio,
                'cambio' => $phoneOriginal !== $phoneLimpio,
            ]);
            
            $this->merge([
                'phone' => $phoneLimpio,
            ]);
        }

        Log::debug('✅ RegisterRequest: Datos después de prepareForValidation', [
            'datos_finales' => $this->all(),
        ]);
    }

    /**
     * Get custom attributes for validator errors.
     */
    public function attributes(): array
    {
        return [
            'name' => 'nombre',
            'paternal_lastname' => 'apellido paterno',
            'maternal_lastname' => 'apellido materno',
            'email' => 'correo electrónico',
            'phone' => 'teléfono',
            'birth_date' => 'fecha de nacimiento',
            'gender' => 'sexo',
            'state' => 'estado',
            'password' => 'contraseña',
            'password_confirmation' => 'confirmación de contraseña',
            'g_recaptcha_response' => 'verificación de seguridad',
            'referrer_id' => 'usuario referidor',
            'phone_country' => 'país del teléfono',
        ];
    }

    /**
     * Configurar el validador.
     */
    

    /**
     * Método para debugging adicional.
     */
    public function validateResolved(): void
    {
        Log::info('🚀 RegisterRequest: validateResolved() - Inicio de validación completa', [
            'todos_los_datos' => $this->all(),
            'claves_estados' => StatesMexico::claves(),
            'total_estados' => count(StatesMexico::claves()),
        ]);

        parent::validateResolved();

        Log::info('🎯 RegisterRequest: validateResolved() - Validación completada', [
            'datos_validados' => $this->validated(),
            'autorizado' => $this->authorize(),
        ]);
    }

    /**
     * Obtener los datos validados con logging.
     */
    public function validated($key = null, $default = null)
    {
        $validated = parent::validated($key, $default);
        
        if ($key === null) {
            Log::info('📄 RegisterRequest: Datos validados obtenidos', [
                'total_campos' => count($validated),
                'campos' => array_keys($validated),
                'tiene_email' => isset($validated['email']),
                'tiene_phone' => isset($validated['phone']),
            ]);
        }

        return $validated;
    }
}