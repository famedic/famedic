<?php

namespace App\Http\Requests\Admin\LaboratoryAppointments;

use App\Models\LaboratoryAppointment;
use Illuminate\Foundation\Http\FormRequest;

class IndexLaboratoryAppointmentMetricsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('viewAny', LaboratoryAppointment::class);
    }

    public function rules(): array
    {
        return [
            'start_date' => ['nullable', 'date'],
            'end_date' => ['nullable', 'date', 'after_or_equal:start_date'],
        ];
    }
}
