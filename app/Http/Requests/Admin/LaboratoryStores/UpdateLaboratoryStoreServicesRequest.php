<?php

namespace App\Http\Requests\Admin\LaboratoryStores;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateLaboratoryStoreServicesRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('update', $this->route('laboratory_store'));
    }

    public function rules(): array
    {
        return [
            'services' => ['present', 'array'],
            'services.*.service_type' => ['required', Rule::in(['clinical_history', 'optical'])],
            'services.*.is_active' => ['required', 'boolean'],
        ];
    }
}
