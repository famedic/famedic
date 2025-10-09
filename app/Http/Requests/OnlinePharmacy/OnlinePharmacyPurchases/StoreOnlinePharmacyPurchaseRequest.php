<?php

namespace App\Http\Requests\OnlinePharmacy\OnlinePharmacyPurchases;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreOnlinePharmacyPurchaseRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'total' => 'required|numeric|min:0',
            'address' => [
                'exists:addresses,id',
                function ($attribute, $value, $fail) {
                    if ($value) {
                        $address = auth()->user()->customer->addresses()->find($value);
                        if (!$address) {
                            $fail('Dirección inválida.');
                        }
                    }
                },
            ],
            'contact' => [
                'exists:contacts,id',
                function ($attribute, $value, $fail) {
                    if ($value) {
                        $contact = auth()->user()->customer->contacts()->find($value);
                        if (!$contact) {
                            $fail('Contacto inválido.');
                        }
                    }
                },
            ],
            'payment_method' => ['required', 'string', Rule::in($this->getAllowedPaymentMethods())],
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
