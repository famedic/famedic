<?php

namespace App\Http\Requests\Admin\LaboratoryPurchases;

use Illuminate\Foundation\Http\FormRequest;

class StoreDevAssistanceRequestRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('update', $this->laboratory_purchase);
    }

    public function rules(): array
    {
        return [
            'comment' => 'required|string|max:5000',
            'mark_resolved' => 'sometimes|boolean',
        ];
    }
}
