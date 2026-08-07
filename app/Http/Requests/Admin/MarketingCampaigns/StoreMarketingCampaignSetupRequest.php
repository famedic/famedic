<?php

namespace App\Http\Requests\Admin\MarketingCampaigns;

use App\Enums\MarketingCampaignLinkStatus;
use App\Enums\MarketingCampaignStatus;
use App\Enums\MarketingCampaignTargetType;
use App\Models\MarketingCampaign;
use App\Services\Marketing\MarketingCampaignLinkSlugService;
use App\Services\Marketing\MarketingCampaignTargetPayloadValidator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Illuminate\Validation\Validator;

class StoreMarketingCampaignSetupRequest extends FormRequest
{
    use MarketingCampaignLinkLandingRules;

    public function authorize(): bool
    {
        return $this->user()?->can('create', MarketingCampaign::class) ?? false;
    }

    public function rules(): array
    {
        return [
            'activate' => ['sometimes', 'boolean'],

            'campaign' => ['required', 'array'],
            'campaign.name' => ['required', 'string', 'max:160'],
            'campaign.description' => ['nullable', 'string', 'max:5000'],
            'campaign.status' => ['required', Rule::enum(MarketingCampaignStatus::class)],
            'campaign.starts_at' => ['nullable', 'date'],
            'campaign.ends_at' => ['nullable', 'date', 'after:campaign.starts_at'],

            'collection' => ['nullable', 'array'],
            'collection.name' => ['required_with:collection', 'string', 'max:160'],
            'collection.public_title' => ['nullable', 'string', 'max:180'],
            'collection.public_description' => ['nullable', 'string', 'max:5000'],
            'collection.laboratory_brand' => ['required_with:collection', 'string'],
            'collection.is_active' => ['sometimes', 'boolean'],
            'collection.laboratory_test_ids' => ['nullable', 'array'],
            'collection.laboratory_test_ids.*' => ['integer'],

            'link' => ['required', 'array'],
            'link.name' => ['required', 'string', 'max:160'],
            'link.slug' => ['required', 'string', 'max:180'],
            'link.status' => ['required', Rule::enum(MarketingCampaignLinkStatus::class)],
            'link.target_type' => ['required', Rule::enum(MarketingCampaignTargetType::class)],
            'link.target_payload' => ['nullable', 'array'],
            'link.utm_source' => ['nullable', 'string', 'max:120'],
            'link.utm_medium' => ['nullable', 'string', 'max:120'],
            'link.utm_campaign' => ['nullable', 'string', 'max:160'],
            'link.utm_term' => ['nullable', 'string', 'max:160'],
            'link.utm_content' => ['nullable', 'string', 'max:160'],
            'link.starts_at' => ['nullable', 'date'],
            'link.ends_at' => ['nullable', 'date', 'after:link.starts_at'],
            'link.public_title' => ['nullable', 'string', 'max:180'],
            'link.public_subtitle' => ['nullable', 'string', 'max:255'],
            'link.public_description' => ['nullable', 'string', 'max:5000'],
            'link.eyebrow' => ['nullable', 'string', 'max:120'],
            'link.primary_cta_label' => ['nullable', 'string', 'max:80'],
            'link.secondary_cta_label' => ['nullable', 'string', 'max:80'],
            'link.show_prices' => ['sometimes', 'boolean'],
            'link.show_brand_logo' => ['sometimes', 'boolean'],
            'link.show_campaign_dates' => ['sometimes', 'boolean'],
            'link.landing_layout' => ['nullable', 'string', Rule::in(['default'])],
            'link.hero_image_source' => ['nullable', Rule::in(array_map(
                fn ($case) => $case->value,
                \App\Enums\MarketingCampaignHeroImageSource::cases(),
            ))],
            'link.hero_image_url' => ['nullable', 'string', 'max:1000'],
            'link.hero_image_alt' => ['nullable', 'string', 'max:180'],
            'link.hero_image' => ['nullable', 'image', 'mimes:jpg,jpeg,png,webp', 'max:5120'],
            'link.primary_laboratory_test_ids' => ['nullable', 'array'],
            'link.primary_laboratory_test_ids.*' => ['integer'],
            'link.related_laboratory_test_ids' => ['nullable', 'array'],
            'link.related_laboratory_test_ids.*' => ['integer'],
            'link.related_category_ids' => ['nullable', 'array'],
            'link.related_category_ids.*' => ['integer'],
            'link.gallery_items' => ['nullable'],
            'link.gallery_uploads' => ['nullable', 'array', 'max:6'],
            'link.gallery_uploads.*' => ['nullable', 'image', 'mimes:jpg,jpeg,png,webp', 'max:5120'],
        ];
    }

