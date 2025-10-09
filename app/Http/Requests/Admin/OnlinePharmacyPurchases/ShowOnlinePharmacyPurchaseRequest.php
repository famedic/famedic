<?php

namespace App\Http\Requests\Admin\OnlinePharmacyPurchases;

use Illuminate\Foundation\Http\FormRequest;

class ShowOnlinePharmacyPurchaseRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('view', $this->online_pharmacy_purchase);
    }

    public function rules(): array
    {
        return [
            //
        ];
    }
}
