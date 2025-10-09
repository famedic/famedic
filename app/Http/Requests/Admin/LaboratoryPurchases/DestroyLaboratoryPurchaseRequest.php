<?php

namespace App\Http\Requests\Admin\LaboratoryPurchases;

use Illuminate\Foundation\Http\FormRequest;

class DestroyLaboratoryPurchaseRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('delete', $this->laboratory_purchase);
    }

    public function rules(): array
    {
        return [
            //
        ];
    }
}
