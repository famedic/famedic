<?php

namespace App\Http\Requests\Admin\LaboratoryStores;

use Illuminate\Foundation\Http\FormRequest;

class ShowLaboratoryStoreRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('view', $this->route('laboratory_store'));
    }

    public function rules(): array
    {
        return [];
    }
}
