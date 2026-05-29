<?php

namespace App\Http\Requests\Admin\LaboratoryAppointments;

use Illuminate\Foundation\Http\FormRequest;

class SendLaboratoryAppointmentEmailRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('update', $this->route('laboratory_appointment'));
    }

    public function rules(): array
    {
        return [];
    }
}