    public function prepareForValidation(): void
    {
        $link = (array) $this->input('link', []);
        $campaign = (array) $this->input('campaign', []);

        if (isset($link['slug'])) {
            $link['slug'] = app(MarketingCampaignLinkSlugService::class)->normalize((string) $link['slug']);
        }

        foreach (['show_prices', 'show_brand_logo', 'show_campaign_dates'] as $field) {
            if ($this->has("link.{$field}")) {
                $link[$field] = $this->boolean("link.{$field}");
            }
        }

        if (! filled($link['landing_layout'] ?? null)) {
            $link['landing_layout'] = 'default';
        }

        if (! filled($link['hero_image_source'] ?? null)) {
            $link['hero_image_source'] = 'none';
        }

        foreach (['primary_laboratory_test_ids', 'related_laboratory_test_ids', 'related_category_ids'] as $field) {
            if (! is_array($link[$field] ?? null)) {
                $link[$field] = [];
            }
        }

        if (! is_array($link['target_payload'] ?? null)) {
            $link['target_payload'] = [];
        }

        if (is_string($link['gallery_items'] ?? null)) {
            $decoded = json_decode($link['gallery_items'], true);
            $link['gallery_items'] = is_array($decoded) ? $decoded : [];
        }

        if (($collection = $this->input('collection')) !== null && is_array($collection)) {
            if ($this->has('collection.is_active')) {
                $collection['is_active'] = $this->boolean('collection.is_active');
            }
            if (! is_array($collection['laboratory_test_ids'] ?? null)) {
                $collection['laboratory_test_ids'] = [];
            }
            $this->merge(['collection' => $collection]);
        }

        $this->merge([
            'link' => $link,
            'campaign' => $campaign,
            'activate' => $this->boolean('activate'),
        ]);
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator) {
            if ($validator->errors()->isNotEmpty()) {
                return;
            }

            $link = (array) $this->input('link', []);
            $targetType = MarketingCampaignTargetType::tryFrom((string) ($link['target_type'] ?? ''));

            try {
                app(MarketingCampaignLinkSlugService::class)->assertAvailable((string) ($link['slug'] ?? ''));

                if ($targetType && $targetType !== MarketingCampaignTargetType::Collection) {
                    app(MarketingCampaignTargetPayloadValidator::class)->validate(
                        $targetType,
                        $link['target_payload'] ?? [],
                        null,
                    );
                }
            } catch (ValidationException $exception) {
                foreach ($exception->errors() as $field => $messages) {
                    foreach ($messages as $message) {
                        $validator->errors()->add(
                            str_starts_with($field, 'target_') ? "link.{$field}" : $field,
                            $message,
                        );
                    }
                }
            }
        });
    }

    /**
     * @return array<string, mixed>
     */
    public function setupPayload(): array
    {
        $link = (array) $this->input('link', []);
        $collection = $this->input('collection');

        return [
            'activate' => $this->boolean('activate'),
            'campaign' => (array) $this->input('campaign', []),
            'collection' => is_array($collection) && $collection !== [] ? $collection : null,
            'link' => array_merge($link, [
                'gallery_items' => $link['gallery_items'] ?? [],
                'gallery_uploads' => $this->file('link.gallery_uploads', []),
            ]),
        ];
    }
}
