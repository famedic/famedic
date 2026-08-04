<?php

namespace App\Http\Requests\Admin\LaboratoryBilling;

class IndexLaboratoryBillingTaxProfilesRequest extends LaboratoryBillingDateRangeRequest
{
    public function rules(): array
    {
        return array_merge(parent::rules(), [
            'search' => ['nullable', 'string', 'max:255'],
            'status' => ['nullable', 'in:active,deleted'],
            'usage' => ['nullable', 'in:used,unused'],
            'is_default' => ['nullable', 'in:true,false,1,0'],
            'tipo_persona' => ['nullable', 'in:fisica,moral'],
            'include_deleted' => ['nullable', 'in:true,false,1,0'],
            'created_in_range' => ['nullable', 'in:true,false,1,0'],
        ]);
    }
}
