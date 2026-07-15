<?php

namespace App\Http\Requests\Webhooks\Zoho;

use App\Enums\LaboratoryBrand;
use App\Services\Zoho\SalesIqStudySearchService;
use App\Services\Zoho\SalesIqWebhookService;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

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

        if (isset($resolved['state']) && is_string($resolved['state'])) {
            $resolved['state'] = trim($resolved['state']);
        }

        $this->merge($resolved);
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'query' => ['required', 'string', 'max:120'],
            'brand' => ['nullable', 'string', 'max:50'],
            'state' => ['nullable', 'string', 'max:100'],
            'visitor_id' => ['nullable', 'string', 'max:150'],
            'conversation_id' => ['nullable', 'string', 'max:150'],
            'page' => ['nullable', 'string', 'max:255'],
            'environment' => ['nullable', 'string', 'max:50'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            $brand = $this->input('brand');

            if (! is_string($brand) || trim($brand) === '') {
                return;
            }

            if (SalesIqStudySearchService::isUnknownBrand($brand)) {
                return;
            }

            $brands = array_map(
                static fn (LaboratoryBrand $case): string => $case->value,
                LaboratoryBrand::cases()
            );

            if (! in_array(mb_strtolower(trim($brand)), $brands, true)) {
                $validator->errors()->add('brand', 'La marca no es válida.');
            }
        });
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'query.required' => 'La búsqueda es requerida.',
            'query.max' => 'La búsqueda no puede superar 120 caracteres.',
        ];
    }
}
