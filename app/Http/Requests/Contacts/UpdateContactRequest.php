<?php

namespace App\Http\Requests\Contacts;

use App\Enums\Gender;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateContactRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('update', $this->contact);
    }

    public function rules(): array
    {
        return [
            'name' => 'required|string|max:255',
            'paternal_lastname' => 'required|string|max:255',
            'maternal_lastname' => 'required|string|max:255',
            'phone' => 'required|phone',
            'phone_country' => 'required|string',
            'birth_date' => 'required|date|before:today',
            'gender' => ['required', Rule::enum(Gender::class)],
        ];
    }
}
