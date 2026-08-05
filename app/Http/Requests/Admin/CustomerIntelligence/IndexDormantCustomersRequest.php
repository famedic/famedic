<?php

namespace App\Http\Requests\Admin\CustomerIntelligence;

use App\Models\Customer;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class IndexDormantCustomersRequest extends FormRequest
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
            'city' => ['nullable', 'string', 'max:120'],
            'state' => ['nullable', 'string', 'max:10'],
            'registration_source' => ['nullable', 'string', Rule::in(['organico', 'referred', 'odessa', 'familiar'])],
            'email_verification' => ['nullable', 'string', Rule::in(['verified', 'unverified'])],
            'phone_verification' => ['nullable', 'string', Rule::in(['verified', 'unverified'])],
            'referral_status' => ['nullable', 'string', Rule::in(['referred', 'not_referred'])],
            'type' => ['nullable', 'string', Rule::in(['regular', 'odessa', 'familiar'])],
            'days_bucket' => ['nullable', 'string', Rule::in(['0-7', '8-30', '31-60', '61-90', '90+'])],
            'granularity' => ['nullable', 'string', Rule::in(['day', 'week', 'month', 'year'])],
            'tab' => ['nullable', 'string', Rule::in(['resumen', 'clientes', 'conversion', 'segmentacion', 'campanas', 'fuentes', 'ia'])],
            'page' => ['nullable', 'integer', 'min:1'],
            'refresh' => ['nullable', 'boolean'],
            'drawer_customer_id' => ['nullable', 'integer', 'exists:customers,id'],
            'export' => ['nullable', 'string', Rule::in(['xlsx', 'csv', 'pdf'])],
        ];
    }
}
