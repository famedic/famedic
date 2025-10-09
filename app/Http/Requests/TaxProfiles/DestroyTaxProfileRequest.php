<?php

namespace App\Http\Requests\TaxProfiles;

use Illuminate\Foundation\Http\FormRequest;

class DestroyTaxProfileRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('delete', $this->route('tax_profile'));
    }

    public function rules(): array
    {
        return [
            //
        ];
    }
}
