<?php

namespace App\Http\Requests\Admin\Customers;

use Illuminate\Foundation\Http\FormRequest;

class ShowCustomerRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('view', $this->customer);
    }

    public function rules(): array
    {
        return [
            //
        ];
    }
}
