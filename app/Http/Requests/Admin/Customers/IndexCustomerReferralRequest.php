<?php

namespace App\Http\Requests\Admin\Customers;

use App\Models\Customer;
use Illuminate\Foundation\Http\FormRequest;

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
        ];
    }
}
