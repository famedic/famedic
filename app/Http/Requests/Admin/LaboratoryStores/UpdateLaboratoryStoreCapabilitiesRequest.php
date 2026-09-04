<?php

namespace App\Http\Requests\Admin\LaboratoryStores;

use Illuminate\Foundation\Http\FormRequest;

class UpdateLaboratoryStoreCapabilitiesRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('update', $this->route('laboratory_store'));
    }

    public function rules(): array
    {
        return [
            'capability_ids' => ['present', 'array'],
            'capability_ids.*' => ['integer', 'distinct', 'exists:laboratory_capabilities,id'],
        ];
    }
}
