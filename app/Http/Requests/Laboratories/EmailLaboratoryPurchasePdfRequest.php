<?php

namespace App\Http\Requests\Laboratories;

use Illuminate\Foundation\Http\FormRequest;

class EmailLaboratoryPurchasePdfRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('view', $this->route('laboratory_purchase'));
    }

    public function rules(): array
    {
        return [
            'email' => ['required', 'email', 'max:255'],
        ];
    }

    public function messages(): array
    {
        return [
            'email.required' => 'El correo electrónico es requerido.',
            'email.email' => 'Por favor ingresa un correo electrónico válido.',
            'email.max' => 'El correo electrónico no puede exceder 255 caracteres.',
        ];
    }
}
