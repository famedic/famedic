<?php

namespace App\Http\Requests\Admin\LaboratoryAppointments;

use Illuminate\Foundation\Http\FormRequest;

class ShowLaboratoryAppointmentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('view', $this->route('laboratory_appointment'));
    }

    public function rules(): array
    {
        return [
            //
        ];
    }
}
