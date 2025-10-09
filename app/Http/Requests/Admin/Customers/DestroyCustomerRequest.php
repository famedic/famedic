<?php

namespace App\Http\Requests\Admin\Customers;

use Illuminate\Foundation\Http\FormRequest;

class DestroyCustomerRequest extends FormRequest
{
    public function authorize(): bool
    {
        // Authorization is handled by the admin middleware and route model binding
        return true;
    }

    public function rules(): array
    {
        return [
            //
        ];
    }
}
