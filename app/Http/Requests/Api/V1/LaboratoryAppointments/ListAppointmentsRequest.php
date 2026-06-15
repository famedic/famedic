<?php

namespace App\Http\Requests\Api\V1\LaboratoryAppointments;

use App\Enums\LaboratoryBrand;
use App\Http\Requests\Api\V1\ApiFormRequest;
use Illuminate\Validation\Rule;

class ListAppointmentsRequest extends ApiFormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'brand' => ['nullable', Rule::enum(LaboratoryBrand::class)],
            'status' => ['nullable', 'string', Rule::in(['pending', 'confirmed', 'completed'])],
            'page' => ['nullable', 'integer', 'min:1'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:50'],
        ];
    }
}
