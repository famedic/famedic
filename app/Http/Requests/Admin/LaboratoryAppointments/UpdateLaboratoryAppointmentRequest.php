<?php

namespace App\Http\Requests\Admin\LaboratoryAppointments;

use App\Enums\Gender;
use App\Models\LaboratoryAppointment;
use App\Models\LaboratoryStore;
use App\Services\LaboratoryRequirements\BranchResolver;
use App\Services\LaboratoryRequirements\CartRequirementAggregator;
use App\Support\LaboratoryRequirements\BranchMatchResult;
use Carbon\Carbon;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

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
            'laboratory_store' => [
                'required',
                Rule::exists('laboratory_stores', 'id')->where(function ($query) {
                    $appointment = $this->laboratoryAppointment();

                    $query
                        ->where('is_active', true)
                        ->whereNull('deleted_at');

                    if ($appointment instanceof LaboratoryAppointment) {
                        $query->where('brand', $appointment->brand->value);
                    }
                }),
            ],
            'notes' => ['nullable', 'string', 'max:255'],
            'send_notification_email' => ['nullable', 'boolean'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            if ($validator->errors()->has('appointment_date') || $validator->errors()->has('appointment_time')) {
                return;
            }

            $appointmentAt = $this->resolveAppointmentAt(
                (string) $this->input('appointment_date'),
                (string) $this->input('appointment_time'),
            );

            if ($appointmentAt === null) {
                return;
            }

            if ($appointmentAt->lessThanOrEqualTo(now('America/Monterrey'))) {
                $validator->errors()->add(
                    'appointment_time',
                    'La fecha y hora seleccionadas ya pasaron. Selecciona una fecha y hora futuras.',
                );
            }

            if (! $validator->errors()->has('laboratory_store')) {
                $this->validateStoreCompatibility($validator);
            }
        });
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

    private function resolveAppointmentAt(string $appointmentDate, string $appointmentTime): ?Carbon
    {
        $time = trim($appointmentTime);
        $date = trim($appointmentDate);

        try {
            if (str_contains($time, 'T') || str_contains($time, 'Z')) {
                return Carbon::parse($time)->timezone('America/Monterrey');
            }

            $datePart = Carbon::parse($date)->format('Y-m-d');

            return Carbon::createFromFormat('Y-m-d H:i', "{$datePart} {$time}", 'America/Monterrey');
        } catch (\Throwable) {
            return null;
        }
    }

    private function validateStoreCompatibility(Validator $validator): void
    {
        $appointment = $this->laboratoryAppointment();
        if (! $appointment instanceof LaboratoryAppointment) {
            return;
        }

        $store = LaboratoryStore::query()
            ->whereKey((int) $this->input('laboratory_store'))
            ->where('is_active', true)
            ->whereNull('deleted_at')
            ->where('brand', $appointment->brand->value)
            ->first();

        if (! $store) {
            return;
        }

        $customer = $appointment->customer;
        if (! $customer) {
            return;
        }

        $items = $customer
            ->laboratoryCartItems()
            ->ofBrand($appointment->brand)
            ->with('laboratoryTest.laboratoryTestCategory')
            ->get();

        if ($items->isEmpty()) {
            return;
        }

        $requirements = app(CartRequirementAggregator::class)->aggregateItems($items, (string) $customer->id);
        if (! $requirements->isResolvable) {
            return;
        }

        $resolution = app(BranchResolver::class)->resolve($requirements);
        $branch = collect($resolution->brands[$appointment->brand->value] ?? $resolution->branches)
            ->first(fn (BranchMatchResult $branch) => (int) $branch->branch->id === (int) $store->id);

        if ($branch !== null && ! $branch->isCompatible) {
            $validator->errors()->add(
                'laboratory_store',
                'La sucursal seleccionada no coincide con los requisitos conocidos de los estudios actuales.',
            );
        }
    }

    private function laboratoryAppointment(): ?LaboratoryAppointment
    {
        $appointment = $this->route('laboratory_appointment');

        return $appointment instanceof LaboratoryAppointment ? $appointment : null;
    }
}
