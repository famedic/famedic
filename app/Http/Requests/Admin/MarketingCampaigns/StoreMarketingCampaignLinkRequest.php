<?php

namespace App\Http\Requests\Admin\MarketingCampaigns;

use App\Enums\MarketingCampaignLinkStatus;
use App\Enums\MarketingCampaignTargetType;
use App\Models\MarketingCampaign;
use App\Models\MarketingCampaignLink;
use App\Services\Marketing\MarketingCampaignLinkSlugService;
use App\Services\Marketing\MarketingCampaignTargetPayloadValidator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Illuminate\Validation\Validator;

class StoreMarketingCampaignLinkRequest extends FormRequest
{
    use MarketingCampaignLinkLandingRules;

    public function authorize(): bool
    {
        if (! ($this->user()?->can('create', MarketingCampaignLink::class) ?? false)) {
            return false;
        }

        $campaign = $this->route('marketing_campaign');

        return ! ($campaign instanceof MarketingCampaign && $campaign->isArchived());
    }

    public function rules(): array
    {
        return [
            'marketing_campaign_id' => ['required', 'integer', 'exists:marketing_campaigns,id'],
            'name' => ['required', 'string', 'max:160'],
            'slug' => ['required', 'string', 'max:180'],
            'status' => ['required', Rule::enum(MarketingCampaignLinkStatus::class)],
            'target_type' => ['required', Rule::enum(MarketingCampaignTargetType::class)],
            'target_payload' => ['required', 'array'],
            'utm_source' => ['nullable', 'string', 'max:120'],
            'utm_medium' => ['nullable', 'string', 'max:120'],
            'utm_campaign' => ['nullable', 'string', 'max:160'],
            'utm_term' => ['nullable', 'string', 'max:160'],
            'utm_content' => ['nullable', 'string', 'max:160'],
            'starts_at' => ['nullable', 'date'],
            'ends_at' => ['nullable', 'date', 'after:starts_at'],
            ...$this->landingContentRules(),
        ];
    }

    public function prepareForValidation(): void
    {
        $campaign = $this->route('marketing_campaign');
        $merge = [];

        if ($campaign) {
            $merge['marketing_campaign_id'] = $campaign->id;
        }

        if ($this->has('slug')) {
            $merge['slug'] = app(MarketingCampaignLinkSlugService::class)->normalize((string) $this->input('slug'));
        }

        if ($merge !== []) {
            $this->merge($merge);
        }

        $this->prepareLandingPayload();
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator) {
            if ($validator->errors()->isNotEmpty()) {
                return;
            }

            try {
                app(MarketingCampaignLinkSlugService::class)->assertAvailable((string) $this->input('slug'));
                app(MarketingCampaignTargetPayloadValidator::class)->validate(
                    MarketingCampaignTargetType::from((string) $this->input('target_type')),
                    $this->input('target_payload'),
                    $this->integer('marketing_campaign_id'),
                );
            } catch (ValidationException $exception) {
                foreach ($exception->errors() as $field => $messages) {
                    foreach ($messages as $message) {
                        $validator->errors()->add($field, $message);
                    }
                }
            }
        });
    }
}
