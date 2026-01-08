<?php

namespace App\Http\Requests\PaymentMethods;

use Illuminate\Foundation\Http\FormRequest;

class UpdatePaymentMethodRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null 
            && $this->user()->customer !== null
            && optional($this->user()->customer->paymentMethods()
                ->find($this->route('payment_method')))->exists();
    }
    
    public function rules(): array
    {
        return [
            'alias' => [
                'nullable',
                'string',
                'max:50',
            ],
            'is_default' => [
                'nullable',
                'boolean',
            ],
        ];
    }
}