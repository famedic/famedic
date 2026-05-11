<?php

namespace App\Http\Requests\LaboratoryAppointments;

use App\Enums\LaboratoryBrand;
use Illuminate\Contracts\Validation\Validator as ValidatorContract;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Log;
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
            Log::warning('laboratory.callback_availability.authorize_denied', [
                'reason' => 'missing_user_customer_or_route',
                'user_id' => $this->user()?->id,
                'has_customer' => (bool) $this->user()?->customer,
                'has_appointment' => (bool) $appointment,
                'brand_ok' => $brand instanceof LaboratoryBrand,
            ]);

            return false;
        }

        $allowed = $appointment->customer_id === $this->user()->customer->id
            && $appointment->brand === $brand;

        if (! $allowed) {
            Log::warning('laboratory.callback_availability.authorize_denied', [
                'reason' => 'customer_or_brand_mismatch',
                'appointment_id' => $appointment->id,
                'appointment_customer_id' => $appointment->customer_id,
                'request_customer_id' => $this->user()->customer->id,
                'appointment_brand' => $appointment->brand->value ?? (string) $appointment->brand,
                'route_brand' => $brand->value,
            ]);
        }

        return $allowed;
    }

    protected function failedValidation(ValidatorContract $validator): void
    {
        Log::warning('laboratory.callback_availability.validation_failed', [
            'appointment_id' => $this->route('laboratory_appointment')?->id,
            'errors' => $validator->errors()->toArray(),
            'payload' => $this->only([
                'callback_availability_starts_at',
                'callback_availability_ends_at',
                'patient_callback_comment',
            ]),
            'server_now' => now()->toIso8601String(),
        ]);

        parent::failedValidation($validator);
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
            /*
             * Usar $this->input() / filled() del FormRequest: en PATCH vía Inertia,
             * $validator->getData() puede no reflejar igual todos los campos del cuerpo
             * (falso positivo en xor de ventana parcial).
             */
            $start = $this->input('callback_availability_starts_at');
            $end = $this->input('callback_availability_ends_at');
            if ($start && $end && strtotime((string) $end) <= strtotime((string) $start)) {
                $validator->errors()->add(
                    'callback_availability_ends_at',
                    'La hora final debe ser posterior a la inicial.'
                );
            }
            $hasFullWindow = $this->filled('callback_availability_starts_at')
                && $this->filled('callback_availability_ends_at');
            /*
             * Paréntesis obligatorios: en PHP `xor` tiene menor precedencia que `=`, así que
             * `$a = $x xor $y` se interpreta como `($a = $x) xor $y` (el xor no forma parte del valor asignado).
             */
            $hasPartialWindow = ($this->filled('callback_availability_starts_at')
                xor $this->filled('callback_availability_ends_at'));
            $hasComment = $this->filled('patient_callback_comment');
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
