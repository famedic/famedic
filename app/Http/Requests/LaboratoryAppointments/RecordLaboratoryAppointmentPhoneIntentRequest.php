<?php

namespace App\Http\Requests\LaboratoryAppointments;

use App\Enums\LaboratoryBrand;
use Illuminate\Foundation\Http\FormRequest;

class RecordLaboratoryAppointmentPhoneIntentRequest extends FormRequest
{
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
        return [];
    }
}
