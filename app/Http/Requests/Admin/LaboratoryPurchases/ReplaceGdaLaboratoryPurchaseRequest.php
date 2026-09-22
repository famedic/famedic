<?php

namespace App\Http\Requests\Admin\LaboratoryPurchases;

use Illuminate\Foundation\Http\FormRequest;

class ReplaceGdaLaboratoryPurchaseRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('replaceGda', $this->laboratory_purchase);
    }

    public function rules(): array
    {
        return [
            'coupon_id' => ['required', 'integer', 'exists:coupons,id'],
        ];
    }

    public function attributes(): array
    {
        return [
            'coupon_id' => 'saldo a favor',
        ];
    }
}
