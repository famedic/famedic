<?php

namespace App\Http\Requests\TaxProfiles;

use Illuminate\Foundation\Http\FormRequest;

class SetDefaultTaxProfileRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('setDefault', $this->route('tax_profile'));
    }

    public function rules(): array
    {
        return [];
    }
}
