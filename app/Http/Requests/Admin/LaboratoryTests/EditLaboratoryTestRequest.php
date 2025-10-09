<?php

namespace App\Http\Requests\Admin\LaboratoryTests;

use Illuminate\Foundation\Http\FormRequest;

class EditLaboratoryTestRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('update', $this->route('laboratory_test'));
    }

    public function rules(): array
    {
        return [
            //
        ];
    }
}
