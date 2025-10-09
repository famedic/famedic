<?php

namespace App\Http\Requests\Admin\LaboratoryAppointments;

use App\Enums\Gender;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateLaboratoryAppointmentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('update', $this->route('laboratory_appointment'));
    }

    public function rules(): array
    {
        return [
            'appointment_date' => ['required', 'date'],
            'appointment_time' => ['required', 'date_format:H:i'],
            'patient_name' => ['required', 'string', 'max:255'],
            'patient_paternal_lastname' => ['required', 'string', 'max:255'],
            'patient_maternal_lastname' => ['required', 'string', 'max:255'],
            'patient_phone' => 'required|phone',
            'patient_phone_country' => 'required|string',
            'patient_birth_date' => 'required|date|before:today',
            'patient_gender' => ['required', Rule::enum(Gender::class)],
            'laboratory_store' => ['required', 'exists:laboratory_stores,id'],
            'notes' => ['nullable', 'string', 'max:255'],
        ];
    }
}
