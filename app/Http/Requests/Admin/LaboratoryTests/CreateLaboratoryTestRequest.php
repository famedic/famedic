<?php

namespace App\Http\Requests\Admin\LaboratoryTests;

use App\Models\LaboratoryTest;
use Illuminate\Foundation\Http\FormRequest;

class CreateLaboratoryTestRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('create', LaboratoryTest::class);
    }

    public function rules(): array
    {
        return [
            //
        ];
    }
}
