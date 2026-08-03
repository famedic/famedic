<?php

namespace App\Http\Requests\Admin\LaboratoryBilling;

use App\Services\LaboratoryBilling\LaboratoryBillingAccess;
use Illuminate\Foundation\Http\FormRequest;

class LaboratoryBillingDateRangeRequest extends FormRequest
{
    public function authorize(): bool
    {
        return app(LaboratoryBillingAccess::class)->allows($this->user());
    }

    public function rules(): array
    {
        $rules = [
            'from' => ['nullable', 'date', 'before_or_equal:today'],
            'to' => ['nullable', 'date', 'before_or_equal:today'],
        ];

        if ($this->filled('from') && $this->filled('to')) {
            $rules['from'][] = 'before_or_equal:to';
            $rules['to'][] = 'after_or_equal:from';
        }

        return $rules;
    }

    public function attributes(): array
    {
        return [
            'from' => 'fecha inicial',
            'to' => 'fecha final',
        ];
    }
}
