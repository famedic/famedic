<?php

namespace App\Http\Requests\LaboratoryCheckout;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class SyncLaboratoryAppointmentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        if (! $this->filled('contact_id') && $this->filled('contact')) {
            $this->merge([
                'contact_id' => $this->input('contact'),
            ]);
        }
    }

    public function rules(): array
    {
        return [
            'contact_id' => [
                'required',
                'integer',
                Rule::exists('contacts', 'id')->where('customer_id', $this->user()->customer->id),
            ],
            'address' => ['nullable', 'string'],
            'payment_method' => ['nullable', 'string'],
        ];
    }
}
