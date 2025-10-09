<?php

namespace App\Http\Requests\Admin\LaboratoryTests;

use App\Models\LaboratoryTest;
use Illuminate\Foundation\Http\FormRequest;

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
            'brand' => ['nullable', 'string'],
            'category' => ['nullable', 'string'],
            'requires_appointment' => ['nullable', 'string', 'in:required,not_required'],
        ];
    }
}
