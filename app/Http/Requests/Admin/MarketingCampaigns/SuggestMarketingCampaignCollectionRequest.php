<?php

namespace App\Http\Requests\Admin\MarketingCampaigns;

use App\Enums\LaboratoryBrand;
use App\Models\MarketingCampaignCollection;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class SuggestMarketingCampaignCollectionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('create', MarketingCampaignCollection::class) === true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'context' => ['required', 'string', 'min:20', 'max:1600'],
            'brand' => ['required', 'string', Rule::enum(LaboratoryBrand::class)],
            'desired_count' => ['required', 'integer', 'min:3', 'max:10'],
            'objective' => ['required', 'string', Rule::in(['preventiva', 'temporada', 'perfil', 'promocion'])],
            'candidate_ids' => ['required', 'array', 'min:1', 'max:40'],
            'candidate_ids.*' => ['integer', 'distinct'],
        ];
    }
}
