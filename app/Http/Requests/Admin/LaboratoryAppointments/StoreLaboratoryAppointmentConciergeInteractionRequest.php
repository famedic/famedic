<?php

namespace App\Http\Requests\Admin\LaboratoryAppointments;

use App\Enums\LaboratoryAppointmentInteractionType;
use App\Models\LaboratoryAppointment;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreLaboratoryAppointmentConciergeInteractionRequest extends FormRequest
{
    public function authorize(): bool
    {
        /** @var LaboratoryAppointment $appointment */
        $appointment = $this->route('laboratory_appointment');

        return $this->user()->can('update', $appointment);
    }

    public function rules(): array
    {
        return [
            'type' => [
                'required',
                'string',
                Rule::in([
                    LaboratoryAppointmentInteractionType::ConciergeNote->value,
                    LaboratoryAppointmentInteractionType::ConciergeOutboundCall->value,
                ]),
            ],
            'body' => ['required', 'string', 'max:5000'],
        ];
    }
}
