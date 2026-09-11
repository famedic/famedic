<?php

namespace App\Http\Requests\Admin\LaboratoryStores;

use Illuminate\Foundation\Http\FormRequest;

class UpdateLaboratoryStoreRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('update', $this->route('laboratory_store'));
    }

    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'phone' => ['nullable', 'string', 'max:32', 'regex:/^[0-9+().\s-]+$/'],
            'address' => ['required', 'string', 'max:500'],
            'street' => ['nullable', 'string', 'max:255'],
            'exterior_number' => ['nullable', 'string', 'max:40'],
            'interior_number' => ['nullable', 'string', 'max:40'],
            'neighborhood' => ['nullable', 'string', 'max:255'],
            'municipality' => ['nullable', 'string', 'max:160'],
            'city' => ['nullable', 'string', 'max:160'],
            'state' => ['required', 'string', 'max:120'],
            'postal_code' => ['nullable', 'string', 'regex:/^\d{5}$/'],
            'google_maps_url' => ['required', 'url', 'max:2048'],
            'latitude' => ['nullable', 'numeric', 'between:-90,90'],
            'longitude' => ['nullable', 'numeric', 'between:-180,180'],
            'is_active' => ['boolean'],
        ];
    }

    protected function prepareForValidation(): void
    {
        $this->merge([
            'is_active' => $this->boolean('is_active'),
        ]);
    }
}
