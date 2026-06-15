<?php

namespace App\Http\Requests\Api\V1\LaboratoryAppointments;

use App\Enums\LaboratoryBrand;
use App\Http\Requests\Api\V1\ApiFormRequest;
use Illuminate\Validation\Rule;

class StoreAppointmentRequest extends ApiFormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'brand' => ['required', Rule::enum(LaboratoryBrand::class)],
            'contact_id' => ['required', 'integer', 'min:1'],
            'address_id' => ['required', 'integer', 'min:1'],
            'scheduled_at' => ['required', 'date', 'after:now'],
            'notes' => ['nullable', 'string', 'max:1000'],
        ];
    }
}
