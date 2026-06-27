<?php

namespace App\Http\Requests\Admin\Carts;

use Illuminate\Foundation\Http\FormRequest;

class ExportCartsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->administrator->hasPermissionTo('view carts');
    }

    public function rules(): array
    {
        return [
            'search' => ['nullable', 'string', 'max:255'],
            'type' => ['nullable', 'in:pharmacy,lab'],
            'display_status' => ['nullable', 'in:active,abandoned,completed'],
            'start_date' => ['nullable', 'date'],
            'end_date' => ['nullable', 'date', 'after_or_equal:start_date'],
        ];
    }
}
