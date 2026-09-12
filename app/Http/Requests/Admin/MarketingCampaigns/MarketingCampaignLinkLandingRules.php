<?php

namespace App\Http\Requests\Admin\MarketingCampaigns;

use App\Enums\MarketingCampaignHeroImageSource;
use App\Enums\MarketingCampaignLandingTemplate;
use Illuminate\Validation\Rule;

trait MarketingCampaignLinkLandingRules
{
    /**
     * @return array<string, mixed>
     */
    protected function landingContentRules(): array
    {
        return [
            'public_title' => ['nullable', 'string', 'max:180'],
            'public_subtitle' => ['nullable', 'string', 'max:255'],
            'public_description' => ['nullable', 'string', 'max:5000'],
            'eyebrow' => ['nullable', 'string', 'max:120'],
            'primary_cta_label' => ['nullable', 'string', 'max:80'],
            'secondary_cta_label' => ['nullable', 'string', 'max:80'],
            'show_prices' => ['sometimes', 'boolean'],
            'show_brand_logo' => ['sometimes', 'boolean'],
            'show_campaign_dates' => ['sometimes', 'boolean'],
            'landing_layout' => ['nullable', 'string', Rule::in(['default'])],
            'landing_template' => ['nullable', Rule::enum(MarketingCampaignLandingTemplate::class)],
            'editorial_eyebrow' => ['nullable', 'string', 'max:120', $this->plainTextRule()],
            'editorial_title' => ['nullable', 'string', 'max:180', $this->plainTextRule()],
            'editorial_body' => ['nullable', 'string', 'max:5000', $this->plainTextRule()],
            'editorial_items' => ['nullable', 'array', 'max:4'],
            'editorial_items.*.title' => ['nullable', 'string', 'max:120', $this->plainTextRule()],
            'editorial_items.*.description' => ['nullable', 'string', 'max:500', $this->plainTextRule()],
            'editorial_items.*.icon' => ['nullable', 'string', Rule::in(['shield', 'heart', 'lab', 'clock'])],

            'hero_image_source' => ['nullable', Rule::in(array_map(
                fn (MarketingCampaignHeroImageSource $case) => $case->value,
                MarketingCampaignHeroImageSource::cases(),
            ))],
            'hero_image_url' => [
                'nullable',
                'required_if:hero_image_source,'.MarketingCampaignHeroImageSource::External->value,
                'string',
                'max:1000',
            ],
            'hero_image_alt' => ['nullable', 'string', 'max:180'],
            'hero_image' => ['nullable', 'image', 'mimes:jpg,jpeg,png,webp', 'max:5120'],

            'primary_laboratory_test_ids' => ['nullable', 'array'],
            'primary_laboratory_test_ids.*' => ['integer'],
            'related_laboratory_test_ids' => ['nullable', 'array'],
            'related_laboratory_test_ids.*' => ['integer'],
            'related_category_ids' => ['nullable', 'array'],
            'related_category_ids.*' => ['integer'],

            'gallery_items' => ['nullable', 'array', 'max:6'],
            'gallery_items.*.kind' => ['required', 'string', Rule::in(['existing', 'upload', 'external'])],
            'gallery_items.*.id' => ['nullable', 'integer'],
            'gallery_items.*.upload_index' => ['nullable', 'integer', 'min:0'],
            'gallery_items.*.url' => ['nullable', 'string', 'max:1000'],
            'gallery_items.*.alt' => ['nullable', 'string', 'max:180'],
            'gallery_uploads' => ['nullable', 'array', 'max:6'],
            'gallery_uploads.*' => ['nullable', 'image', 'mimes:jpg,jpeg,png,webp', 'max:5120'],
        ];
    }

    protected function prepareLandingPayload(): void
    {
        $this->prepareLandingBooleans();

        if (is_string($this->input('gallery_items'))) {
            $decoded = json_decode($this->input('gallery_items'), true);
            $this->merge([
                'gallery_items' => is_array($decoded) ? $decoded : [],
            ]);
        }

        if (! is_array($this->input('gallery_items'))) {
            $this->merge(['gallery_items' => []]);
        }

        if (is_string($this->input('editorial_items'))) {
            $decoded = json_decode($this->input('editorial_items'), true);
            $this->merge([
                'editorial_items' => is_array($decoded) ? $decoded : [],
            ]);
        }

        if (! is_array($this->input('editorial_items'))) {
            $this->merge(['editorial_items' => []]);
        }
    }

    protected function prepareLandingBooleans(): void
    {
        $merge = [];

        foreach (['show_prices', 'show_brand_logo', 'show_campaign_dates'] as $field) {
            if ($this->has($field)) {
                $merge[$field] = $this->boolean($field);
            }
        }

        if (! $this->filled('landing_layout')) {
            $merge['landing_layout'] = 'default';
        }

        if (! $this->filled('landing_template')) {
            $merge['landing_template'] = MarketingCampaignLandingTemplate::Conversion->value;
        }

        if (! $this->filled('hero_image_source')) {
            $merge['hero_image_source'] = MarketingCampaignHeroImageSource::None->value;
        }

        foreach (['primary_laboratory_test_ids', 'related_laboratory_test_ids', 'related_category_ids'] as $field) {
            if (! is_array($this->input($field))) {
                $merge[$field] = [];
            }
        }

        if ($merge !== []) {
            $this->merge($merge);
        }
    }

    private function plainTextRule(): \Closure
    {
        return function (string $attribute, mixed $value, \Closure $fail): void {
            if (is_string($value) && preg_match('/<[^>]*>/', $value)) {
                $fail('El campo :attribute no acepta HTML.');
            }
        };
    }
}
