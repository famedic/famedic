<?php

namespace App\Http\Requests\OnlinePharmacy;

use Illuminate\Foundation\Http\FormRequest;

class CheckoutRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'address' => [
                'nullable',
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
        ];
    }
}
