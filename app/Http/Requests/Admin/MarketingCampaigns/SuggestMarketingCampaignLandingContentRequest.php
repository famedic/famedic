<?php

namespace App\Http\Requests\Admin\MarketingCampaigns;

use App\Models\MarketingCampaign;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class SuggestMarketingCampaignLandingContentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('create', MarketingCampaign::class) === true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'context' => ['required', 'string', 'min:20', 'max:1800'],
            'objective' => ['required', 'string', Rule::in(['promocion', 'educacion', 'lanzamiento', 'temporada'])],
            'tone' => ['required', 'string', Rule::in(['cercano', 'profesional', 'preventivo', 'comercial'])],
            'audience' => ['nullable', 'string', 'max:240'],
        ];
    }
}
