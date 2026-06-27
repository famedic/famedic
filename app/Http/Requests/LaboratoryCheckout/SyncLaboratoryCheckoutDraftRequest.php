<?php

namespace App\Http\Requests\LaboratoryCheckout;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class SyncLaboratoryCheckoutDraftRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        $merges = [];

        if (! $this->filled('contact_id') && $this->filled('contact')) {
            $merges['contact_id'] = $this->input('contact');
        }

        if (! $this->filled('address_id') && $this->filled('address')) {
            $merges['address_id'] = $this->input('address');
        }

        if ($this->has('payment_method') && is_numeric($this->input('payment_method'))) {
            $merges['payment_method'] = (string) $this->input('payment_method');
        }

        if ($merges !== []) {
            $this->merge($merges);
        }
    }

    public function rules(): array
    {
        $customerId = $this->user()->customer->id;

        return [
            'step' => ['required', 'string', Rule::in(['patient', 'address', 'payment'])],
            'contact_id' => [
                Rule::requiredIf(fn () => in_array($this->input('step'), ['patient', 'address', 'payment'], true)),
                'nullable',
                'integer',
                Rule::exists('contacts', 'id')->where('customer_id', $customerId),
            ],
            'address_id' => [
                Rule::requiredIf(fn () => in_array($this->input('step'), ['address', 'payment'], true)),
                'nullable',
                'integer',
                Rule::exists('addresses', 'id')->where('customer_id', $customerId),
            ],
            'payment_method' => [
                Rule::requiredIf(fn () => $this->input('step') === 'payment'),
                'nullable',
                'string',
                'max:64',
            ],
            'coupon_id' => ['nullable', 'integer', 'exists:coupons,id'],
        ];
    }

    public function messages(): array
    {
        return [
            'contact_id.required' => 'Selecciona un paciente antes de continuar.',
            'contact_id.exists' => 'El paciente seleccionado no es válido.',
            'address_id.required' => 'Selecciona una dirección antes de continuar.',
            'address_id.exists' => 'La dirección seleccionada no es válida.',
            'payment_method.required' => 'Selecciona un método de pago antes de continuar.',
        ];
    }
}
