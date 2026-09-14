<?php

namespace App\Http\Requests\LaboratoryAppointments;

use App\Enums\LaboratoryBrand;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class RecordLaboratoryAppointmentPhoneIntentRequest extends FormRequest
{
    protected function prepareForValidation(): void
    {
        if (! $this->has('channel')) {
            $this->merge(['channel' => 'phone']);
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
            'channel' => ['required', Rule::in(['phone', 'whatsapp'])],
            'context' => ['nullable', 'string', 'max:64'],
            'step' => ['nullable', 'string', 'max:64'],
            'address_id' => ['nullable', 'integer'],
            'contact_id' => ['nullable', 'integer'],
            'current_url' => ['nullable', 'string', 'max:2048'],
        ];
    }
}
