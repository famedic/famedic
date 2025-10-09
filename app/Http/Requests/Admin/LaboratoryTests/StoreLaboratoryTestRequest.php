<?php

namespace App\Http\Requests\Admin\LaboratoryTests;

use App\Enums\LaboratoryBrand;
use App\Models\LaboratoryTest;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreLaboratoryTestRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('create', LaboratoryTest::class);
    }

    public function rules(): array
    {
        return [
            'brand' => ['required', Rule::enum(LaboratoryBrand::class)],
            'gda_id' => ['required', 'string', 'max:255', 'unique:laboratory_tests,gda_id'],
            'name' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string'],
            'feature_list' => ['nullable', 'array'],
            'feature_list.*' => ['string', 'max:255'],
            'indications' => ['nullable', 'string'],
            'other_name' => ['nullable', 'string', 'max:255'],
            'elements' => ['nullable', 'string'],
            'common_use' => ['nullable', 'string'],
            'requires_appointment' => ['boolean'],
            'public_price' => ['required', 'numeric', 'min:0'],
            'famedic_price' => ['required', 'numeric', 'min:0'],
            'laboratory_test_category_id' => ['required', 'exists:laboratory_test_categories,id'],
        ];
    }

    public function prepareForValidation(): void
    {
        $this->merge([
            'requires_appointment' => $this->boolean('requires_appointment'),
        ]);
    }
}
