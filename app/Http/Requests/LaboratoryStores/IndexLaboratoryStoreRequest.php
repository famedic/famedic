<?php

namespace App\Http\Requests\LaboratoryStores;

use App\Enums\LaboratoryBrand;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class IndexLaboratoryStoreRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'brand' => ['nullable', Rule::enum(LaboratoryBrand::class)],
            'search' => ['nullable', 'string', 'max:120'],
            'state' => ['nullable', 'string', 'max:120'],
            'municipality' => ['nullable', 'string', 'max:160'],
            'postal_code' => ['nullable', 'string', 'regex:/^\d{5}$/'],
            'capability' => ['nullable', 'string', 'max:120', Rule::exists('laboratory_capabilities', 'slug')->where('is_active', true)],
            'service' => ['nullable', 'string', Rule::in(array_keys($this->serviceTypes()))],
            'latitude' => ['nullable', 'numeric', 'between:-90,90'],
            'longitude' => ['nullable', 'numeric', 'between:-180,180'],
            'radius' => ['nullable', Rule::in(['5', '10', '25', '50', 5, 10, 25, 50])],
            'sort' => ['nullable', Rule::in(['name', 'relevance', 'distance'])],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator) {
            $hasLatitude = $this->filled('latitude');
            $hasLongitude = $this->filled('longitude');

            if ($hasLatitude !== $hasLongitude) {
                $validator->errors()->add('latitude', 'Latitude and longitude must be provided together.');
                $validator->errors()->add('longitude', 'Latitude and longitude must be provided together.');
            }

            if ($this->input('sort') === 'distance' && (! $hasLatitude || ! $hasLongitude)) {
                $validator->errors()->add('sort', 'Distance sorting requires latitude and longitude.');
            }
        });
    }

    /**
     * @return array<string, string|null>
     */
    public function filters(): array
    {
        $validated = $this->validated();

        return [
            'brand' => $validated['brand'] ?? null,
            'search' => $this->filled('search') ? trim((string) $validated['search']) : null,
            'state' => $validated['state'] ?? null,
            'municipality' => $validated['municipality'] ?? null,
            'postal_code' => $validated['postal_code'] ?? null,
            'capability' => $validated['capability'] ?? null,
            'service' => $validated['service'] ?? null,
            'latitude' => array_key_exists('latitude', $validated) ? (float) $validated['latitude'] : null,
            'longitude' => array_key_exists('longitude', $validated) ? (float) $validated['longitude'] : null,
            'radius' => array_key_exists('radius', $validated) ? (int) $validated['radius'] : null,
            'sort' => $validated['sort'] ?? 'name',
        ];
    }

    /**
     * @return array<string, string>
     */
    public function serviceTypes(): array
    {
        return [
            'historia_clinica' => 'clinical_history',
            'optica' => 'optical',
        ];
    }
}
