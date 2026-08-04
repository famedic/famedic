<?php

namespace App\Http\Requests\Laboratories\LaboratoryCartItems;

use App\Enums\LaboratoryBrand;
use App\Models\LaboratoryTest;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class StoreLaboratoryCartItemRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'laboratory_test' => ['required', 'exists:laboratory_tests,id'],
            'laboratory_brand' => ['required', Rule::enum(LaboratoryBrand::class)],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator) {
            if ($validator->errors()->isNotEmpty()) {
                return;
            }

            $test = LaboratoryTest::find($this->integer('laboratory_test'));
            $brand = LaboratoryBrand::from($this->string('laboratory_brand')->toString());

            if (!$test || $test->brand !== $brand) {
                $validator->errors()->add(
                    'laboratory_test',
                    'Este estudio no pertenece a la marca de laboratorio seleccionada.',
                );
            }
        });
    }
}
