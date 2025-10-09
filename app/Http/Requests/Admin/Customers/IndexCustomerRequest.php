<?php

namespace App\Http\Requests\Admin\Customers;

use App\Models\Customer;
use Illuminate\Foundation\Http\FormRequest;

class IndexCustomerRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('viewAny', Customer::class);
    }

    public function rules(): array
    {
        return [
            'search' => ['nullable', 'string', 'max:255'],
            'type' => ['nullable', 'string', 'in:regular,odessa,familiar'],
            'medical_attention_status' => ['nullable', 'string', 'in:active,inactive'],
            'referral_status' => ['nullable', 'string', 'in:referred,not_referred'],
            'verification_status' => ['nullable', 'string', 'in:verified,unverified'],
            'start_date' => ['nullable', 'date', 'before_or_equal:today'],
            'end_date' => ['nullable', 'date', 'before_or_equal:today', 'after_or_equal:start_date'],
        ];
    }
}
