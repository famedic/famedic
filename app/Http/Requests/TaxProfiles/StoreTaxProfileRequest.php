<?php

namespace App\Http\Requests\TaxProfiles;

use App\Rules\RFC;
use Illuminate\Foundation\Http\FormRequest;

class StoreTaxProfileRequest extends FormRequest
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
                'required',
                'file',
                'mimes:pdf',
                'max:5120',
            ],
            'extracted_data' => 'nullable|array',
            'confirm_data' => 'required_if:extracted_data,!=,null|boolean',
        ];
    }
    
    public function messages()
    {
        return [
            'fiscal_certificate.required' => 'La constancia fiscal es obligatoria',
            'fiscal_certificate.mimes' => 'El archivo debe ser un PDF',
            'fiscal_certificate.max' => 'El archivo no debe superar 5MB',
            'rfc.size' => 'El RFC debe tener 12 caracteres (moral) o 13 caracteres (física)',
        ];
    }
}