<?php

namespace App\Http\Requests\Admin\OnlinePharmacyPurchases;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

class StoreInvoiceRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('update', $this->online_pharmacy_purchase);
    }

    public function rules(): array
    {
        $invoiceExists = $this->online_pharmacy_purchase->invoice !== null;

        return [
            'invoice' => [
                $invoiceExists ? 'nullable' : 'required',
                'file',
                'mimes:pdf',
                'max:10240',
            ],
            'invoice_xml' => [
                'nullable',
                'file',
                'extensions:xml',
                'max:5120',
            ],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator) {
            if ($this->online_pharmacy_purchase->invoice === null) {
                return;
            }

            if (! $this->hasFile('invoice') && ! $this->hasFile('invoice_xml')) {
                $validator->errors()->add(
                    'invoice',
                    'Debes seleccionar al menos un archivo (PDF o XML) para actualizar la factura.'
                );
            }
        });
    }

    public function attributes(): array
    {
        return [
            'invoice' => 'factura PDF',
            'invoice_xml' => 'factura XML',
        ];
    }

    public function messages(): array
    {
        return [
            'invoice.required' => 'El archivo PDF de la factura es obligatorio.',
            'invoice.mimes' => 'La factura PDF debe ser un archivo con extensión .pdf.',
            'invoice.max' => 'La factura PDF no debe superar los 10 MB.',
            'invoice_xml.extensions' => 'La factura XML debe ser un archivo con extensión .xml.',
            'invoice_xml.max' => 'La factura XML no debe superar los 5 MB.',
        ];
    }
}
