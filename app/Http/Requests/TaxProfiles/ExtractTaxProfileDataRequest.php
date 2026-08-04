<?php

namespace App\Http\Requests\TaxProfiles;

use Illuminate\Foundation\Http\FormRequest;

class ExtractTaxProfileDataRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->customer !== null;
    }

    public function rules(): array
    {
        return [
            'fiscal_certificate' => ['required', 'file', 'mimes:pdf', 'mimetypes:application/pdf', 'max:5120'],
        ];
    }

    public function messages(): array
    {
        return [
            'fiscal_certificate.required' => 'El archivo de constancia es obligatorio.',
            'fiscal_certificate.file' => 'El archivo de constancia no es válido.',
            'fiscal_certificate.mimes' => 'La constancia debe ser un archivo PDF.',
            'fiscal_certificate.mimetypes' => 'La constancia debe ser un archivo PDF.',
            'fiscal_certificate.max' => 'La constancia fiscal no debe exceder 5MB.',
        ];
    }

    public function withValidator($validator): void
    {
        $validator->after(function ($validator) {
            if ($validator->errors()->isNotEmpty()) {
                return;
            }

            $file = $this->file('fiscal_certificate');
            if (! $file) {
                return;
            }

            if ($file->getSize() === 0) {
                $validator->errors()->add('fiscal_certificate', 'El archivo de constancia está vacío.');

                return;
            }

            $handle = @fopen($file->getRealPath(), 'rb');
            if ($handle === false) {
                $validator->errors()->add('fiscal_certificate', 'No se pudo leer el archivo de constancia.');

                return;
            }

            $header = fread($handle, 5);
            fclose($handle);

            if ($header === false || ! str_starts_with($header, '%PDF')) {
                $validator->errors()->add(
                    'fiscal_certificate',
                    'El archivo no es un PDF válido.'
                );
            }
        });
    }
}
