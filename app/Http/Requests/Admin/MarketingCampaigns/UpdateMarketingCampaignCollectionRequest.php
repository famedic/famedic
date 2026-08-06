<?php

namespace App\Http\Requests\Admin\MarketingCampaigns;

use App\Enums\LaboratoryBrand;
use App\Models\LaboratoryTest;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class UpdateMarketingCampaignCollectionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('update', $this->collection()) ?? false;
    }

    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:160'],
            'public_title' => ['required', 'string', 'max:180'],
            'public_description' => ['nullable', 'string'],
            'laboratory_brand' => ['required', Rule::enum(LaboratoryBrand::class)],
            'is_active' => ['boolean'],
            // Lista vacía válida: limpia items. Duplicados se rechazan.
            'laboratory_test_ids' => ['present', 'array'],
            'laboratory_test_ids.*' => ['required', 'integer', 'exists:laboratory_tests,id'],
        ];
    }

    protected function prepareForValidation(): void
    {
        $campaign = $this->route('marketing_campaign');
        $collection = $this->collection();

        if ($campaign && $collection && (int) $collection->marketing_campaign_id !== (int) $campaign->id) {
            abort(404);
        }

        $this->merge([
            'is_active' => $this->boolean('is_active', true),
            'laboratory_test_ids' => $this->input('laboratory_test_ids', []),
        ]);
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator) {
            if ($validator->errors()->isNotEmpty()) {
                return;
            }

            $ids = array_map('intval', $this->input('laboratory_test_ids', []));

            if (count($ids) !== count(array_unique($ids))) {
                $validator->errors()->add(
                    'laboratory_test_ids',
                    'No se permiten estudios duplicados en la colección.'
                );

                return;
            }

            if ($ids === []) {
                return;
            }

            $brand = LaboratoryBrand::from((string) $this->input('laboratory_brand'));
            $hasOtherBrand = LaboratoryTest::query()
                ->whereKey($ids)
                ->where('brand', '!=', $brand->value)
                ->exists();

            if ($hasOtherBrand) {
                $validator->errors()->add(
                    'laboratory_test_ids',
                    'Todos los estudios deben pertenecer a la marca de la colección.'
                );
            }
        });
    }

    private function collection(): mixed
    {
        return $this->route('marketing_campaign_collection')
            ?? $this->route('collection')
            ?? $this->route('marketingCampaignCollection');
    }
}
