<?php

namespace App\Http\Requests\Addresses;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateAddressRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('update', $this->address);
    }

    public function rules(): array
    {
        return [
            'street' => ['required', 'string', 'max:255'],
            'number' => ['required', 'string', 'max:255'],
            'neighborhood' => ['required', 'string', 'max:255'],
            'state' => ['required', 'string', 'max:255', Rule::in(array_keys(config('mexicanstates')))],
            'city' => ['required', 'string', 'max:255', Rule::in(config('mexicanstates.' . $this->state))],
            'zipcode' => ['required', 'string', 'max:255'],
            'additional_references' => ['nullable', 'string', 'max:255'],
        ];
    }
}
