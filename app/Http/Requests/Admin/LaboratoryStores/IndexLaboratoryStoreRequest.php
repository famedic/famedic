<?php

namespace App\Http\Requests\Admin\LaboratoryStores;

use App\Enums\LaboratoryBrand;
use App\Models\LaboratoryStore;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class IndexLaboratoryStoreRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('viewAny', LaboratoryStore::class);
    }

    public function rules(): array
    {
        return [
            'search' => ['nullable', 'string', 'max:120'],
            'brand' => ['nullable', Rule::enum(LaboratoryBrand::class)],
            'state' => ['nullable', 'string', 'max:120'],
            'municipality' => ['nullable', 'string', 'max:160'],
            'active_status' => ['nullable', Rule::in(['active', 'inactive', 'historical'])],
            'location_status' => ['nullable', Rule::in(['with_coordinates', 'missing_coordinates'])],
            'service' => ['nullable', 'string', 'max:64', Rule::exists('laboratory_store_services', 'service_type')],
            'capability' => ['nullable', 'string', 'max:120', Rule::exists('laboratory_capabilities', 'slug')],
            'data_status' => ['nullable', Rule::in(['ok', 'warning', 'conflict', 'historical'])],
            'view' => ['nullable', Rule::in(['list', 'map', 'split'])],
            'store' => ['nullable', 'integer', 'exists:laboratory_stores,id'],
        ];
    }

    public function filters(): array
    {
        $validated = $this->validated();

        return collect([
            'search' => isset($validated['search']) ? trim((string) $validated['search']) : null,
            'brand' => $validated['brand'] ?? null,
            'state' => $validated['state'] ?? null,
            'municipality' => $validated['municipality'] ?? null,
            'active_status' => $validated['active_status'] ?? null,
            'location_status' => $validated['location_status'] ?? null,
            'service' => $validated['service'] ?? null,
            'capability' => $validated['capability'] ?? null,
            'data_status' => $validated['data_status'] ?? null,
            'view' => $validated['view'] ?? null,
            'store' => $validated['store'] ?? null,
        ])->filter(fn ($value) => $value !== null && $value !== '')->all();
    }
}
