<?php

namespace App\Http\Requests\TaxProfiles;

use App\Rules\RFC;
use Illuminate\Foundation\Http\FormRequest;

class UpdateTaxProfileRequest extends FormRequest
{
    public function authorize()
    {
        return true;
    }
    
    public function rules()
    {
        return [
            'name' => 'required|string|max:255',
            'rfc' => ['required', 'string', 'size:12,13', new RFC()],
            'zipcode' => 'required|string|size:5',
            'tax_regime' => 'required|string',
            'cfdi_use' => 'required|string',
            'fiscal_certificate' => [
                'sometimes',
                'file',
                'mimes:pdf',
                'max:5120',
            ],
            'extracted_data' => 'nullable|array',
            'confirm_data' => 'required_if:extracted_data,!=,null|boolean',
        ];
        
        // El archivo es opcional en actualización
        if ($this->hasFile('fiscal_certificate')) {
            $rules['fiscal_certificate'] = ['file', 'mimes:pdf', 'max:5120'];
        }
        
        return $rules;
    }
}