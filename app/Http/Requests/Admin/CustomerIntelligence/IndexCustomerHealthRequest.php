<?php

namespace App\Http\Requests\Admin\CustomerIntelligence;

use App\Models\Customer;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class IndexCustomerHealthRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('viewAny', Customer::class);
    }

    public function rules(): array
    {
        return [
            'search' => ['nullable', 'string', 'max:255'],
            'start_date' => ['nullable', 'date', 'before_or_equal:today'],
            'end_date' => ['nullable', 'date', 'before_or_equal:today', 'after_or_equal:start_date'],
            'type' => ['nullable', 'string', Rule::in(['regular', 'odessa', 'familiar'])],
            'source' => ['nullable', 'string', Rule::in(['organico', 'referred', 'odessa', 'familiar'])],
            'state' => ['nullable', 'string', 'max:10'],
            'city' => ['nullable', 'string', 'max:120'],
            'health_band' => ['nullable', 'string', Rule::in(['excellent', 'good', 'at_risk', 'critical', 'lost'])],
            'segment' => ['nullable', 'string', Rule::in([
                'premium', 'dormant', 'recoverable', 'lost', 'vip', 'high_value', 'high_risk', 'next_purchase', 'high_conversion',
            ])],
            'sort' => ['nullable', 'string', Rule::in(['health_desc', 'health_asc', 'ltv_desc', 'churn_desc', 'recent'])],
            'tab' => ['nullable', 'string', Rule::in(['overview', 'scores', 'predictive', 'segments', 'ia'])],
            'page' => ['nullable', 'integer', 'min:1'],
            'refresh' => ['nullable', 'boolean'],
            'drawer_customer_id' => ['nullable', 'integer', 'exists:customers,id'],
        ];
    }
}
