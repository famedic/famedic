<?php

namespace App\Http\Requests\Admin\LaboratoryAppointments;

use App\Enums\Gender;
use Carbon\Carbon;
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
            'appointment_time' => ['required', 'string', $this->appointmentTimeRule()],
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

    /**
     * @return \Closure(string, mixed, \Closure): void
     */
    private function appointmentTimeRule(): \Closure
    {
        return function (string $attribute, mixed $value, \Closure $fail): void {
            $v = (string) $value;

            if (str_contains($v, 'T') || str_contains($v, 'Z')) {
                try {
                    Carbon::parse($v);
                } catch (\Throwable) {
                    $fail(__('validation.date'));
                }

                return;
            }

            if (! preg_match('/^\d{1,2}:\d{2}$/', $v)) {
                $fail(__('validation.date_format', ['attribute' => $attribute, 'format' => 'H:i']));
            }
        };
    }
}
