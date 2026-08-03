<?php

namespace App\Http\Requests\Admin\LaboratoryBilling;

use App\Enums\LaboratoryBillingStatus;
use Illuminate\Validation\Rule;

class IndexLaboratoryBillingRequestsRequest extends LaboratoryBillingDateRangeRequest
{
    public function rules(): array
    {
        return array_merge(parent::rules(), [
            'search' => ['nullable', 'string', 'max:255'],
            'status' => ['nullable', Rule::in(array_column(LaboratoryBillingStatus::cases(), 'value'))],
            'overdue' => ['nullable', 'in:true,false,1,0'],
            'document' => ['nullable', 'in:with_pdf,without_pdf,with_xml,without_xml,complete,incomplete'],
            'tax_profile_id' => ['nullable', 'integer'],
            'customer_id' => ['nullable', 'integer'],
            'brand' => ['nullable', 'string', 'max:50'],
        ]);
    }
}
