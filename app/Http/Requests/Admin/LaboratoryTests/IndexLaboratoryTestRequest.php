<?php

namespace App\Http\Requests\Admin\LaboratoryTests;

use App\Enums\LaboratoryBrand;
use App\Models\LaboratoryTest;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class IndexLaboratoryTestRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('viewAny', LaboratoryTest::class);
    }

    public function rules(): array
    {
        return [
            'search' => ['nullable', 'string', 'max:255'],
            'brand' => ['nullable', 'string', Rule::enum(LaboratoryBrand::class)],
            'category' => ['nullable', 'integer', 'exists:laboratory_test_categories,id'],
            'requires_appointment' => ['nullable', 'string', 'in:required,not_required'],
        ];
    }
}
