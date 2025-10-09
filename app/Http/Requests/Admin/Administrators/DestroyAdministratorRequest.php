<?php

namespace App\Http\Requests\Admin\Administrators;

use Illuminate\Foundation\Http\FormRequest;

class DestroyAdministratorRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('delete', $this->route('administrator'));
    }

    public function rules(): array
    {
        return [
            //
        ];
    }
}
