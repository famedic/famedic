<?php

namespace App\Http\Requests\Admin\CustomerIntelligence;

use App\Models\Customer;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class IndexCohortsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('viewAny', Customer::class);
    }

    public function rules(): array
    {
        return [
            'start_date' => ['nullable', 'date', 'before_or_equal:today'],
            'end_date' => ['nullable', 'date', 'before_or_equal:today', 'after_or_equal:start_date'],
            'type' => ['nullable', 'string', Rule::in(['regular', 'odessa', 'familiar'])],
            'source' => ['nullable', 'string', Rule::in(['organico', 'referred', 'odessa', 'familiar'])],
            'state' => ['nullable', 'string', 'max:10'],
            'city' => ['nullable', 'string', 'max:120'],
            'gender' => ['nullable', 'string', Rule::in(['1', '2', 'male', 'female'])],
            'max_weeks' => ['nullable', 'integer', 'min:4', 'max:16'],
            'max_cohorts' => ['nullable', 'integer', 'min:3', 'max:12'],
            'tab' => ['nullable', 'string', Rule::in(['overview', 'retention', 'repeat', 'churn', 'ltv', 'ia'])],
            'refresh' => ['nullable', 'boolean'],
        ];
    }
}
