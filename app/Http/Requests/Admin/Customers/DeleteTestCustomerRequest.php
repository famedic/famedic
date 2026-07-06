<?php

namespace App\Http\Requests\Admin\Customers;

use Illuminate\Foundation\Http\FormRequest;

class DeleteTestCustomerRequest extends FormRequest
{
    public function authorize(): bool
    {
        abort_if(app()->isProduction(), 403);

        return $this->user()->can('deleteTestUser', $this->route('customer'));
    }

    public function rules(): array
    {
        return [];
    }
}
