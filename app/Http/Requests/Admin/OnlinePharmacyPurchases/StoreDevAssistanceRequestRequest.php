<?php

namespace App\Http\Requests\Admin\OnlinePharmacyPurchases;

use Illuminate\Foundation\Http\FormRequest;

class StoreDevAssistanceRequestRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('update', $this->online_pharmacy_purchase);
    }

    public function rules(): array
    {
        return [
            'comment' => 'required|string|max:5000',
            'mark_resolved' => 'sometimes|boolean',
        ];
    }
}
