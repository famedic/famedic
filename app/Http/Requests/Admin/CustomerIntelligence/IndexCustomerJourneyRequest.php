<?php

namespace App\Http\Requests\Admin\CustomerIntelligence;

use App\Models\Customer;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class IndexCustomerJourneyRequest extends FormRequest
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
            'compare_mode' => ['nullable', 'string', Rule::in(['period', 'month_vs_previous', '30_vs_90'])],
            'heatmap_metric' => ['nullable', 'string', Rule::in(['registrations', 'logins', 'checkouts', 'purchases'])],
            'tab' => ['nullable', 'string', Rule::in(['overview', 'paths', 'usuarios', 'insights', 'ia'])],
            'page' => ['nullable', 'integer', 'min:1'],
            'refresh' => ['nullable', 'boolean'],
            'drawer_customer_id' => ['nullable', 'integer', 'exists:customers,id'],
        ];
    }
}
