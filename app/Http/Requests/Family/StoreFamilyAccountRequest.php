<?php

namespace App\Http\Requests\Family;

use App\Enums\Gender;
use App\Enums\Kinship;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreFamilyAccountRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'paternal_lastname' => ['required', 'string', 'max:255'],
            'maternal_lastname' => ['required', 'string', 'max:255'],
            'birth_date' => ['required', 'date', 'before:today'],
            'gender' => ['required', Rule::enum(Gender::class)],
            'kinship' => [
                'required',
                Rule::enum(Kinship::class),
                function ($attribute, $value, $fail) {
                    $existing = $this->user()->customer
                        ->familyAccounts()
                        ->pluck('kinship')
                        ->map(fn($kinship) => $kinship->value)
                        ->unique();

                    if ($existing->isEmpty()) return;

                    $isGroup1 = $existing->intersect(['spouse', 'child'])->isNotEmpty();
                    $allowedValues = $isGroup1 ? ['spouse', 'child'] : ['parent'];

                    if (!in_array($value, $allowedValues)) {
                        $fail('No puedes mezclar cónyuge/hijos con padres en el mismo plan familiar.');
                    }
                },
            ],
        ];
    }
}