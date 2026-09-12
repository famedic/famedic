<?php

namespace App\Http\Requests\Admin\MarketingCampaigns;

use App\Enums\MarketingCampaignLinkStatus;
use App\Enums\MarketingCampaignTargetType;
use App\Services\Marketing\MarketingCampaignLinkSlugService;
use App\Services\Marketing\MarketingCampaignTargetPayloadValidator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Illuminate\Validation\Validator;

class UpdateMarketingCampaignLinkRequest extends FormRequest
{
    use MarketingCampaignLinkLandingRules;

    public function authorize(): bool
    {
        return $this->user()?->can('update', $this->link()) ?? false;
    }

    public function rules(): array
    {
        return [
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
        $link = $this->link();

        if ($campaign && $link && (int) $link->marketing_campaign_id !== (int) $campaign->id) {
            abort(404);
        }

        if ($this->has('slug')) {
            $this->merge([
                'slug' => app(MarketingCampaignLinkSlugService::class)->normalize((string) $this->input('slug')),
            ]);
        }

        $this->prepareLandingPayload();
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator) {
            if ($validator->errors()->isNotEmpty()) {
                return;
            }

            $link = $this->link();

            try {
                app(MarketingCampaignLinkSlugService::class)->assertAvailable(
                    (string) $this->input('slug'),
                    $link,
                );
                app(MarketingCampaignTargetPayloadValidator::class)->validate(
                    MarketingCampaignTargetType::from((string) $this->input('target_type')),
                    $this->input('target_payload'),
                    (int) $link->marketing_campaign_id,
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

    private function link(): mixed
    {
        return $this->route('marketing_campaign_link')
            ?? $this->route('link')
            ?? $this->route('marketingCampaignLink');
    }
}
