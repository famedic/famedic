<?php

namespace App\Http\Requests\Admin\LaboratoryAppointments;

use Illuminate\Foundation\Http\FormRequest;

class BulkDeleteOldLaboratoryAppointmentsRequest extends FormRequest
{
    public function authorize(): bool
    {
        $administrator = $this->user()?->administrator;

        return (bool) $administrator?->hasRole('Administrador');
    }

    public function rules(): array
    {
        return [
            'appointment_ids' => ['required', 'array', 'min:1', 'max:100'],
            'appointment_ids.*' => ['integer', 'distinct'],
        ];
    }
}
