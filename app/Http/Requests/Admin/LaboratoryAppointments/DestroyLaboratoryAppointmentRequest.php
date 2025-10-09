<?php

namespace App\Http\Requests\Admin\LaboratoryAppointments;

use Illuminate\Foundation\Http\FormRequest;

class DestroyLaboratoryAppointmentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('delete', $this->route('laboratory_appointment'));
    }

    public function rules(): array
    {
        return [
            //
        ];
    }
}
