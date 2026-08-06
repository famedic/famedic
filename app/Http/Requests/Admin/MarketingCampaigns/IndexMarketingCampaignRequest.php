<?php

namespace App\Http\Requests\Admin\MarketingCampaigns;

use App\Enums\MarketingCampaignStatus;
use App\Models\MarketingCampaign;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class IndexMarketingCampaignRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('viewAny', MarketingCampaign::class) ?? false;
    }

    public function rules(): array
    {
        return [
            'search' => ['nullable', 'string', 'max:160'],
            'status' => ['nullable', Rule::enum(MarketingCampaignStatus::class)],
            'starts_at' => ['nullable', 'date'],
            'ends_at' => ['nullable', 'date'],
            'sort' => ['nullable', Rule::in(['created_at', 'name', 'starts_at', 'ends_at', 'status'])],
            'direction' => ['nullable', Rule::in(['asc', 'desc'])],
        ];
    }
}
