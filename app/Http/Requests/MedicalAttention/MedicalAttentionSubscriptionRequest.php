<?php

namespace App\Http\Requests\MedicalAttention;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class MedicalAttentionSubscriptionRequest extends FormRequest
{
    public function authorize(): bool
    {
        $customer = $this->user()->customer;

        // No permitir compra si ya tiene suscripción activa
        return !$customer
            ->medicalAttentionSubscriptions()
            ->active()
            ->exists();
    }

    public function rules(): array
    {
        return [
            'payment_method' => [
                'required',
                'string',
                Rule::in($this->getAllowedPaymentMethods()),
            ],
        ];
    }

    private function getAllowedPaymentMethods(): array
    {
        $allowedPaymentMethods = [];

        $customer = $this->user()->customer;

        // Métodos guardados (EfevooPay)
        $customer->paymentMethods()->each(function ($paymentMethod) use (&$allowedPaymentMethods) {
            $allowedPaymentMethods[] = (string) $paymentMethod->id;
        });

        // Odessa si aplica
        if ($customer->has_odessa_afiliate_account) {
            $allowedPaymentMethods[] = 'odessa';
        }

        return $allowedPaymentMethods;
    }
}
