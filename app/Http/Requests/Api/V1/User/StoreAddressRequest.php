<?php

namespace App\Http\Requests\Api\V1\User;

use App\Http\Requests\Api\V1\ApiFormRequest;
use Illuminate\Validation\Rule;

class StoreAddressRequest extends ApiFormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'street' => ['required', 'string', 'max:255'],
            'number' => ['required', 'string', 'max:255'],
            'neighborhood' => ['required', 'string', 'max:255'],
            'state' => ['required', 'string', 'max:255', Rule::in(array_keys(config('mexicanstates')))],
            'city' => ['required', 'string', 'max:255', Rule::in(config('mexicanstates.'.$this->state))],
            'zipcode' => ['required', 'string', 'max:255'],
            'additional_references' => ['nullable', 'string', 'max:255'],
        ];
    }

    protected function prepareForValidation(): void
    {
        $merge = [];

        if ($this->has('postal_code') && ! $this->has('zipcode')) {
            $merge['zipcode'] = $this->input('postal_code');
        }

        if ($this->has('references') && ! $this->has('additional_references')) {
            $merge['additional_references'] = $this->input('references');
        }

        if ($merge !== []) {
            $this->merge($merge);
        }
    }
}
