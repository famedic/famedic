<?php

namespace App\Http\Requests\TaxProfiles;

use Illuminate\Foundation\Http\FormRequest;

class EditTaxProfileRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('update', $this->tax_profile);
    }

    public function rules(): array
    {
        return [
            //
        ];
    }
}
