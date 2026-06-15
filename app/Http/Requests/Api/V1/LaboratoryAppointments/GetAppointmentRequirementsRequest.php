<?php

namespace App\Http\Requests\Api\V1\LaboratoryAppointments;

use App\Enums\LaboratoryBrand;
use App\Http\Requests\Api\V1\ApiFormRequest;
use Illuminate\Validation\Rule;

class GetAppointmentRequirementsRequest extends ApiFormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'brand' => ['required', Rule::enum(LaboratoryBrand::class)],
        ];
    }
}
