<?php

namespace App\Http\Requests\Admin\LaboratoryBilling;

class IndexLaboratoryBillingInvoicesRequest extends LaboratoryBillingDateRangeRequest
{
    public function rules(): array
    {
        return array_merge(parent::rules(), [
            'search' => ['nullable', 'string', 'max:255'],
            'document' => ['nullable', 'in:complete,missing_pdf,missing_xml,no_documents'],
        ]);
    }
}
