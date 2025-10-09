<?php

namespace App\Http\Requests\TaxProfiles;

use Illuminate\Foundation\Http\FormRequest;

class ShowTaxProfileRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('view', $this->tax_profile);
    }

    public function rules(): array
    {
        return [
            //
        ];
    }
}
