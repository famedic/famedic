<?php

namespace App\Http\Requests\Api\V1\User;

use App\Http\Requests\Api\V1\ApiFormRequest;
use App\Rules\ValidRfc;
use Illuminate\Support\Str;

class UpdateTaxProfileRequest extends ApiFormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'rfc' => ['required', 'string', 'min:12', 'max:13', new ValidRfc()],
            'business_name' => ['required', 'string', 'max:255'],
            'tax_regime' => ['required', 'string', 'max:10'],
            'cfdi_use' => ['nullable', 'string', 'max:10'],
            'postal_code' => ['required', 'string', 'regex:/^\d{5}$/'],
            'email' => ['nullable', 'email', 'max:255'],
        ];
    }

    protected function prepareForValidation(): void
    {
        if ($this->rfc) {
            $this->merge(['rfc' => Str::upper(trim((string) $this->rfc))]);
        }
    }
}
