<?php

namespace App\Http\Requests\Admin\LaboratoryPurchases;

use Illuminate\Foundation\Http\FormRequest;

class ResendLaboratoryPurchaseConfirmationRequest extends FormRequest
{
    public function authorize(): bool
    {
        return (bool) $this->user()?->administrator?->hasPermissionTo('laboratory-purchases.manage');
    }

    public function rules(): array
    {
        return [];
    }
}
