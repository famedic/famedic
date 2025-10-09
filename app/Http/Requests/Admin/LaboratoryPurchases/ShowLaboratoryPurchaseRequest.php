<?php

namespace App\Http\Requests\Admin\LaboratoryPurchases;

use Illuminate\Foundation\Http\FormRequest;

class ShowLaboratoryPurchaseRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('view', $this->laboratory_purchase);
    }

    public function rules(): array
    {
        return [
            //
        ];
    }
}
