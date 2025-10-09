<?php

namespace App\Http\Requests\MedicalAttention;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class MedicalAttentionSubscriptionRequest extends FormRequest
{
    public function authorize(): bool
    {
        $customer = $this->user()->customer;
        
        // Can't purchase if already have active subscription
        return !$customer->medicalAttentionSubscriptions()->active()->exists();
    }

    public function rules(): array
    {
        return [
            'payment_method' => ['required', 'string', Rule::in($this->getAllowedPaymentMethods())],
            'total' => ['required', 'integer', 'min:0'],
        ];
    }

    private function getAllowedPaymentMethods(): array
    {
        $allowedPaymentMethods = [];

        auth()->user()->customer->paymentMethods()->each(function ($paymentMethod) use (&$allowedPaymentMethods) {
            $allowedPaymentMethods[] = $paymentMethod->id;
        });

        if (auth()->user()->customer->has_odessa_afiliate_account) {
            $allowedPaymentMethods[] = 'odessa';
        }

        return $allowedPaymentMethods;
    }
}
