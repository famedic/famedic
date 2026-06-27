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
            'view' => ['nullable', 'in:list,dashboard'],
            'start_date' => ['nullable', 'date'],
            'end_date' => ['nullable', 'date', 'after_or_equal:start_date'],
            'date_range' => ['nullable', 'in:,today,last_7_days,last_15_days,last_30_days,last_60_days,last_6_months'],
            'brand' => ['nullable', 'in:,olab,swisslab,jenner,liacsa,azteca'],
            'phone_call_intent' => ['nullable', 'in:,true,false'],
            'callback_info' => ['nullable', 'in:,true,false'],
        ];
    }
}
