<?php

namespace App\Http\Requests\Admin\LaboratoryBilling;

use App\Services\LaboratoryBilling\LaboratoryBillingAccess;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class RunLaboratoryBillingReportRequest extends FormRequest
{
    public function authorize(): bool
    {
        return app(LaboratoryBillingAccess::class)->allowsReports($this->user());
    }

    public function rules(): array
    {
        return [
            'period_type' => ['nullable', 'string', Rule::in([
                'previous_day',
                'last_7_days',
                'current_week',
                'previous_week',
                'current_month',
                'previous_month',
                'custom_range',
            ])],
            'custom_from' => ['nullable', 'required_if:period_type,custom_range', 'date'],
            'custom_to' => ['nullable', 'required_if:period_type,custom_range', 'date', 'after_or_equal:custom_from'],
        ];
    }
}
