<?php

namespace App\Http\Requests\Admin\MarketingCampaigns;

use App\Enums\MarketingCampaignStatus;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateMarketingCampaignRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('update', $this->campaign()) ?? false;
    }

    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:160'],
            'description' => ['nullable', 'string'],
            'status' => ['required', Rule::enum(MarketingCampaignStatus::class)],
            'starts_at' => ['nullable', 'date'],
            'ends_at' => ['nullable', 'date', 'after:starts_at'],
        ];
    }

    private function campaign(): mixed
    {
        return $this->route('marketing_campaign') ?? $this->route('campaign');
    }
}
