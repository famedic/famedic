<?php

namespace App\Http\Requests\LaboratoryAppointments;

use App\Enums\LaboratoryBrand;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

class UpdateLaboratoryAppointmentCallbackAvailabilityRequest extends FormRequest
{
    protected function prepareForValidation(): void
    {
        foreach (['callback_availability_starts_at', 'callback_availability_ends_at', 'patient_callback_comment'] as $key) {
            if ($this->input($key) === '') {
                $this->merge([$key => null]);
            }
        }
    }

    public function authorize(): bool
    {
        $appointment = $this->route('laboratory_appointment');
        $brand = $this->route('laboratory_brand');

        if (! $this->user()?->customer || ! $appointment || ! $brand instanceof LaboratoryBrand) {
            return false;
        }

        return $appointment->customer_id === $this->user()->customer->id
            && $appointment->brand === $brand;
    }

    public function rules(): array
    {
        return [
            'callback_availability_starts_at' => ['nullable', 'date'],
            'callback_availability_ends_at' => ['nullable', 'date'],
            'patient_callback_comment' => ['nullable', 'string', 'max:2000'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            $data = $validator->getData();
            $start = $data['callback_availability_starts_at'] ?? null;
            $end = $data['callback_availability_ends_at'] ?? null;
            if ($start && $end && strtotime((string) $end) <= strtotime((string) $start)) {
                $validator->errors()->add(
                    'callback_availability_ends_at',
                    'La hora final debe ser posterior a la inicial.'
                );
            }
            $hasFullWindow = filled($start) && filled($end);
            $hasPartialWindow = filled($start) xor filled($end);
            $hasComment = filled($data['patient_callback_comment'] ?? null);
            if ($hasPartialWindow) {
                $validator->errors()->add(
                    'callback_availability_starts_at',
                    'Indica inicio y fin del horario, o solo un comentario.'
                );
            } elseif (! $hasFullWindow && ! $hasComment) {
                $validator->errors()->add(
                    'callback_availability_starts_at',
                    'Indica el horario completo (desde y hasta) o un comentario.'
                );
            }
            if ($start && strtotime((string) $start) <= time()) {
                $validator->errors()->add(
                    'callback_availability_starts_at',
                    'El inicio debe ser posterior al momento actual.'
                );
            }
        });
    }
}
