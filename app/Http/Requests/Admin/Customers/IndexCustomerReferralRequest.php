<?php

namespace App\Http\Requests\Admin\Customers;

use App\Models\Customer;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class IndexCustomerReferralRequest extends FormRequest
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
            'status' => ['nullable', 'string', Rule::in(['nuevo', 'verificado', 'compro', 'membresia', 'inactivo'])],
            'company' => ['nullable', 'string', 'max:255'],
            'source' => ['nullable', 'string', Rule::in(['odessa', 'familiar', 'regular'])],
            'city' => ['nullable', 'string', 'max:255'],
            'segment' => ['nullable', 'string', Rule::in(['bronce', 'plata', 'oro', 'platino', 'diamante'])],
            'type' => ['nullable', 'string', Rule::in(['regular', 'odessa', 'familiar'])],
            'granularity' => ['nullable', 'string', Rule::in(['day', 'week', 'month'])],
            'tab' => ['nullable', 'string', Rule::in(['overview', 'inviters', 'leaderboard', 'insights', 'ia'])],
            'view' => ['nullable', 'string', Rule::in(['table', 'cards'])],
            'compare_mode' => ['nullable', 'string', Rule::in(['period', 'month_vs_previous', '30_vs_90'])],
            'page' => ['nullable', 'integer', 'min:1'],
            'drawer_user_id' => ['nullable', 'integer', 'min:1'],
            'refresh' => ['nullable', 'boolean'],
            'export' => ['nullable', 'string', Rule::in(['csv', 'xlsx'])],
        ];
    }
}
