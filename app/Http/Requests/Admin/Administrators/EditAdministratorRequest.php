<?php

namespace App\Http\Requests\Admin\Administrators;

use Illuminate\Foundation\Http\FormRequest;

class EditAdministratorRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('update', $this->route('administrator'));
    }

    public function rules(): array
    {
        return [
            //
        ];
    }
}
