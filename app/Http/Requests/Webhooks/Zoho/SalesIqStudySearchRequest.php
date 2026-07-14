<?php

namespace App\Http\Requests\Webhooks\Zoho;

use App\Enums\LaboratoryBrand;
use App\Services\Zoho\SalesIqWebhookService;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class SalesIqStudySearchRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        $resolved = app(SalesIqWebhookService::class)->resolvePayload($this);

        if (isset($resolved['query']) && is_string($resolved['query'])) {
            $resolved['query'] = trim($resolved['query']);
        }

        if (isset($resolved['brand']) && is_string($resolved['brand'])) {
            $resolved['brand'] = mb_strtolower(trim($resolved['brand']));
        }

        $this->merge($resolved);
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $brands = array_map(
            static fn (LaboratoryBrand $brand): string => $brand->value,
            LaboratoryBrand::cases()
        );

        return [
            'query' => ['required', 'string', 'max:120'],
            'brand' => ['nullable', 'string', Rule::in($brands)],
            'visitor_id' => ['nullable', 'string', 'max:150'],
            'conversation_id' => ['nullable', 'string', 'max:150'],
            'page' => ['nullable', 'string', 'max:255'],
            'environment' => ['nullable', 'string', 'max:50'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'query.required' => 'La búsqueda es requerida.',
            'query.max' => 'La búsqueda no puede superar 120 caracteres.',
            'brand.in' => 'La marca no es válida.',
        ];
    }
}
