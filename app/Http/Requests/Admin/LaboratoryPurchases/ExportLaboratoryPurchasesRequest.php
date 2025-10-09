<?php

namespace App\Http\Requests\Admin\LaboratoryPurchases;

use Illuminate\Foundation\Http\FormRequest;

class ExportLaboratoryPurchasesRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->administrator->hasPermissionTo('laboratory-purchases.manage.export');
    }

    public function rules(): array
    {
        return [
            'search' => ['nullable', 'string', 'max:255'],
            'deleted' => 'nullable|in:,true,false',
            'start_date' => 'nullable|date',
            'end_date' => 'nullable|date|after_or_equal:start_date',
            'invoice_requested' => 'nullable|in:,true,false',
            'invoice_uploaded' => 'nullable|in:,true,false',
            'results_uploaded' => 'nullable|in:,true,false',
            'payment_method' => 'nullable|in:,odessa,stripe',
            'brand' => 'nullable|string',
        ];
    }
}
