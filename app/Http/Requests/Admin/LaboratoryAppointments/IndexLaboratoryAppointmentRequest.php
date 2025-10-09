<?php

namespace App\Http\Requests\Admin\LaboratoryAppointments;

use App\Models\LaboratoryAppointment;
use Illuminate\Foundation\Http\FormRequest;

class IndexLaboratoryAppointmentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('viewAny', LaboratoryAppointment::class);
    }

    public function rules(): array
    {
        return [
            'search' => ['nullable', 'string', 'max:255'],
            'completed' => ['nullable', 'in:,true,false'],
        ];
    }
}
